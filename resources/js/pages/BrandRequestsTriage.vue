<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { ExternalLink, LoaderCircle, Pause, Phone, Play, X } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { formatDuration, formatPhone, toInternational } from '@/lib/calls'
import { call as softphoneCall } from '@/composables/useSoftphone'
import { refreshBrandRequests } from '@/composables/useBrandRequests'
import { xsrfToken } from '@/lib/csrf'

/**
 * Marques demandées, toutes dans un seul tableau : chaque ligne arrive avec l'action la plus probable déjà choisie,
 * modifiable sur la ligne, et se traite d'un clic (ou plusieurs lignes cochées d'un coup).
 *   Action proposée : associer à la marque dont le nom compris dans l'appel est exactement le nom (ou un autre nom),
 *   sinon à celle qui a exactement le nom tapé ; sinon créer la marque (nom compris dans l'appel, premier numéro
 *   trouvé par ChatGPT) ; marquer comme traitée quand la marque n'est jamais nommée dans l'appel et que ChatGPT n'a
 *   rien trouvé. Autres noms ajoutés : tous les noms proposés (comme sur la page d'une demande).
 *   Clic sur la ligne : fenêtre avec les appels, la transcription, les marques proches, les numéros de ChatGPT et
 *   tous les champs ; elle enchaîne sur la demande suivante.
 *   Lecteur en bas : seulement pour écouter, utilisable fenêtre ouverte.
 */
defineProps({ types: Array })

const rows = ref([])
const loading = ref(true)
const error = ref(null)
// État de chaque ligne (par clé de nom) : action choisie, champs, résultat
const state = reactive({})

const nameKey = (n) => String(n).normalize('NFD').replace(/[^a-z0-9]/gi, '').toLowerCase()
const titleCase = (s) => String(s).replace(/\S+/g, (w) => (w.length > 1 && w === w.toUpperCase() ? w : w.charAt(0).toUpperCase() + w.slice(1)))
const lines = (text) => text.split('\n').map((n) => n.trim()).filter(Boolean)
const firstNumber = (row) => (row.search?.status === 'done' && row.search.found ? row.search.suggestions?.[0] ?? null : null)

function proposedAction(row) {
    const exact = row.matches[0] ?? row.suggestions.find((b) => b.rank === 1)
    if (exact) return `link:${exact.id}`
    if (!firstNumber(row) && row.heard.state === 'never') return 'ignore'
    return 'create'
}
function initState(row) {
    const name = row.heard.main ?? titleCase(row.name ?? '')
    state[row.key] = {
        action: proposedAction(row),
        name,
        phone: firstNumber(row)?.number ?? '',
        type: 'service',
        country: 'FR',
        // Autres noms : tous les noms proposés ; pour une création, sauf le nom de la marque lui-même
        aliasesText: row.aliasOptions.filter((o) => nameKey(o.name) !== nameKey(name)).map((o) => o.name).join('\n'),
        linkAliasesText: row.aliasOptions.map((o) => o.name).join('\n'),
        // Marque choisie dans « Une autre marque » (fenêtre) ou marque créée puis rouverte
        extra: null,
        sameNumber: row.sameNumber ?? [],
        checked: false,
        busy: false,
        error: null,
        done: null,
    }
}

async function load() {
    try {
        const res = await fetch('/data/brand-requests/triage', { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const json = await res.json()
        json.items.forEach((row) => state[row.key] ?? initState(row))
        rows.value = json.items
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}
onMounted(load)

const date = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—')
const mmss = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`)
const origin = (row) => [row.calls - row.support ? '118 500' : null, row.support ? 'support' : null].filter(Boolean).join(' et ')

// ─── Action d'une ligne ─────────────────────────────────────────────────────────
// Marques proposées : celles dont le nom compris est exactement le nom, puis les proches, puis celle choisie à la main
function brandsOf(row) {
    const st = state[row.key]
    const list = [...row.matches.map((b) => ({ ...b, reason: 'nom compris dans l\'appel' })), ...row.suggestions.filter((s) => !row.matches.some((m) => m.id === s.id))]
    if (st?.extra && !list.some((b) => b.id === st.extra.id)) list.push(st.extra)
    return list
}
// Deux marques au même nom (doublons dans la base) : le numéro les distingue
function brandLabel(row, b) {
    const twin = brandsOf(row).some((o) => o.id !== b.id && o.name === b.name)
    return twin ? `${b.name} · ${b.phonenumber ? formatPhone(b.phonenumber) : 'sans numéro'}` : b.name
}
const linkedBrand = (row) => {
    const action = state[row.key]?.action ?? ''
    return action.startsWith('link:') ? brandsOf(row).find((b) => `link:${b.id}` === action) ?? null : null
}
const phoneOf = (row) => toInternational(state[row.key].phone)
const phoneValid = (row) => !phoneOf(row) || /^\+(33|32|49)\d{8,11}$/.test(phoneOf(row))
const premium = (row) => /^\+3389/.test(phoneOf(row))
// Numéro trouvé par ChatGPT qui est dans le champ (pour dire d'où il vient)
const chatgptNumber = (row) => row.search?.suggestions?.find((n) => n.number === phoneOf(row)) ?? null
function canApply(row) {
    const st = state[row.key]
    if (st.action === 'create') return !!st.name.trim() && phoneValid(row)
    if (st.action.startsWith('link:')) return !!linkedBrand(row)
    return st.action === 'ignore'
}
const ACTION_LABEL = { create: 'Créer la marque', ignore: 'Marquer comme traitée' }
const actionLabel = (row) => (linkedBrand(row) ? `Associer à ${brandLabel(row, linkedBrand(row))}` : ACTION_LABEL[state[row.key].action])
const buttonLabel = (row) => (linkedBrand(row) ? 'Associer à la marque' : ACTION_LABEL[state[row.key].action])

// Numéro collé ou saisi : remis au format international, puis cherché parmi les marques existantes
function formatPhoneField(row) {
    setTimeout(async () => {
        const st = state[row.key]
        st.phone = toInternational(st.phone)
        const code = st.phone.match(/^\+(33|32|49)/)?.[1]
        if (code) st.country = { 33: 'FR', 32: 'BE', 49: 'DE' }[code]
        st.sameNumber = []
        if (!st.phone || !phoneValid(row)) return
        const res = await fetch(`/data/brand-requests/phone-check?phonenumber=${encodeURIComponent(st.phone)}`, { headers: { Accept: 'application/json' } }).catch(() => null)
        if (res?.ok && state[row.key].phone === st.phone) st.sameNumber = (await res.json()).brands
    })
}
function setPhone(row, number) {
    state[row.key].phone = number
    state[row.key].action = 'create'
    formatPhoneField(row)
}

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: body ? JSON.stringify(body) : undefined,
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(json.errors ? Object.values(json.errors).flat()[0] : json.message || `Erreur HTTP ${res.status}`)
    return json
}
async function apply(row) {
    const st = state[row.key]
    if (st.busy || st.done || !canApply(row)) return false
    st.busy = true
    st.error = null
    try {
        const brand = linkedBrand(row)
        if (brand) {
            await post('/data/brand-requests/link', { ids: row.ids, brand_id: brand.id, aliases: lines(st.linkAliasesText) })
            st.done = { status: 'linked', id: brand.id, name: brand.name }
        } else if (st.action === 'create') {
            const json = await post('/data/brand-requests/create', {
                ids: row.ids,
                name: st.name.trim(),
                phonenumber: phoneOf(row) || null,
                country: st.country,
                type: st.type || 'service',
                can_transfer: !!phoneOf(row),
                aliases: lines(st.aliasesText),
            })
            st.done = { status: 'created', id: json.brand_id, name: st.name.trim(), phonenumber: phoneOf(row) || null }
        } else {
            await post('/data/brand-requests/ignore', { ids: row.ids })
            st.done = { status: 'ignored' }
        }
        st.checked = false
        refreshBrandRequests(true)
        return true
    } catch (e) {
        st.error = e.message
        return false
    } finally {
        st.busy = false
    }
}
// Rouvrir : la demande revient à traiter (autres noms ajoutés retirés de la marque). Une marque créée reste : la ligne
// propose alors de lui associer la demande, pour ne pas la créer en double.
async function reopen(row) {
    const st = state[row.key]
    st.busy = true
    st.error = null
    try {
        for (const id of row.ids) await post(`/data/brand-requests/${id}/reopen`)
        if (st.done.status === 'created') {
            st.extra = { id: st.done.id, name: st.done.name, phonenumber: st.done.phonenumber, reason: 'marque créée ici' }
            st.action = `link:${st.done.id}`
        }
        st.done = null
        refreshBrandRequests(true)
    } catch (e) {
        st.error = e.message
    } finally {
        st.busy = false
    }
}

// ─── Lignes cochées ─────────────────────────────────────────────────────────────
const open = computed(() => rows.value.filter((r) => !state[r.key].done))
const checked = computed(() => open.value.filter((r) => state[r.key].checked))
const allChecked = computed(() => (checked.value.length && checked.value.length === open.value.length ? true : checked.value.length ? 'indeterminate' : false))
function checkAll(value) {
    open.value.forEach((r) => (state[r.key].checked = !!value))
}
const bulkBusy = ref(false)
async function applyChecked() {
    bulkBusy.value = true
    for (const row of checked.value) await apply(row)
    bulkBusy.value = false
}

const columns = [
    { key: 'check', label: '', headerClass: 'w-8', class: 'w-8' },
    { key: 'request', label: 'Demande' },
    { key: 'listen', label: 'Écoute' },
    { key: 'action', label: 'Action' },
    { key: 'brand', label: 'Marque' },
    { key: 'apply', label: '' },
]
const rowClass = (row) => (row.key === lastOpenedKey.value ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')

// ─── Lecteur : le même que la page d'une demande (repère du nom, retour au nom) ─────
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
const markStart = (call) => (call.audio_at == null ? 0 : Math.max(0, call.audio_at - 2))
function play(call) {
    if (!audio || !call?.record) return
    if (player.call?.id === call.id) {
        audio.paused ? audio.play() : audio.pause()
        return
    }
    Object.assign(player, { call, current: 0, duration: 0, error: null, loading: true })
    audio.src = call.record
    audio.playbackRate = player.rate
    // Lecture juste avant la réplique où le client nomme la marque, depuis le début sans repère
    pendingStart = markStart(call)
    audio.play().catch(() => {})
}
function playAt(call, seconds) {
    if (!audio || !call.record) return
    if (player.call?.id !== call.id) {
        play(call)
        pendingStart = seconds
        return
    }
    audio.currentTime = seconds
    audio.play().catch(() => {})
}
function skip(seconds) {
    if (audio) audio.currentTime = Math.min(Math.max(0, audio.currentTime + seconds), player.duration || audio.currentTime + seconds)
}
function backToMark() {
    if (!audio || player.call?.audio_at == null) return
    audio.currentTime = markStart(player.call)
    audio.play().catch(() => {})
}
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
const isPlaying = (call) => !!call && player.call?.id === call.id && player.playing
onUnmounted(() => audio?.pause())

// ─── Fenêtre d'une demande ──────────────────────────────────────────────────────
const openKey = ref(null)
const lastOpenedKey = ref(null)
const detail = ref(null)
const detailError = ref(null)
const modalOpen = computed({
    get: () => openKey.value !== null,
    set: (value) => !value && (openKey.value = null),
})
const current = computed(() => rows.value.find((r) => r.key === openKey.value) ?? null)
const cs = computed(() => (current.value ? state[current.value.key] : null))
function openRow(row) {
    lastOpenedKey.value = row.key
    openKey.value = row.key
}
watch(openKey, async (key) => {
    detail.value = null
    detailError.value = null
    selectedCallId.value = null
    if (key === null) return
    try {
        const res = await fetch(`/data/brand-requests/${key}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const json = await res.json()
        if (openKey.value === key) detail.value = json
    } catch (e) {
        detailError.value = e.message
    }
})
// Demande suivante : la prochaine ligne pas encore traitée, dans l'ordre du tableau (après la dernière, retour au début)
const nextRow = computed(() => {
    const list = rows.value
    const i = list.findIndex((r) => r.key === openKey.value)
    return [...list.slice(i + 1), ...list.slice(0, Math.max(0, i))].find((r) => !state[r.key].done) ?? null
})
function goNext() {
    nextRow.value ? openRow(nextRow.value) : (openKey.value = null)
}
async function applyAndNext(action) {
    if (action) cs.value.action = action
    if (await apply(current.value)) goNext()
}
// Clic dans le lecteur pendant que la fenêtre est ouverte : la fenêtre reste ouverte
function keepOpenForPlayer(e) {
    if (e.target?.closest?.('[data-player]')) e.preventDefault()
}

// Appels de la demande et transcription de celui sélectionné (le premier à l'ouverture)
const callColumns = [
    { key: 'at', label: 'Date' },
    { key: 'agent', label: 'Agent' },
    { key: 'called', label: 'Numéro appelé' },
    { key: 'name', label: 'Nom tapé' },
    { key: 'record', label: 'Conversation' },
]
const selectedCallId = ref(null)
const transcriptCall = computed(() => {
    const calls = detail.value?.calls ?? []
    return calls.find((c) => c.id === selectedCallId.value) ?? calls.find((c) => c.audio_at != null) ?? calls[0] ?? null
})
const callRowClass = (row) => (row.id === transcriptCall.value?.id ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')
const currentLine = computed(() => {
    if (player.call?.id !== transcriptCall.value?.id) return -1
    let index = -1
    ;(transcriptCall.value?.transcript ?? []).forEach((l, i) => l.s <= player.current + 0.2 && (index = i))
    return index
})
const isMarkLine = (call, line) => call.audio_at != null && Math.abs(line.s - call.audio_at) < 0.05
// À l'affichage d'une transcription, la réplique du nom est centrée dans sa zone
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
// « Une autre marque » : ajoutée aux choix de la ligne et choisie
function pickOther(id) {
    const b = detail.value?.brands.find((o) => o.value === id)
    if (!b || !current.value) return
    cs.value.extra = { id: b.value, name: b.label, phonenumber: null, reason: 'choisie à la main' }
    cs.value.action = `link:${b.value}`
}
const googleUrl = computed(() => `https://www.google.com/search?q=${encodeURIComponent(`${cs.value?.name || current.value?.name || ''} numéro de téléphone service client contact`)}`)
</script>

<template>
    <Head title="Traiter les demandes" />

    <AppLayout>
        <div class="space-y-6 p-6" :class="player.call && 'pb-28'">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées', href: '/brands/requests' }, { title: 'Traiter les demandes' }]">
                <template #actions>
                    <Button variant="outline" as-child><Link href="/brands/requests?tab=handled">Voir les demandes traitées</Link></Button>
                </template>
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <section>
                <div class="mb-2 flex items-end justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">
                            À traiter <span class="ml-1 font-normal tabular-nums text-gray-400">{{ loading ? '' : open.length }}</span>
                        </h2>
                        <p class="text-sm text-gray-500">Action proposée : la marque qui a exactement le nom compris dans l'appel ou le nom tapé, sinon une création avec le numéro trouvé par ChatGPT. Un clic sur la ligne ouvre les appels et leur transcription.</p>
                    </div>
                    <!-- Action principale : traiter d'un coup les lignes dont la proposition est bonne -->
                    <Button size="sm" class="shrink-0" :disabled="!checked.length || bulkBusy" @click="applyChecked">
                        <LoaderCircle v-if="bulkBusy" class="mr-2 h-4 w-4 animate-spin" />
                        {{ checked.length > 1 ? `Traiter les ${checked.length} demandes cochées` : 'Traiter la demande cochée' }}
                    </Button>
                </div>

                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="columns" :rows="rows" :loading="loading" empty-message="Aucune marque à traiter" :row-class="rowClass" :row-click="openRow">
                        <template #header-check>
                            <Checkbox :model-value="allChecked" :disabled="!open.length" aria-label="Tout cocher" @update:model-value="checkAll" />
                        </template>
                        <template #check="{ row }">
                            <div @click.stop>
                                <Checkbox v-if="!state[row.key].done" v-model="state[row.key].checked" :aria-label="`Cocher ${row.name}`" />
                            </div>
                        </template>

                        <template #request="{ row }">
                            <div class="min-w-48 py-1">
                                <span class="font-medium text-gray-900">{{ row.name }}</span>
                                <span v-if="row.variants.length" class="ml-2 text-xs text-gray-500">{{ row.variants.join(', ') }}</span>
                                <!-- Seulement le nom principal compris : les autres formes sont dans la fenêtre -->
                                <p v-if="row.heard.state === 'names'" class="text-xs text-gray-600">Compris : {{ row.heard.main }}</p>
                                <p v-else-if="row.heard.state === 'never'" class="text-xs text-gray-500">Jamais nommée dans l'appel</p>
                                <p class="text-xs text-gray-400 tabular-nums">{{ row.calls }} appel{{ row.calls > 1 ? 's' : '' }} · {{ date(row.last_at) }}</p>
                            </div>
                        </template>

                        <template #listen="{ row }">
                            <button
                                v-if="row.listen"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm whitespace-nowrap"
                                :class="isPlaying(row.listen) ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                :title="row.listen.audio_at != null ? 'Écouter à partir du moment où le client nomme la marque' : 'Écouter la conversation'"
                                @click.stop="play(row.listen)"
                            >
                                <Pause v-if="isPlaying(row.listen)" class="size-3.5" />
                                <Play v-else class="size-3.5" />
                                {{ mmss(row.listen.duration) }}
                            </button>
                            <span v-else class="text-sm text-gray-400">—</span>
                        </template>

                        <template #action="{ row }">
                            <div @click.stop>
                                <template v-if="state[row.key].done">
                                    <span v-if="state[row.key].done.status === 'created'" class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">Marque créée</span>
                                    <span v-else-if="state[row.key].done.status === 'linked'" class="inline-flex rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">Associée</span>
                                    <span v-else class="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">Traitée sans marque</span>
                                </template>
                                <select v-else v-model="state[row.key].action" class="flex h-9 w-72 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" :disabled="state[row.key].busy">
                                    <option v-for="b in brandsOf(row)" :key="b.id" :value="`link:${b.id}`">Associer à {{ brandLabel(row, b) }}</option>
                                    <option value="create">Créer la marque</option>
                                    <option value="ignore">Marquer comme traitée</option>
                                </select>
                            </div>
                        </template>

                        <template #brand="{ row }">
                            <div class="w-80 py-1" @click.stop>
                                <!-- Traitée : la marque, ouverte dans un autre onglet pour ne pas perdre le tableau -->
                                <template v-if="state[row.key].done">
                                    <a v-if="state[row.key].done.id" :href="`/brands/${state[row.key].done.id}/edit`" target="_blank" class="text-sm font-medium text-gray-900 hover:underline">{{ state[row.key].done.name }}</a>
                                    <span v-else class="text-sm text-gray-400">—</span>
                                </template>
                                <template v-else-if="linkedBrand(row)">
                                    <a :href="`/brands/${linkedBrand(row).id}/edit`" target="_blank" class="text-sm font-medium text-gray-900 hover:underline">{{ linkedBrand(row).name }}</a>
                                    <span class="ml-2 text-sm tabular-nums text-gray-600">{{ linkedBrand(row).phonenumber ? formatPhone(linkedBrand(row).phonenumber) : 'sans numéro' }}</span>
                                    <p class="text-xs text-gray-500">{{ linkedBrand(row).reason }}<template v-if="linkedBrand(row).via"> (autre nom « {{ linkedBrand(row).via }} »)</template></p>
                                    <p v-if="lines(state[row.key].linkAliasesText).length" class="text-xs text-gray-500" data-ui-ok>
                                        <!-- Liste d'autres noms : peut passer à la ligne -->
                                        Autres noms ajoutés : {{ lines(state[row.key].linkAliasesText).join(', ') }}
                                    </p>
                                </template>
                                <div v-else-if="state[row.key].action === 'create'" class="space-y-1">
                                    <div class="flex gap-2">
                                        <Input v-model="state[row.key].name" class="h-8 w-40" placeholder="Nom" :aria-label="`Nom de la marque ${row.name}`" />
                                        <Input
                                            v-model="state[row.key].phone"
                                            class="h-8 w-40 tabular-nums"
                                            placeholder="Numéro"
                                            :aria-label="`Numéro de ${row.name}`"
                                            @paste="formatPhoneField(row)"
                                            @blur="formatPhoneField(row)"
                                        />
                                    </div>
                                    <p v-if="!phoneValid(row)" class="text-xs text-red-600">Numéro français, belge ou allemand attendu</p>
                                    <p v-else-if="chatgptNumber(row)" class="flex items-center gap-1.5 text-xs text-gray-500">
                                        ChatGPT
                                        <span class="inline-flex rounded-full px-1.5 py-0.5 text-xs font-medium" :class="chatgptNumber(row).official_source ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'">{{ chatgptNumber(row).official_source ? 'Site officiel' : 'Autre source' }}</span>
                                        <span v-if="row.search.suggestions.length > 1">· {{ row.search.suggestions.length - 1 }} autre{{ row.search.suggestions.length > 2 ? 's' : '' }}</span>
                                    </p>
                                    <p v-else-if="row.search?.status === 'done' && !row.search.found && !phoneOf(row)" class="text-xs text-gray-500">ChatGPT n'a pas trouvé de numéro</p>
                                    <p v-else-if="row.search?.status === 'pending' && !phoneOf(row)" class="text-xs text-gray-500">Numéro pas encore cherché par ChatGPT</p>
                                    <p v-else-if="!phoneOf(row)" class="text-xs text-gray-500">Sans numéro : pas de transfert</p>
                                    <p v-if="premium(row)" class="text-xs text-amber-700">Numéro surtaxé (08 9)</p>
                                    <p v-if="state[row.key].sameNumber.length" class="text-xs text-red-600">
                                        Déjà le numéro de
                                        <template v-for="(b, i) in state[row.key].sameNumber" :key="b.id">{{ i ? ', ' : '' }}<a :href="`/brands/${b.id}/edit`" target="_blank" class="underline">{{ b.name }}</a></template>
                                    </p>
                                </div>
                                <span v-else class="text-sm text-gray-500">Ni création ni association</span>
                            </div>
                        </template>

                        <template #apply="{ row }">
                            <div class="flex flex-col items-end gap-1" @click.stop>
                                <Button v-if="state[row.key].done" size="sm" variant="ghost" :disabled="state[row.key].busy" @click="reopen(row)">Rouvrir</Button>
                                <Button v-else size="sm" variant="outline" class="w-44" :disabled="state[row.key].busy || !canApply(row)" @click="apply(row)">
                                    <LoaderCircle v-if="state[row.key].busy" class="mr-2 h-4 w-4 animate-spin" />
                                    {{ buttonLabel(row) }}
                                </Button>
                                <p v-if="state[row.key].error" class="max-w-56 text-right text-xs text-red-600" data-ui-ok>{{ state[row.key].error }}</p>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </section>
        </div>

        <!-- Lecteur (même barre que la page d'une demande) ; fenêtre ouverte, il passe devant son fond sombre et reste cliquable -->
        <div
            v-if="player.call"
            data-player
            class="fixed inset-x-0 bottom-0 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur md:left-64"
            :class="modalOpen ? 'pointer-events-auto z-[60]' : 'z-40'"
        >
            <div class="flex items-center gap-3 py-2.5 pr-20 pl-4">
                <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-900 text-white hover:bg-gray-800" :title="player.playing ? 'Pause' : 'Lecture'" @click="play(player.call)">
                    <LoaderCircle v-if="player.loading" class="size-4 animate-spin" />
                    <Pause v-else-if="player.playing" class="size-4" />
                    <Play v-else class="ml-0.5 size-4" />
                </button>
                <div class="w-56 min-w-0 shrink-0">
                    <div class="truncate text-sm font-medium text-gray-900">« {{ player.call.name }} » · {{ player.call.agent || '—' }}</div>
                    <div class="truncate text-xs text-gray-500">{{ date(player.call.at) }} · {{ player.call.caller ? formatPhone(player.call.caller) : '—' }}</div>
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

        <!-- Fenêtre d'une demande : avec son fond sombre ; hauteur limitée pour ne pas passer sous le lecteur.
             Les champs sont ceux de la ligne : ce qui est choisi ici se retrouve dans le tableau, et inversement. -->
        <Dialog v-model:open="modalOpen">
            <DialogContent class="max-h-[calc(100vh-10rem)] overflow-y-auto sm:max-w-5xl" @interact-outside="keepOpenForPlayer">
                <DialogHeader v-if="current">
                    <DialogTitle>
                        {{ current.name }}
                        <span v-if="current.variants.length" class="ml-2 text-sm font-normal text-gray-500">{{ current.variants.join(', ') }}</span>
                    </DialogTitle>
                    <DialogDescription>
                        {{ current.calls }} appel{{ current.calls > 1 ? 's' : '' }} {{ origin(current) }} · dernière demande le {{ date(current.last_at) }}
                    </DialogDescription>
                </DialogHeader>

                <p v-if="detailError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ detailError }}</p>
                <div v-else-if="!detail" class="space-y-3">
                    <div v-for="i in 4" :key="i" class="h-16 animate-pulse rounded bg-gray-50" />
                </div>

                <div v-else-if="current && cs" class="space-y-6">
                    <!-- État traité (depuis la ligne, ou une action ici) : le résultat et Rouvrir -->
                    <div v-if="cs.done" class="flex items-center gap-3 rounded-lg border border-gray-100 px-4 py-3 text-sm">
                        <span class="text-gray-900">
                            <template v-if="cs.done.status === 'created'">Marque créée : </template>
                            <template v-else-if="cs.done.status === 'linked'">Associée à </template>
                            <template v-else>Marquée comme traitée, sans marque</template>
                            <a v-if="cs.done.id" :href="`/brands/${cs.done.id}/edit`" target="_blank" class="font-medium underline">{{ cs.done.name }}</a>
                        </span>
                        <Button size="sm" variant="ghost" class="ml-auto" :disabled="cs.busy" @click="reopen(current)">Rouvrir</Button>
                    </div>

                    <!-- Nom tapé et nom compris (même barre que la page d'une demande) -->
                    <div class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border border-gray-100 px-4 py-2.5 text-sm">
                        <span><span class="text-gray-500">Nom tapé</span> <span class="ml-1 font-medium text-gray-900">{{ detail.names.map((n) => n.name).join(', ') }}</span></span>
                        <span v-if="detail.heard.state === 'names'">
                            <span class="text-gray-500">Compris dans l'appel</span> <span class="ml-1 font-medium text-gray-900">{{ detail.heard.main }}</span>
                            <span v-if="detail.heard.others.length" class="ml-2 text-xs text-gray-500">{{ detail.heard.others.join(', ') }}</span>
                        </span>
                        <span v-else-if="detail.heard.state === 'never'" class="text-gray-500">Marque jamais nommée dans l'appel</span>
                    </div>

                    <!-- Appels : un clic choisit la transcription affichée, seul ▶ lance l'écoute -->
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ detail.calls.length }}</span></h3>
                        <div class="rounded-lg border border-gray-100">
                            <DataTable :columns="callColumns" :rows="detail.calls" empty-message="Aucun appel à traiter" :row-class="callRowClass" :row-click="(c) => (selectedCallId = c.id)">
                                <template #at="{ value }"><span class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ date(value) }}</span></template>
                                <template #agent="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #called="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #name="{ value }"><span class="text-sm text-gray-900">{{ value }}</span></template>
                                <template #record="{ row }">
                                    <button
                                        v-if="row.record"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                        :class="isPlaying(row) ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                        @click.stop="play(row)"
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

                    <section v-if="transcriptCall">
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">
                            Transcription <span class="ml-1 font-normal text-gray-500">{{ date(transcriptCall.at) }} · {{ transcriptCall.agent || '—' }} · nom tapé « {{ transcriptCall.name }} »</span>
                        </h3>
                        <div class="rounded-lg border border-gray-100">
                            <p v-if="transcriptCall.transcript === null" class="px-4 py-3 text-sm text-gray-500">Transcription pas encore disponible</p>
                            <p v-else-if="!transcriptCall.transcript.length" class="px-4 py-3 text-sm text-gray-500">Pas de transcription</p>
                            <div v-else ref="transcriptBox" class="relative max-h-72 divide-y divide-gray-50 overflow-y-auto">
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

                    <template v-if="!cs.done">
                        <!-- Marques existantes : un clic choisit « Associer à cette marque » pour la demande -->
                        <section>
                            <h3 class="mb-2 text-sm font-semibold text-gray-900">Marques existantes proches</h3>
                            <div class="divide-y divide-gray-50 rounded-lg border border-gray-100">
                                <div
                                    v-for="b in brandsOf(current)"
                                    :key="b.id"
                                    class="flex cursor-pointer items-center gap-4 px-4 py-2 text-sm"
                                    :class="cs.action === `link:${b.id}` ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : 'hover:bg-gray-50'"
                                    @click="cs.action = `link:${b.id}`"
                                >
                                    <a :href="`/brands/${b.id}/edit`" target="_blank" class="font-medium text-gray-900 hover:underline" @click.stop>{{ b.name }}</a>
                                    <span class="tabular-nums text-gray-600">{{ b.phonenumber ? formatPhone(b.phonenumber) : 'sans numéro' }}</span>
                                    <span class="text-gray-500">{{ b.type || '—' }}</span>
                                    <span class="text-gray-500">{{ b.reason }}<template v-if="b.via"> (autre nom « {{ b.via }} »)</template><template v-if="b.source"> · {{ b.source }}</template></span>
                                </div>
                                <div class="flex items-center gap-4 px-4 py-2 text-sm">
                                    <span class="text-gray-700">Une autre marque</span>
                                    <div class="w-72"><FilterSelect :model-value="null" :options="detail.brands" placeholder="Chercher une marque" searchable @update:model-value="pickOther" /></div>
                                </div>
                            </div>
                            <div v-if="linkedBrand(current)" class="mt-3 space-y-2">
                                <Label for="tr-link-aliases">Autres noms ajoutés à {{ linkedBrand(current).name }}</Label>
                                <Textarea id="tr-link-aliases" v-model="cs.linkAliasesText" :rows="Math.max(2, cs.linkAliasesText.split('\n').length)" placeholder="Un nom par ligne" />
                                <p class="text-sm text-gray-500">Ceux que la marque a déjà ne sont pas ajoutés en double.</p>
                            </div>
                        </section>

                        <!-- Créer la marque : un clic dans le bloc choisit « Créer la marque » -->
                        <section @focusin="cs.action = 'create'">
                            <h3 class="mb-2 text-sm font-semibold text-gray-900">
                                Créer la marque <span class="ml-1 font-normal text-gray-500">activée tout de suite et visible des agents</span>
                            </h3>
                            <div class="grid gap-4 rounded-lg border border-gray-100 p-4 md:grid-cols-2" :class="cs.action === 'create' && 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]'">
                                <div class="space-y-2">
                                    <Label for="tr-name">Nom *</Label>
                                    <Input id="tr-name" v-model="cs.name" class="bg-white" />
                                    <a :href="googleUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-gray-500 underline hover:text-gray-900">Chercher le service client sur Google <ExternalLink class="h-3.5 w-3.5" /></a>
                                </div>
                                <div class="space-y-2">
                                    <Label for="tr-phone">Numéro de transfert</Label>
                                    <div class="flex items-center gap-2">
                                        <Input id="tr-phone" v-model="cs.phone" placeholder="+33123456789" class="bg-white tabular-nums" @paste="formatPhoneField(current)" @blur="formatPhoneField(current)" />
                                        <Button v-if="phoneOf(current) && phoneValid(current)" type="button" size="sm" variant="outline" @click="softphoneCall(phoneOf(current))"><Phone class="mr-2 h-4 w-4" />Appeler</Button>
                                    </div>
                                    <p v-if="!phoneValid(current)" class="text-sm text-red-600">Numéro français, belge ou allemand attendu.</p>
                                    <p v-if="premium(current)" class="text-sm text-amber-700">Numéro surtaxé (08 9).</p>
                                    <p v-if="cs.sameNumber.length" class="text-sm text-red-600">
                                        Déjà le numéro de
                                        <template v-for="(b, i) in cs.sameNumber" :key="b.id">{{ i ? ', ' : '' }}<a :href="`/brands/${b.id}/edit`" target="_blank" class="underline">{{ b.name }}</a></template>
                                        : les appels des deux marques seraient mélangés.
                                    </p>
                                </div>
                                <div class="space-y-2 md:col-span-2">
                                    <Label for="tr-aliases">Autres noms</Label>
                                    <Textarea id="tr-aliases" v-model="cs.aliasesText" class="bg-white" :rows="Math.max(2, cs.aliasesText.split('\n').length)" placeholder="Un nom par ligne" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="tr-type">Type</Label>
                                    <Input id="tr-type" v-model="cs.type" class="bg-white" list="tr-types" placeholder="service, bank, ecommerce…" />
                                    <datalist id="tr-types"><option v-for="t in types" :key="t" :value="t" /></datalist>
                                </div>
                                <div class="space-y-2">
                                    <Label for="tr-country">Pays</Label>
                                    <select id="tr-country" v-model="cs.country" class="flex h-9 w-full rounded-md border border-input bg-white px-3 py-1 text-sm shadow-sm">
                                        <option value="FR">France</option>
                                        <option value="BE">Belgique</option>
                                        <option value="DE">Allemagne</option>
                                    </select>
                                </div>

                                <!-- Numéros trouvés par ChatGPT, sous les champs qu'ils remplissent -->
                                <div v-if="current.search" class="space-y-2 md:col-span-2">
                                    <p class="text-sm font-medium text-gray-900">
                                        Trouvé par ChatGPT
                                        <span v-if="current.search.searched_name" class="ml-1 font-normal text-gray-500">recherche « {{ current.search.searched_name }} »</span>
                                    </p>
                                    <p v-if="current.search.status === 'pending'" class="text-sm text-gray-500">Recherche en cours</p>
                                    <p v-else-if="current.search.status === 'failed'" class="text-sm text-gray-500">Recherche impossible</p>
                                    <template v-else-if="!current.search.found">
                                        <p class="text-sm text-gray-900">ChatGPT n'a pas trouvé de numéro +33</p>
                                        <p v-if="current.search.summary" class="text-sm text-gray-500">{{ current.search.summary }}</p>
                                    </template>
                                    <div v-else class="divide-y divide-gray-50 rounded-lg border border-gray-100 bg-white">
                                        <div v-for="n in current.search.suggestions" :key="n.number" class="flex items-start gap-4 px-4 py-3 text-sm">
                                            <div class="min-w-0 flex-1 space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-medium tabular-nums text-gray-900">{{ formatPhone(n.number) }}</span>
                                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="n.official_source ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'">{{ n.official_source ? 'Site officiel' : 'Autre source' }}</span>
                                                    <span v-if="n.premium" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Surtaxé</span>
                                                    <span class="text-gray-500">Confiance {{ n.confidence }}</span>
                                                </div>
                                                <p class="text-gray-900">{{ n.service }}</p>
                                                <p class="text-gray-500">
                                                    {{ n.justification }}
                                                    <a v-if="n.source_url" :href="n.source_url" target="_blank" rel="noopener" class="ml-1 inline-flex items-center gap-1 underline hover:text-gray-900">Source <ExternalLink class="h-3.5 w-3.5" /></a>
                                                </p>
                                            </div>
                                            <Button type="button" size="sm" variant="outline" :disabled="phoneOf(current) === n.number" @click="setPhone(current, n.number)">
                                                {{ phoneOf(current) === n.number ? 'Dans le champ' : 'Mettre dans le champ' }}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <p v-if="cs.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ cs.error }}</p>
                    </template>
                </div>

                <!-- Pied : l'action choisie (bouton noir), traiter sans marque, ou passer sans rien faire -->
                <DialogFooter v-if="current && cs && detail" class="sm:justify-between">
                    <Button variant="ghost" :disabled="!nextRow" @click="goNext">Passer à la demande suivante</Button>
                    <div v-if="!cs.done" class="flex gap-3">
                        <Button v-if="cs.action !== 'ignore'" variant="outline" :disabled="cs.busy" @click="applyAndNext('ignore')">Marquer comme traitée</Button>
                        <Button :disabled="cs.busy || !canApply(current)" @click="applyAndNext()">
                            <LoaderCircle v-if="cs.busy" class="mr-2 h-4 w-4 animate-spin" />
                            {{ actionLabel(current) }} {{ nextRow ? 'et passer à la suivante' : 'et fermer' }}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
