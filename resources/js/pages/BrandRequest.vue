<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { ExternalLink, LoaderCircle, Pause, Phone, Play, X } from 'lucide-vue-next'
import { formatDuration } from '@/lib/calls'
import { call as softphoneCall } from '@/composables/useSoftphone'
import { refreshBrandRequests } from '@/composables/useBrandRequests'
import { xsrfToken } from '@/lib/csrf'

/**
 * Une marque demandée (tous les appels où les agents ont tapé ce nom, ou une variante) :
 *   1. Appels : écoute de la conversation
 *   2. Autres noms : ajoutés à la marque (brands.aliases, un par ligne), cherchés par le softphone
 *   3. Marques existantes proches : « Associer à cette marque » ajoute les autres noms à la marque et classe la demande
 *   4. Créer la marque ; ou « Marquer la demande comme traitée », sans créer ni associer de marque
 * Chaque action porte sur tous les appels de la demande.
 */
const props = defineProps({ nameKey: String, types: Array })

const data = ref(null)
const error = ref(null)
// Autres noms à ajouter à la marque : même champ que la fiche marque (un par ligne)
// Autres noms : un champ pour la marque créée, un pour la marque existante associée (préremplis avec les noms tapés)
const aliasText = ref('')
const linkAliasText = ref('')
async function load() {
    try {
        const res = await fetch(`/data/brand-requests/${props.nameKey}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
        // Préremplis avec tous les noms tapés, un par ligne (ceux qui sont déjà le nom ou un autre nom de la marque sont ignorés à l'enregistrement)
        aliasText.value = data.value.names.map((n) => n.name).join('\n')
        linkAliasText.value = aliasText.value
        if (!form.name) form.name = titleCase(data.value.name ?? '')
    } catch (e) {
        error.value = e.message
    }
}
onMounted(load)

const titleCase = (s) => s.replace(/\S+/g, (w) => (w.length > 1 && w === w.toUpperCase() ? w : w.charAt(0).toUpperCase() + w.slice(1)))
const date = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-')
const mmss = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`)

// ─── Appels ───────────────────────────────────────────────────────────────────
const callColumns = [
    { key: 'at', label: 'Date' },
    { key: 'called', label: 'Numéro appelé' },
    { key: 'caller', label: 'Appelant' },
    { key: 'agent', label: 'Agent' },
    { key: 'name', label: 'Nom tapé' },
    { key: 'record', label: 'Conversation' },
]

// ─── Lecteur : le même que la page Appels (barre en bas : position, vitesse) ──────
const audio = typeof Audio !== 'undefined' ? new Audio() : null
let pendingStart = null
const player = reactive({ call: null, playing: false, current: 0, duration: 0, rate: 1, loading: false, error: null })
const RATES = [1, 1.25, 1.5, 2]
if (audio) {
    audio.preload = 'auto'
    audio.addEventListener('timeupdate', () => (player.current = audio.currentTime))
    audio.addEventListener('loadedmetadata', () => {
        player.duration = audio.duration
        // Position de départ appliquée une fois la durée connue (avant, le navigateur l'ignore)
        if (pendingStart !== null) audio.currentTime = pendingStart
        pendingStart = null
    })
    audio.addEventListener('play', () => (player.playing = true))
    audio.addEventListener('pause', () => (player.playing = false))
    audio.addEventListener('waiting', () => (player.loading = true))
    audio.addEventListener('playing', () => (player.loading = false))
    audio.addEventListener('error', () => {
        player.loading = false
        player.error = "Impossible de lire l'enregistrement"
    })
}
function play(row) {
    if (!audio || !row.record) return
    if (player.call?.id === row.id) {
        audio.paused ? audio.play() : audio.pause()
        return
    }
    Object.assign(player, { call: row, current: 0, duration: 0, error: null, loading: true })
    audio.src = row.record
    audio.playbackRate = player.rate
    // Lecture juste avant la réplique où le client nomme la marque (audio_at), depuis le début sans repère
    pendingStart = markStart(row)
    audio.play().catch(() => {})
}
const markStart = (row) => (row.audio_at == null ? 0 : Math.max(0, row.audio_at - 2))
// Clic sur une réplique : lecture à sa seconde (lecteur ouvert sur cet appel au besoin)
function playAt(row, seconds) {
    if (!audio || !row.record) return
    if (player.call?.id !== row.id) {
        play(row)
        pendingStart = seconds
        return
    }
    audio.currentTime = seconds
    audio.play().catch(() => {})
}
// Transcription affichée : celle de l'appel ouvert dans le lecteur
const transcriptCall = computed(() => player.call ?? null)
// Réplique en cours de lecture : la dernière commencée
const currentLine = computed(() => {
    const lines = transcriptCall.value?.transcript ?? []
    let index = -1
    lines.forEach((l, i) => l.s <= player.current + 0.2 && (index = i))
    return index
})
const isMarkLine = (row, line) => row.audio_at != null && Math.abs(line.s - row.audio_at) < 0.05
// À l'ouverture, la réplique du nom est amenée dans la zone visible de la transcription
const transcriptBox = ref(null)
watch(
    () => player.call?.id,
    async () => {
        await nextTick()
        transcriptBox.value?.querySelector('[data-mark]')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    },
)
function skip(seconds) {
    if (audio) audio.currentTime = Math.min(Math.max(0, audio.currentTime + seconds), player.duration || audio.currentTime + seconds)
}
function backToMark() {
    if (!audio || player.call?.audio_at == null) return
    audio.currentTime = markStart(player.call)
    audio.play().catch(() => {})
}
// Position du repère sur la barre (en %), une fois la durée connue
const markPct = computed(() => (player.call?.audio_at != null && player.duration ? Math.min(100, (player.call.audio_at / player.duration) * 100) : null))
function seek(e) {
    if (audio && player.duration) audio.currentTime = Number(e.target.value)
}
function cycleRate() {
    player.rate = RATES[(RATES.indexOf(player.rate) + 1) % RATES.length]
    if (audio) audio.playbackRate = player.rate
}
function closePlayer() {
    audio?.pause()
    player.call = null
}
const isPlaying = (row) => player.call?.id === row.id && player.playing
onUnmounted(() => audio?.pause())

// ─── Noms tapés → autres noms de la marque ────────────────────────────────────
const lines = (text) => text.split('\n').map((x) => x.trim()).filter(Boolean)
const aliases = computed(() => lines(aliasText.value))
const ids = computed(() => data.value?.calls.map((c) => c.id) ?? [])

// ─── Actions ──────────────────────────────────────────────────────────────────
const busy = ref(false)
const actionError = ref(null)
async function post(url, body) {
    busy.value = true
    actionError.value = null
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify(body),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.errors ? Object.values(json.errors).flat()[0] : json.message || `Erreur HTTP ${res.status}`)
        return json
    } catch (e) {
        actionError.value = e.message
        return null
    } finally {
        busy.value = false
    }
}
// Après une action : la suite des appels de cette demande, ou la demande suivante, ou la liste
async function after() {
    refreshBrandRequests(true)
    await load()
    if (data.value && !data.value.calls.length) router.visit(data.value.next ? `/brands/requests/${data.value.next}` : '/brands/requests')
}
async function link(brandId) {
    if (await post('/data/brand-requests/link', { ids: ids.value, brand_id: brandId, aliases: lines(linkAliasText.value) })) after()
}
const otherBrand = ref(null)
// Action principale (bouton noir) = la réponse la plus probable : associer quand une marque a exactement ce nom
// (sinon on créerait un doublon), créer sinon. Une seule action principale sur la page.
const identicalId = computed(() => (data.value?.suggestions?.[0]?.rank === 1 ? data.value.suggestions[0].id : null))

// Création
const COUNTRY = { 33: 'FR', 32: 'BE', 49: 'DE' }
const form = reactive({ name: '', phone: '', country: 'FR', type: 'service', canTransfer: true, note: '', scenario: '' })
// Numéro saisi librement (01 23…, 0033…, +33…) → +33…
const phonenumber = computed(() => {
    const d = form.phone.replace(/[^\d+]/g, '')
    if (!d) return ''
    if (d.startsWith('+')) return d
    if (d.startsWith('00')) return `+${d.slice(2)}`
    if (d.startsWith('0')) return `+33${d.slice(1)}`
    return d
})
const phoneValid = computed(() => !phonenumber.value || /^\+(33|32|49)\d{8,11}$/.test(phonenumber.value))
const premium = computed(() => /^\+3389/.test(phonenumber.value))
// Pays déduit du numéro (modifiable)
watch(phonenumber, (n) => {
    const code = n.match(/^\+(33|32|49)/)?.[1]
    if (code) form.country = COUNTRY[code]
})
// Une autre marque a déjà ce numéro (les appels sont reliés aux marques par leur numéro)
const sameNumber = ref([])
let phoneTimer = null
watch(phonenumber, (n) => {
    clearTimeout(phoneTimer)
    sameNumber.value = []
    if (!n || !phoneValid.value) return
    phoneTimer = setTimeout(async () => {
        const res = await fetch(`/data/brand-requests/phone-check?phonenumber=${encodeURIComponent(n)}`, { headers: { Accept: 'application/json' } }).catch(() => null)
        if (res?.ok) sameNumber.value = (await res.json()).brands
    }, 400)
})
const googleUrl = computed(() => `https://www.google.com/search?q=${encodeURIComponent(`${form.name || data.value?.name || ''} numéro de téléphone service client contact`)}`)
async function create() {
    const json = await post('/data/brand-requests/create', {
        ids: ids.value,
        name: form.name,
        phonenumber: phonenumber.value || null,
        country: form.country,
        type: form.type,
        can_transfer: !!phonenumber.value && form.canTransfer,
        note: form.note || null,
        scenario: form.scenario || null,
        aliases: aliases.value,
    })
    if (json) {
        Object.assign(form, { name: '', phone: '', note: '' })
        after()
    }
}

// Marquer comme traitée, sans créer ni associer de marque
async function ignore() {
    if (await post('/data/brand-requests/ignore', { ids: ids.value })) after()
}
</script>

<template>
    <Head :title="data?.name ? `${data.name} · Marques demandées` : 'Marque demandée'" />

    <AppLayout width="form">
        <div class="space-y-6 p-6" :class="player.call && 'pb-28'">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées', href: '/brands/requests' }, { title: data?.name ?? '…' }]">
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── Appels ─── -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data?.calls.length ?? '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="callColumns" :rows="data?.calls ?? []" :loading="!data && !error" empty-message="Aucun appel à traiter">
                        <template #at="{ value }"><span class="text-sm whitespace-nowrap text-gray-700">{{ date(value) }}</span></template>
                        <template #called="{ value }"><span class="text-sm text-gray-700">{{ value || '-' }}</span></template>
                        <template #caller="{ value }"><span class="font-mono text-sm text-gray-600">{{ value || '-' }}</span></template>
                        <template #agent="{ value }"><span class="text-sm text-gray-700">{{ value || '-' }}</span></template>
                        <template #name="{ value }"><span class="text-sm text-gray-900">{{ value }}</span></template>
                        <template #record="{ row }">
                            <button
                                v-if="row.record"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                :class="isPlaying(row) ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                @click="play(row)"
                            >
                                <Pause v-if="isPlaying(row)" class="size-3.5" />
                                <Play v-else class="size-3.5" />
                                {{ mmss(row.duration) }}
                            </button>
                            <span v-else class="text-sm text-gray-400">Pas d'enregistrement</span>
                        </template>
                    </DataTable>
                </div>
            </section>

            <!-- ─── Transcription de l'appel ouvert dans le lecteur ─── -->
            <section v-if="transcriptCall">
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Transcription <span class="ml-1 font-normal text-gray-500">{{ date(transcriptCall.at) }} · {{ transcriptCall.agent || '—' }} · nom tapé « {{ transcriptCall.name }} »</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <p v-if="transcriptCall.transcript === null" class="px-4 py-3 text-sm text-gray-500">Transcription pas encore disponible</p>
                    <p v-else-if="!transcriptCall.transcript.length" class="px-4 py-3 text-sm text-gray-500">Pas de transcription</p>
                    <div v-else ref="transcriptBox" class="max-h-96 divide-y divide-gray-50 overflow-y-auto">
                        <button
                            v-for="(line, i) in transcriptCall.transcript"
                            :key="i"
                            type="button"
                            class="grid w-full grid-cols-[3.5rem_4rem_1fr] gap-3 px-4 py-2 text-left text-sm hover:bg-gray-50"
                            :class="[isMarkLine(transcriptCall, line) ? 'bg-amber-50 hover:bg-amber-100/70' : i === currentLine && player.playing ? 'bg-gray-50' : '']"
                            :data-mark="isMarkLine(transcriptCall, line) ? '' : undefined"
                            @click="playAt(transcriptCall, line.s)"
                        >
                            <span class="tabular-nums text-gray-400">{{ formatDuration(line.s) }}</span>
                            <span :class="line.who === 'client' ? 'font-medium text-gray-900' : 'text-gray-500'">{{ line.who === 'client' ? 'Client' : 'Agent' }}</span>
                            <span :class="line.who === 'client' ? 'text-gray-900' : 'text-gray-600'">{{ line.t }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <template v-if="data?.calls.length">
                <p v-if="actionError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ actionError }}</p>

                <!-- Marques proches et création : la réponse la plus probable en premier (proches s'il y en a, sinon création).
                     Les cartes et les champs sont ceux de la fiche marque (BrandForm.vue). -->
                <div class="flex flex-col gap-6">
                    <!-- ─── Marques existantes proches ─── -->
                    <Card :class="data.suggestions.length ? 'order-1' : 'order-2'">
                        <CardHeader>
                            <CardTitle>Marques existantes proches</CardTitle>
                            <p class="text-sm text-gray-500">« Associer à cette marque » ajoute les autres noms ci-dessous à la marque choisie, et la demande passe dans « Traitées » comme marque existante.</p>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div class="space-y-2 pb-2">
                                <Label for="br-link-aliases">Autres noms ajoutés à la marque associée</Label>
                                <Textarea id="br-link-aliases" v-model="linkAliasText" rows="2" placeholder="Un nom par ligne" />
                                <p class="text-sm text-gray-500">Préremplis avec les noms tapés. Ceux que la marque a déjà ne sont pas ajoutés en double.</p>
                            </div>
                            <div v-for="b in data.suggestions" :key="b.id" class="flex items-center gap-4 text-sm">
                                <Link :href="`/brands/${b.id}/edit`" class="font-medium text-gray-900 hover:underline">{{ b.name }}</Link>
                                <span class="tabular-nums text-gray-600">{{ b.phonenumber || 'sans numéro' }}</span>
                                <span class="text-gray-500">{{ b.type || '-' }}</span>
                                <span class="text-gray-500">{{ b.reason }}<template v-if="b.via"> (autre nom « {{ b.via }} »)</template></span>
                                <Button type="button" size="sm" :variant="b.id === identicalId ? 'default' : 'outline'" class="ml-auto" :disabled="busy" @click="link(b.id)">Associer à cette marque</Button>
                            </div>
                            <p v-if="!data.suggestions.length" class="text-sm text-gray-500">Aucune marque proche</p>
                            <div class="flex items-center gap-4 text-sm">
                                <span class="text-gray-700">Une autre marque</span>
                                <div class="w-72"><FilterSelect v-model="otherBrand" :options="data.brands" placeholder="Chercher une marque" searchable /></div>
                                <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="busy || !otherBrand" @click="link(otherBrand)">Associer à cette marque</Button>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- ─── Créer la marque : mêmes cartes et mêmes champs que « Nouvelle marque » ─── -->
                    <form class="space-y-6" :class="data.suggestions.length ? 'order-2' : 'order-1'" @submit.prevent="create">
                        <Card>
                            <CardHeader>
                                <CardTitle>Créer la marque</CardTitle>
                                <p class="text-sm text-gray-500">Activée tout de suite et visible des agents.</p>
                            </CardHeader>
                            <CardContent class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="br-name">Nom *</Label>
                                    <Input id="br-name" v-model="form.name" required />
                                    <a :href="googleUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-gray-500 underline hover:text-gray-900">Chercher le service client sur Google <ExternalLink class="h-3.5 w-3.5" /></a>
                                </div>
                                <div class="space-y-2 md:col-span-2">
                                    <Label for="br-aliases">Autres noms</Label>
                                    <Textarea id="br-aliases" v-model="aliasText" rows="2" placeholder="Un nom par ligne" />
                                    <p class="text-sm text-gray-500">Noms sous lesquels les agents cherchent aussi la marque dans le softphone. Préremplis avec les noms tapés.</p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="br-phone">Numéro de transfert</Label>
                                    <div class="flex items-center gap-2">
                                        <Input id="br-phone" v-model="form.phone" placeholder="+33123456789" class="tabular-nums" />
                                        <Button v-if="phonenumber && phoneValid" type="button" size="sm" variant="outline" @click="softphoneCall(phonenumber)"><Phone class="mr-2 h-4 w-4" />Appeler</Button>
                                    </div>
                                    <p v-if="phonenumber && phoneValid && phonenumber !== form.phone.trim()" class="text-sm tabular-nums text-gray-500">{{ phonenumber }}</p>
                                    <p v-if="!phoneValid" class="text-sm text-red-600">Numéro français, belge ou allemand attendu.</p>
                                    <p v-if="premium" class="text-sm text-amber-700">Numéro surtaxé (08 9).</p>
                                    <p v-if="sameNumber.length" class="text-sm text-red-600">
                                        Déjà le numéro de
                                        <template v-for="(b, i) in sameNumber" :key="b.id">{{ i ? ', ' : '' }}<Link :href="`/brands/${b.id}/edit`" class="underline">{{ b.name }}</Link></template>
                                        : les appels des deux marques seraient mélangés.
                                    </p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="br-type">Type</Label>
                                    <Input id="br-type" v-model="form.type" list="br-types" placeholder="service, bank, ecommerce…" />
                                    <datalist id="br-types">
                                        <option v-for="t in types" :key="t" :value="t" />
                                    </datalist>
                                </div>
                                <div class="space-y-2">
                                    <Label for="br-country">Pays</Label>
                                    <select id="br-country" v-model="form.country" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm">
                                        <option value="FR">France</option>
                                        <option value="BE">Belgique</option>
                                        <option value="DE">Allemagne</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2 md:col-span-2">
                                    <Switch id="br-transfer" :checked="form.canTransfer && !!phonenumber" :disabled="!phonenumber" @update:checked="form.canTransfer = $event" />
                                    <Label for="br-transfer">Transfert possible</Label>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>Pour l'agent</CardTitle></CardHeader>
                            <CardContent class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="br-note">Note</Label>
                                    <Textarea id="br-note" v-model="form.note" rows="3" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="br-scenario">Scénario</Label>
                                    <Textarea id="br-scenario" v-model="form.scenario" rows="6" />
                                </div>
                            </CardContent>
                        </Card>

                        <div class="flex justify-end">
                            <Button type="submit" :variant="identicalId ? 'outline' : 'default'" :disabled="busy || !form.name || !phoneValid">Créer la marque</Button>
                        </div>
                    </form>
                </div>

                <!-- ─── Sans créer ni associer de marque ─── -->
                <div class="flex justify-end">
                    <Button type="button" variant="outline" :disabled="busy" @click="ignore">Marquer la demande comme traitée</Button>
                </div>
            </template>
        </div>

        <!-- Lecteur (même barre que la page Appels) -->
        <div v-if="player.call" class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur md:left-64">
            <div class="flex items-center gap-3 py-2.5 pr-20 pl-4">
                <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-900 text-white hover:bg-gray-800" :title="player.playing ? 'Pause' : 'Lecture'" @click="play(player.call)">
                    <LoaderCircle v-if="player.loading" class="size-4 animate-spin" />
                    <Pause v-else-if="player.playing" class="size-4" />
                    <Play v-else class="ml-0.5 size-4" />
                </button>
                <div class="w-56 min-w-0 shrink-0">
                    <div class="truncate text-sm font-medium text-gray-900">« {{ player.call.name }} » · {{ player.call.agent || '—' }}</div>
                    <div class="truncate text-xs text-gray-500">{{ date(player.call.at) }} · {{ player.call.caller || '—' }}</div>
                </div>
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-gray-500">{{ formatDuration(player.current) }}</span>
                <!-- Barre de lecture, avec le repère du moment où le client donne le nom -->
                <div class="relative flex min-w-0 flex-1 items-center">
                    <input type="range" min="0" :max="player.duration || 0" step="0.1" :value="player.current" class="w-full accent-gray-900" aria-label="Position" @input="seek" />
                    <span v-if="markPct !== null" class="pointer-events-none absolute -top-3 h-6 w-0.5 -translate-x-1/2 rounded bg-amber-500" :style="{ left: `${markPct}%` }" title="Le client nomme la marque" />
                </div>
                <span class="w-10 shrink-0 text-xs tabular-nums text-gray-500">{{ formatDuration(player.duration) }}</span>
                <p v-if="player.error" class="shrink-0 text-xs text-red-600">{{ player.error }}</p>
                <div class="flex shrink-0 items-center gap-1.5">
                    <button type="button" class="h-7 rounded-md border border-gray-200 px-2 text-xs tabular-nums text-gray-700 hover:bg-gray-50" @click="skip(-10)">−10 s</button>
                    <button type="button" class="h-7 rounded-md border border-gray-200 px-2 text-xs tabular-nums text-gray-700 hover:bg-gray-50" @click="skip(10)">+10 s</button>
                    <button v-if="player.call.audio_at != null" type="button" class="h-7 rounded-md border border-gray-200 px-2 text-xs text-gray-700 hover:bg-gray-50" @click="backToMark">Revenir au nom</button>
                    <button type="button" class="h-7 w-12 rounded-md border border-gray-200 text-xs tabular-nums text-gray-700 hover:bg-gray-50" title="Vitesse de lecture" @click="cycleRate">{{ String(player.rate).replace('.', ',') }}×</button>
                    <button type="button" class="flex size-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Fermer le lecteur" @click="closePlayer"><X class="size-4" /></button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
