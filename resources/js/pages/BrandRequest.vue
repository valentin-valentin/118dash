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
import { ArrowRight, ExternalLink, LoaderCircle, Pause, Phone, Play, X } from 'lucide-vue-next'
import { formatDuration, formatPhone, toInternational } from '@/lib/calls'
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
// Autres noms : le même champ que la fiche marque (un par ligne), un pour la marque créée et un pour la marque existante
// associée, chacun prérempli avec tous les noms proposés et modifiable de son côté
const aliasesText = ref('')
const linkAliasesText = ref('')
const nameKey = (n) => String(n).normalize('NFD').replace(/[^a-z0-9]/gi, '').toLowerCase()
async function load() {
    try {
        const res = await fetch(`/data/brand-requests/${props.nameKey}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
        linkAliasesText.value = data.value.aliasOptions.map((o) => o.name).join('\n')
        // Nom de la marque : celui compris dans l'appel s'il existe, sinon le nom tapé
        if (!form.name) form.name = data.value.heard.main ?? titleCase(data.value.name ?? '')
        // Numéro de transfert : le premier trouvé par ChatGPT (celui qui correspond le mieux à ce que le client a dit)
        if (!form.phone && data.value.numberSearch?.found) form.phone = data.value.numberSearch.suggestions?.[0]?.number ?? ''
        // Autres noms de la marque créée : tous les noms proposés, sauf le nom de la marque lui-même
        aliasesText.value = data.value.aliasOptions.filter((o) => nameKey(o.name) !== nameKey(form.name)).map((o) => o.name).join('\n')
    } catch (e) {
        error.value = e.message
    }
}
onMounted(load)

const titleCase = (s) => String(s).replace(/\S+/g, (w) => (w.length > 1 && w === w.toUpperCase() ? w : w.charAt(0).toUpperCase() + w.slice(1)))
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
    selectedId.value = row.id
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
// Transcription affichée : celle de l'appel sélectionné dans le tableau (le premier à l'ouverture), sans dépendre de la lecture
const selectedId = ref(null)
const transcriptCall = computed(() => {
    const calls = data.value?.calls ?? []
    return calls.find((c) => c.id === selectedId.value) ?? calls[0] ?? null
})
const selectedRowClass = (row) => (row.id === transcriptCall.value?.id ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')
// Réplique en cours de lecture : la dernière commencée (seulement si l'appel lu est celui affiché)
const currentLine = computed(() => {
    if (player.call?.id !== transcriptCall.value?.id) return -1
    const lines = transcriptCall.value?.transcript ?? []
    let index = -1
    lines.forEach((l, i) => l.s <= player.current + 0.2 && (index = i))
    return index
})
const isMarkLine = (row, line) => row.audio_at != null && Math.abs(line.s - row.audio_at) < 0.05
// À l'affichage d'une transcription, la réplique du nom est centrée dans sa zone, sans faire défiler la page
const transcriptBox = ref(null)
watch(
    () => transcriptCall.value?.id,
    async () => {
        await nextTick()
        const box = transcriptBox.value
        const mark = box?.querySelector('[data-mark]')
        box && (box.scrollTop = mark ? mark.offsetTop - (box.clientHeight - mark.offsetHeight) / 2 : 0)
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
const lines = (text) => text.split('\n').map((n) => n.trim()).filter(Boolean)
const aliases = computed(() => lines(aliasesText.value))
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
// Après une action : la demande suivante de la liste « À traiter » (même ordre), ou la liste
// Sans autre demande en attente, chaque action n'a qu'un bouton : « … et retourner à la liste »
const hasNext = computed(() => !!data.value?.next)
function after(goNext) {
    refreshBrandRequests(true)
    router.visit(goNext && data.value?.next ? `/brands/requests/${data.value.next}` : '/brands/requests')
}
async function link(goNext) {
    if (!chosenId.value) return
    if (await post('/data/brand-requests/link', { ids: ids.value, brand_id: chosenId.value, aliases: lines(linkAliasesText.value) })) after(goNext)
}
// Marque à associer : une ligne cliquée (sélectionnée comme une ligne de tableau), ou celle cherchée dans « Une autre marque »
const chosenId = ref(null)
const otherBrand = ref(null)
watch(otherBrand, (id) => id && (chosenId.value = id))
// Action principale (bouton noir) = la réponse la plus probable : associer quand une marque a exactement ce nom
// (sinon on créerait un doublon), créer sinon. Une seule action principale sur la page.
// Associer est l'action principale quand la transcription correspond à une marque, ou qu'une marque a exactement le nom tapé
const identicalId = computed(() => data.value?.matches?.[0]?.id ?? (data.value?.suggestions?.[0]?.rank === 1 ? data.value.suggestions[0].id : null))
// Marques proches, hors celles déjà mises en avant par la transcription
const otherSuggestions = computed(() => (data.value?.suggestions ?? []).filter((b) => !data.value.matches.some((m) => m.id === b.id)))
const hasExisting = computed(() => !!(data.value?.matches?.length || data.value?.suggestions?.length))
// Sélection par défaut : la marque qui a exactement ce nom (ou que la transcription donne)
watch(identicalId, (id) => (chosenId.value = id ?? null), { immediate: true })
const rowClass = (id) => (chosenId.value === id ? 'shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')

// Création
const COUNTRY = { 33: 'FR', 32: 'BE', 49: 'DE' }
const form = reactive({ name: '', phone: '', country: 'FR', type: 'service', canTransfer: true, note: '', scenario: '' })
// Numéro saisi librement (01 23…, 0033…, +33…) → +33…
const phonenumber = computed(() => toInternational(form.phone))
// Numéro collé ou saisi : remis au format international dans le champ, dès le collage et en quittant le champ
const formatPhoneField = () => setTimeout(() => (form.phone = toInternational(form.phone)))
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
async function create(goNext) {
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
    if (json) after(goNext)
}

// Marquer comme traitée, sans créer ni associer de marque
async function ignore(goNext) {
    if (await post('/data/brand-requests/ignore', { ids: ids.value })) after(goNext)
}
</script>

<template>
    <Head :title="data?.name ? `${data.name} · Marques demandées` : 'Marque demandée'" />

    <AppLayout width="form">
        <div class="space-y-6 p-6" :class="player.call && 'pb-28'">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées', href: '/brands/requests' }, { title: data?.name ?? '…' }]">
                <!-- Navigation : ouvre la demande suivante de la liste sans rien enregistrer sur celle-ci -->
                <template v-if="hasNext" #actions>
                    <Button variant="outline" as-child>
                        <Link :href="`/brands/requests/${data.next}`">Passer à la demande suivante <ArrowRight class="ml-2 h-4 w-4" /></Link>
                    </Button>
                </template>
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── Nom tapé et nom compris dans l'appel (même barre que la page Serveur SIP) ─── -->
            <div v-if="data" class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border border-gray-100 bg-white px-4 py-2.5 text-sm">
                <span><span class="text-gray-500">Nom tapé</span> <span class="ml-1 font-medium text-gray-900">{{ data.names.map((n) => n.name).join(', ') }}</span></span>
                <span v-if="data.heard.state === 'names'">
                    <span class="text-gray-500">Compris dans l'appel</span> <span class="ml-1 font-medium text-gray-900">{{ data.heard.main }}</span>
                    <span v-if="data.heard.others.length" class="ml-2 text-xs text-gray-500">{{ data.heard.others.join(', ') }}</span>
                </span>
                <span v-else-if="data.heard.state === 'never'" class="text-gray-500">Marque jamais nommée dans l'appel</span>
            </div>

            <!-- ─── Appels ─── -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data?.calls.length ?? '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="callColumns" :rows="data?.calls ?? []" :loading="!data && !error" empty-message="Aucun appel à traiter" :row-class="selectedRowClass" :row-click="(row) => (selectedId = row.id)">
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

            <!-- ─── Transcription de l'appel sélectionné ─── -->
            <section v-if="transcriptCall">
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Transcription <span class="ml-1 font-normal text-gray-500">{{ date(transcriptCall.at) }} · {{ transcriptCall.agent || '—' }} · nom tapé « {{ transcriptCall.name }} »</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <p v-if="transcriptCall.transcript === null" class="px-4 py-3 text-sm text-gray-500">Transcription pas encore disponible</p>
                    <p v-else-if="!transcriptCall.transcript.length" class="px-4 py-3 text-sm text-gray-500">Pas de transcription</p>
                    <div v-else ref="transcriptBox" class="relative max-h-96 divide-y divide-gray-50 overflow-y-auto">
                        <button
                            v-for="(line, i) in transcriptCall.transcript"
                            :key="i"
                            type="button"
                            class="grid w-full grid-cols-[3.5rem_4rem_1fr] gap-3 px-4 py-2 text-left text-sm"
                            :class="isMarkLine(transcriptCall, line) ? 'bg-amber-50 hover:bg-amber-100' : i === currentLine && player.playing ? 'bg-gray-50 hover:bg-gray-100' : 'hover:bg-gray-50'"
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
                    <div class="space-y-6" :class="hasExisting ? 'order-1' : 'order-2'">
                    <Card>
                        <CardHeader>
                            <CardTitle>Marques existantes proches</CardTitle>
                            <p class="text-sm text-gray-500">Choisir la marque. « Associer à cette marque » lui ajoute les autres noms ci-dessous, et la demande passe dans « Traitées » comme marque existante.</p>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div class="space-y-2 pb-2">
                                <Label for="br-link-aliases">Autres noms ajoutés à la marque associée</Label>
                                <Textarea id="br-link-aliases" v-model="linkAliasesText" :rows="Math.max(2, linkAliasesText.split('\n').length)" placeholder="Un nom par ligne" />
                                <p class="text-sm text-gray-500">Ceux que la marque a déjà ne sont pas ajoutés en double.</p>
                            </div>
                            <!-- La transcription donne exactement le nom (ou un autre nom) d'une marque existante : en premier -->
                            <!-- Une ligne par marque, cliquable ; la marque choisie a le liseré des lignes sélectionnées -->
                            <div
                                v-for="b in data.matches"
                                :key="`m-${b.id}`"
                                class="flex cursor-pointer items-center gap-4 rounded-md border border-amber-200 px-3 py-2 text-sm"
                                :class="[chosenId === b.id ? 'bg-amber-100' : 'bg-amber-50 hover:bg-amber-100', rowClass(b.id)]"
                                @click="chosenId = b.id"
                            >
                                <span class="text-amber-800">La transcription correspond à <Link :href="`/brands/${b.id}/edit`" class="font-medium underline" @click.stop>{{ b.name }}</Link></span>
                                <span class="tabular-nums text-amber-700">{{ b.phonenumber || 'sans numéro' }}</span>
                            </div>
                            <div
                                v-for="b in otherSuggestions"
                                :key="b.id"
                                class="flex cursor-pointer items-center gap-4 rounded-md px-3 py-2 text-sm"
                                :class="[chosenId === b.id ? 'bg-gray-50' : 'hover:bg-gray-50', rowClass(b.id)]"
                                @click="chosenId = b.id"
                            >
                                <Link :href="`/brands/${b.id}/edit`" class="font-medium text-gray-900 hover:underline" @click.stop>{{ b.name }}</Link>
                                <span class="tabular-nums text-gray-600">{{ b.phonenumber || 'sans numéro' }}</span>
                                <span class="text-gray-500">{{ b.type || '-' }}</span>
                                <span class="text-gray-500">{{ b.reason }}<template v-if="b.via"> (autre nom « {{ b.via }} »)</template> · {{ b.source }}</span>
                            </div>
                            <p v-if="!hasExisting" class="px-3 text-sm text-gray-500">Aucune marque proche</p>
                            <div
                                class="flex items-center gap-4 rounded-md px-3 py-2 text-sm"
                                :class="[otherBrand && chosenId === otherBrand ? 'bg-gray-50' : '', otherBrand && 'cursor-pointer', otherBrand && rowClass(otherBrand)]"
                                @click="otherBrand && (chosenId = otherBrand)"
                            >
                                <span class="text-gray-700">Une autre marque</span>
                                <div class="w-72" @click.stop><FilterSelect v-model="otherBrand" :options="data.brands" placeholder="Chercher une marque" searchable /></div>
                            </div>
                        </CardContent>
                    </Card>
                    <div class="flex justify-end gap-4">
                        <Button v-if="hasNext" type="button" variant="outline" :disabled="busy || !chosenId" @click="link(false)">Associer à cette marque et retourner à la liste</Button>
                        <Button type="button" :variant="identicalId ? 'default' : 'outline'" :disabled="busy || !chosenId" @click="link(hasNext)">
                            {{ hasNext ? 'Associer à cette marque et passer à la suivante' : 'Associer à cette marque et retourner à la liste' }}
                        </Button>
                    </div>
                    </div>

                    <!-- ─── Créer la marque : mêmes cartes et mêmes champs que « Nouvelle marque » ─── -->
                    <form class="space-y-6" :class="hasExisting ? 'order-2' : 'order-1'" @submit.prevent="create(hasNext)">
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
                                    <Textarea id="br-aliases" v-model="aliasesText" :rows="Math.max(2, aliasesText.split('\n').length)" placeholder="Un nom par ligne" />
                                    <p class="text-sm text-gray-500">Noms sous lesquels les agents cherchent aussi la marque dans le softphone.</p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="br-phone">Numéro de transfert</Label>
                                    <div class="flex items-center gap-2">
                                        <Input id="br-phone" v-model="form.phone" placeholder="+33123456789" class="tabular-nums" @paste="formatPhoneField" @blur="formatPhoneField" />
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
                                <!-- Numéros trouvés par ChatGPT (voxnode118), sous le champ qu'ils remplissent ; le premier est déjà dans le champ -->
                                <div v-if="data.numberSearch" class="space-y-2 md:col-span-2">
                                    <p class="text-sm font-medium text-gray-900">
                                        Trouvé par ChatGPT
                                        <span v-if="data.numberSearch.searched_name" class="ml-1 font-normal text-gray-500">recherche « {{ data.numberSearch.searched_name }} »</span>
                                    </p>
                                    <p v-if="data.numberSearch.status === 'pending'" class="text-sm text-gray-500">Recherche en cours</p>
                                    <p v-else-if="data.numberSearch.status === 'failed'" class="text-sm text-gray-500">Recherche impossible</p>
                                    <template v-else-if="!data.numberSearch.found">
                                        <p class="text-sm text-gray-900">ChatGPT n'a pas trouvé de numéro +33</p>
                                        <p v-if="data.numberSearch.summary" class="text-sm text-gray-500">{{ data.numberSearch.summary }}</p>
                                    </template>
                                    <div v-else class="divide-y divide-gray-50 rounded-lg border border-gray-100">
                                        <div v-for="n in data.numberSearch.suggestions" :key="n.number" class="flex items-start gap-4 px-4 py-3 text-sm">
                                            <div class="min-w-0 flex-1 space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-medium tabular-nums text-gray-900">{{ formatPhone(n.number) }}</span>
                                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="n.official_source ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'">
                                                        {{ n.official_source ? 'Site officiel' : 'Autre source' }}
                                                    </span>
                                                    <span v-if="n.premium" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Surtaxé</span>
                                                    <span class="text-gray-500">Confiance {{ n.confidence }}</span>
                                                </div>
                                                <p class="text-gray-900">{{ n.service }}</p>
                                                <p class="text-gray-500">
                                                    {{ n.justification }}
                                                    <a v-if="n.source_url" :href="n.source_url" target="_blank" rel="noopener" class="ml-1 inline-flex items-center gap-1 underline hover:text-gray-900">Source <ExternalLink class="h-3.5 w-3.5" /></a>
                                                </p>
                                            </div>
                                            <Button type="button" size="sm" variant="outline" :disabled="form.phone === n.number" @click="form.phone = n.number">
                                                {{ form.phone === n.number ? 'Dans le champ' : 'Mettre dans le champ' }}
                                            </Button>
                                        </div>
                                    </div>
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

                        <div class="flex justify-end gap-4">
                            <Button v-if="hasNext" type="button" variant="outline" :disabled="busy || !form.name || !phoneValid" @click="create(false)">Créer la marque et retourner à la liste</Button>
                            <Button type="submit" :variant="identicalId ? 'outline' : 'default'" :disabled="busy || !form.name || !phoneValid">
                                {{ hasNext ? 'Créer la marque et passer à la suivante' : 'Créer la marque et retourner à la liste' }}
                            </Button>
                        </div>
                    </form>
                </div>

                <!-- ─── Sans créer ni associer de marque ─── -->
                <div class="flex justify-end gap-4">
                    <Button v-if="hasNext" type="button" variant="outline" :disabled="busy" @click="ignore(false)">Marquer la demande comme traitée et retourner à la liste</Button>
                    <Button type="button" variant="outline" :disabled="busy" @click="ignore(hasNext)">
                        {{ hasNext ? 'Marquer la demande comme traitée et passer à la suivante' : 'Marquer la demande comme traitée et retourner à la liste' }}
                    </Button>
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
