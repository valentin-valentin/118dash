<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Download, FileText, LoaderCircle, Pause, PhoneForwarded, Play, User, X } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import CallDetailPanel from '@/components/CallDetailPanel.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useFilters } from '@/composables/useFilters'
import { useFilterOptions } from '@/composables/useFilterOptions'
import { formatQuality, qualityClass } from '@/lib/quality'
import {
    agentLabel,
    carrierName,
    centerOf,
    formatDateTime,
    formatDay,
    formatDuration,
    formatEuros,
    formatInt,
    formatPhone,
    percent,
} from '@/lib/calls'

/**
 * Appels : retrouver un appel, l'écouter, voir son détail.
 *   1. Filtres (tous visibles, comme sur les autres pages) et période
 *   2. Résumé de la sélection : volumes, durées, argent
 *   3. Liste : ▶ dans la colonne Durée écoute la partie agent ou la partie transfert (lecteur en bas de page) ;
 *      un clic sur la ligne ouvre la fiche
 *   4. Fiche de l'appel (panneau) : enregistrements, déroulé, appelant et ses autres appels, argent, qualité…
 * Dates et heures en heure de Paris.
 */
const props = defineProps({
    centers: { type: Array, default: () => [] },
})

// ─── Filtres (gardés dans l'adresse de la page) ─────────────────────────────────
const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: '7d', label: '7 jours' },
    { key: '30d', label: '30 jours' },
    { key: 'month', label: 'Ce mois' },
    { key: 'custom', label: 'Dates' },
]
const OUTCOMES = [
    { value: 'answered', label: 'Décrochés par un agent' },
    { value: 'lost', label: 'Non décrochés' },
    { value: 'transferred', label: 'Transférés' },
]
const DEFAULTS = {
    caller: '',
    called: '',
    period: 'today',
    from: '',
    to: '',
    outcome: '',
    center: '',
    platform: '',
    has_recording: '',
    source_id: [],
    company_id: [],
    provider_id: [],
    brand_name: [],
    agent_name: [],
    carrier: [],
    duration_min: '',
    duration_max: '',
    audio_quality: '',
    blacklist_id: '',
    phone_agent_hangup: '',
    phone_reported_by_agent: '',
    phone_cant_provide_service: '',
    sort: 'called_at',
    dir: 'desc',
    per_page: 50,
    page: 1,
}
const FILTER_KEYS = ['caller', 'called', 'outcome', 'center', 'platform', 'has_recording', 'source_id', 'company_id', 'provider_id', 'brand_name', 'agent_name', 'carrier', 'duration_min', 'duration_max', 'audio_quality', 'blacklist_id', 'phone_agent_hangup', 'phone_reported_by_agent', 'phone_cant_provide_service']
const isSet = (v) => (Array.isArray(v) ? v.length > 0 : v !== '' && v !== null && v !== undefined)

const { filters, reset } = useFilters(DEFAULTS)
const hasFilters = computed(() =>
    Object.keys(DEFAULTS).some((k) => !['sort', 'dir', 'per_page', 'page'].includes(k) && isSet(filters[k]) && filters[k] !== DEFAULTS[k]),
)

// Jour au format AAAA-MM-JJ en heure de Paris
const parisDay = (date) => date.toLocaleDateString('en-CA', { timeZone: 'Europe/Paris' })
const shift = (days) => {
    const d = new Date()
    d.setDate(d.getDate() + days)
    return d
}
const range = computed(() => {
    const today = parisDay(new Date())
    switch (filters.period) {
        case 'yesterday':
            return { from: parisDay(shift(-1)), to: parisDay(shift(-1)) }
        case '7d':
            return { from: parisDay(shift(-6)), to: today }
        case '30d':
            return { from: parisDay(shift(-29)), to: today }
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today }
        case 'custom':
            return { from: filters.from || today, to: filters.to || today }
        default:
            return { from: today, to: today }
    }
})
function choosePeriod(key) {
    if (key === 'custom' && !filters.from) {
        filters.from = range.value.from
        filters.to = range.value.to
    }
    filters.period = key
}

// Paramètres envoyés au serveur
const params = computed(() => {
    const p = { date_from: range.value.from, date_to: range.value.to }
    for (const key of FILTER_KEYS) {
        const v = filters[key]
        if (isSet(v)) p[key] = Array.isArray(v) ? v.join(',') : v
    }
    return p
})
const query = (extra = {}) => new URLSearchParams({ ...params.value, ...extra }).toString()

// ─── Données ───────────────────────────────────────────────────────────────────
const {
    options: filterOptions,
    isLoading: isLoadingOptions,
    isClearing: isClearingCache,
    load: loadFilterOptions,
    clearCache: clearFilterCache,
} = useFilterOptions('/data/calls/filter-options', {
    brands: [],
    agents: [],
    carriers: [],
    providers: [],
    companies: [],
    sources: [],
    blacklists: [],
})

const list = reactive({ items: [], total: null, page: 1, lastPage: 1, loading: false, error: null })
const summary = reactive({ data: null, loading: false })
let listRequest = 0
let summaryRequest = 0

async function loadList() {
    const id = ++listRequest
    list.loading = true
    list.error = null
    try {
        const res = await fetch(`/data/calls?${query({ sort: filters.sort, dir: filters.dir, per_page: filters.per_page, page: filters.page })}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const data = await res.json()
        if (id !== listRequest) return
        Object.assign(list, { items: data.items, total: data.total, page: data.current_page, lastPage: data.last_page ?? data.current_page })
    } catch (e) {
        if (id === listRequest) list.error = e.message
    } finally {
        if (id === listRequest) list.loading = false
    }
}
async function loadSummary() {
    const id = ++summaryRequest
    summary.loading = true
    try {
        const res = await fetch(`/data/calls/summary?${query()}`, { headers: { Accept: 'application/json' } })
        if (res.ok && id === summaryRequest) summary.data = await res.json()
    } finally {
        if (id === summaryRequest) summary.loading = false
    }
}

// Un changement de filtre recharge le résumé et revient à la première page ; tri et page ne rechargent que la liste
let timer = null
watch(params, () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        if (filters.page !== 1) filters.page = 1
        else loadList()
        loadSummary()
    }, 350)
}, { deep: true })
watch(() => [filters.sort, filters.dir, filters.per_page, filters.page], loadList)

function toggleSort(key) {
    if (filters.sort === key) filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    else Object.assign(filters, { sort: key, dir: 'desc' })
}

const summaryFigures = computed(() => {
    const s = summary.data
    if (!s) return []
    return [
        { label: 'Appels', value: formatInt(s.calls) },
        { label: 'Décrochés par un agent', value: formatInt(s.answered), sub: percent(s.answered, s.calls) },
        { label: 'Non décrochés', value: formatInt(s.lost), sub: percent(s.lost, s.calls) },
        { label: 'Transférés', value: formatInt(s.transferred), sub: `${percent(s.transferred, s.answered)} des décrochés` },
        { label: 'Attente avant décroché', value: formatDuration(s.avg_wait_answered), sub: 'moyenne' },
        { label: 'Durée agent', value: formatDuration(s.avg_agent), sub: 'moyenne des décrochés' },
        { label: 'CA', value: formatEuros(s.payout, 0) },
        { label: 'Bénéfice', value: formatEuros(s.payout - s.payout_source, 0), sub: `reverse ${formatEuros(s.payout_source, 0)}` },
    ]
})

// ─── Fiche d'un appel (panneau) ────────────────────────────────────────────────
const openId = ref(null)
const sheetOpen = computed({
    get: () => openId.value !== null,
    set: (open) => !open && (openId.value = null),
})

// ─── Lecteur : un seul enregistrement à la fois, en bas de la page ──────────────
const audio = typeof Audio !== 'undefined' ? new Audio() : null
const player = reactive({ call: null, part: 'agent', playing: false, current: 0, duration: 0, rate: 1, loading: false, error: null })
const RATES = [1, 1.25, 1.5, 2]

if (audio) {
    audio.preload = 'auto'
    audio.addEventListener('timeupdate', () => (player.current = audio.currentTime))
    audio.addEventListener('loadedmetadata', () => (player.duration = audio.duration))
    audio.addEventListener('play', () => (player.playing = true))
    audio.addEventListener('pause', () => (player.playing = false))
    audio.addEventListener('waiting', () => (player.loading = true))
    audio.addEventListener('playing', () => (player.loading = false))
    audio.addEventListener('error', () => {
        player.loading = false
        player.error = "Impossible de lire l'enregistrement"
    })
    // Fin de la partie agent : le transfert suit, s'il est enregistré
    audio.addEventListener('ended', () => {
        if (player.part === 'agent' && player.call?.record_transfer) play(player.call, 'transfer')
    })
}

function play(call, part = 'agent') {
    if (!audio) return
    const url = part === 'transfer' ? call.record_transfer : call.record_agent
    if (!url) return
    if (player.call?.id === call.id && player.part === part) {
        audio.paused ? audio.play() : audio.pause()
        return
    }
    Object.assign(player, { call, part, current: 0, duration: 0, error: null, loading: true })
    audio.src = url
    audio.playbackRate = player.rate
    audio.play().catch(() => {})
}
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
// Montant sans symbole, pour le détail « CA - reverse = » (le résultat porte le €)
const amount = (v) => Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const isPlaying = (row, part) => player.call?.id === row.id && player.part === part && player.playing

// Une seule source de son : un lecteur de la fiche qui démarre met en pause les autres
function pauseOthers(e) {
    if (e.target instanceof HTMLMediaElement) {
        document.querySelectorAll('audio').forEach((a) => a !== e.target && a.pause())
        if (audio && e.target !== audio) audio.pause()
    }
}

// ─── Export CSV ────────────────────────────────────────────────────────────────
const exportableColumns = [
    { key: 'called_at', label: 'Date' },
    { key: 'caller', label: 'Appelant' },
    { key: 'called', label: 'Appelé' },
    { key: 'source', label: 'Source' },
    { key: 'provider', label: 'Provider' },
    { key: 'company', label: 'Company' },
    { key: 'voxnode_id', label: 'Voxnode ID' },
    { key: 'total_duration', label: 'Durée totale (s)' },
    { key: 'duration_agent', label: 'Durée agent (s)' },
    { key: 'duration_transfer', label: 'Durée transfert (s)' },
    { key: 'who_hangup', label: 'Qui a raccroché' },
    { key: 'agent_name', label: 'Agent' },
    { key: 'brand_name', label: 'Marque' },
    { key: 'brand_phonenumber', label: 'N° marque' },
    { key: 'carrier', label: 'Opérateur' },
    { key: 'record_agent', label: 'Enregistrement agent' },
    { key: 'record_transfer', label: 'Enregistrement transfert' },
    { key: 'blacklist', label: 'Blacklist' },
    { key: 'platform', label: 'Plateforme' },
    { key: 'audio_quality', label: 'Qualité audio (score)' },
]
const exportOpen = ref(false)
const exportColumns = ref(exportableColumns.map((c) => c.key))
function toggleExportColumn(key) {
    exportColumns.value = exportColumns.value.includes(key) ? exportColumns.value.filter((k) => k !== key) : [...exportColumns.value, key]
}
function downloadExport() {
    const columns = exportableColumns.map((c) => c.key).filter((k) => exportColumns.value.includes(k)).join(',')
    window.location.href = `/calls/export?${query({ columns })}`
    exportOpen.value = false
}

onMounted(() => {
    loadFilterOptions()
    loadList()
    loadSummary()
    document.addEventListener('play', pauseOthers, true)
})
onUnmounted(() => {
    audio?.pause()
    document.removeEventListener('play', pauseOthers, true)
})

const COLUMNS = [
    { key: 'called_at', label: 'Date', sortable: true },
    { key: 'caller', label: 'Appelant' },
    { key: 'called', label: 'Appelé' },
    { key: 'provider', label: 'Provider' },
    { key: 'company', label: 'Company' },
    { key: 'source', label: 'Source' },
    { key: 'brand_name', label: 'Marque', sortable: true },
    { key: 'agent_name', label: 'Agent', sortable: true },
    { key: 'carrier', label: 'Opé.' },
    { key: 'total_duration', label: 'Durée', sortable: true },
    { key: 'payout', label: 'Payout', sortable: true },
    { key: 'audio_quality', label: 'Qualité', sortable: true },
]
</script>

<template>
    <Head title="Appels" />

    <AppLayout>
        <div class="space-y-4 p-6" :class="player.call && 'pb-28'">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Appels' }]">
                <template #actions>
                    <Button variant="outline" size="sm" @click="exportOpen = true"><Download class="h-4 w-4" /> Exporter CSV</Button>
                </template>
            </PageHeader>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :is-loading="isLoadingOptions"
                :is-clearing="isClearingCache"
                :show-clear-cache="true"
                :result-count="list.total"
                :loading-results="list.loading"
                @reset="reset"
                @clear-cache="() => clearFilterCache('/data/calls/clear-filter-cache')"
            >
                <!-- Période -->
                <div class="mb-3 flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="p in PERIODS"
                        :key="p.key"
                        type="button"
                        class="h-8 rounded-md px-3 text-xs font-medium"
                        :class="filters.period === p.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="choosePeriod(p.key)"
                    >
                        {{ p.label }}
                    </button>
                    <template v-if="filters.period === 'custom'">
                        <Input v-model="filters.from" type="date" class="ml-1.5 h-8 w-40" />
                        <span class="text-xs text-gray-400">→</span>
                        <Input v-model="filters.to" type="date" class="h-8 w-40" />
                    </template>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    <Input v-model="filters.caller" type="text" placeholder="N° appelant..." class="h-8" />
                    <Input v-model="filters.called" type="text" placeholder="N° appelé..." class="h-8" />
                    <FilterSelect v-model="filters.source_id" :options="filterOptions.sources" placeholder="Toutes les sources" searchable multiple />
                    <FilterSelect v-model="filters.provider_id" :options="filterOptions.providers" placeholder="Tous les providers" searchable multiple />
                    <FilterSelect v-model="filters.company_id" :options="filterOptions.companies" placeholder="Toutes les companies" searchable multiple />
                    <FilterSelect v-model="filters.brand_name" :options="filterOptions.brands" placeholder="Toutes les marques" searchable multiple />
                    <FilterSelect v-model="filters.agent_name" :options="filterOptions.agents" placeholder="Tous les agents" searchable multiple />
                    <FilterSelect
                        v-model="filters.center"
                        :options="[...centers.map((c) => ({ value: c.key, label: c.label })), { value: 'none', label: 'Sans centre' }]"
                        placeholder="Tous les centres"
                        :searchable="false"
                    />
                    <FilterSelect v-model="filters.carrier" :options="filterOptions.carriers" placeholder="Tous les opérateurs" multiple />
                    <FilterSelect v-model="filters.outcome" :options="OUTCOMES" placeholder="Décroché ou non" :searchable="false" />
                    <Input v-model="filters.duration_min" type="number" min="0" placeholder="Durée min (s)" class="h-8" />
                    <Input v-model="filters.duration_max" type="number" min="0" placeholder="Durée max (s)" class="h-8" />
                    <FilterSelect
                        v-model="filters.has_recording"
                        :options="[{ value: 'yes', label: 'Avec enregistrement' }]"
                        placeholder="Enregistrement"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.phone_agent_hangup"
                        :options="[{ value: 'yes', label: 'Agent a raccroché' }, { value: 'no', label: 'Agent n’a pas raccroché' }]"
                        placeholder="Agent raccroche"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.phone_reported_by_agent"
                        :options="[{ value: 'yes', label: 'Signalé par agent' }, { value: 'no', label: 'Non signalé' }]"
                        placeholder="Signalé"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.phone_cant_provide_service"
                        :options="[{ value: 'yes', label: 'Service non fourni' }, { value: 'no', label: 'Service fourni' }]"
                        placeholder="Service"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.platform"
                        :options="[{ value: 'voxnode', label: 'Voxnode' }, { value: 'selfhosted', label: 'Notre serveur' }]"
                        placeholder="Plateforme"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.audio_quality"
                        :options="[{ value: 'degraded', label: 'Dégradés (score < 3,1)' }, { value: 'measured', label: 'Qualité mesurée' }, { value: 'unmeasured', label: 'Qualité non mesurée' }]"
                        placeholder="Qualité audio"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.blacklist_id"
                        :options="[{ value: 'any', label: 'Avec blacklist' }, { value: 'none', label: 'Sans blacklist' }, ...filterOptions.blacklists.map((b) => ({ value: b.id, label: b.phonenumber }))]"
                        placeholder="Blacklist"
                        searchable
                    />
                </div>
            </FilterBar>

            <!-- Résumé de la sélection -->
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100 sm:grid-cols-4 xl:grid-cols-8" :class="summary.loading && 'opacity-60'">
                <template v-if="summaryFigures.length">
                    <div v-for="f in summaryFigures" :key="f.label" class="bg-white px-4 py-3">
                        <div class="text-xs text-gray-500">{{ f.label }}</div>
                        <div class="text-lg font-semibold tabular-nums" :class="f.warn ? 'text-red-700' : 'text-gray-900'">{{ f.value }}</div>
                        <div v-if="f.sub" class="truncate text-xs text-gray-400">{{ f.sub }}</div>
                    </div>
                </template>
                <div v-else class="col-span-full h-[4.6rem] animate-pulse bg-white" />
            </div>

            <!-- Liste -->
            <div class="overflow-hidden rounded-lg border border-gray-100 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-[0.8125rem]">
                        <thead class="border-b border-gray-100 text-xs text-gray-400">
                            <tr>
                                <th
                                    v-for="col in COLUMNS"
                                    :key="col.key"
                                    class="px-1.5 py-2 text-left font-medium whitespace-nowrap uppercase tracking-wide first:pl-3"
                                    :class="[col.class, col.sortable && 'cursor-pointer select-none hover:text-gray-700']"
                                    @click="col.sortable && toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <template v-if="filters.sort === col.key">{{ filters.dir === 'asc' ? '↑' : '↓' }}</template>
                                </th>
                            </tr>
                        </thead>
                        <tbody :class="list.loading && 'opacity-50'">
                            <tr v-if="list.loading && !list.items.length">
                                <td :colspan="COLUMNS.length" class="py-12 text-center text-gray-400"><LoaderCircle class="mx-auto size-5 animate-spin" /></td>
                            </tr>
                            <tr v-else-if="list.error">
                                <td :colspan="COLUMNS.length" class="py-12 text-center text-red-600">{{ list.error }}</td>
                            </tr>
                            <tr v-else-if="!list.items.length">
                                <td :colspan="COLUMNS.length" class="py-12 text-center text-gray-400">Aucun appel</td>
                            </tr>
                            <tr
                                v-for="row in list.items"
                                :key="row.id"
                                class="cursor-pointer border-t border-gray-50 hover:bg-gray-50"
                                :class="[openId === row.id && 'bg-gray-50', player.call?.id === row.id && 'bg-amber-50/60 hover:bg-amber-50', row.blacklist_id && 'bg-red-50 hover:bg-red-100/80']"
                                @click="openId = row.id"
                            >
                                <td class="px-1.5 py-1.5 whitespace-nowrap first:pl-3">
                                    <div class="tabular-nums text-gray-900">{{ formatDateTime(row.called_at) }}</div>
                                </td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap text-gray-700 tabular-nums">{{ row.caller || '-' }}</td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap text-gray-700 tabular-nums">{{ row.called || '-' }}</td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <ColorBadge v-if="row.phonenumber?.provider" :color="row.phonenumber.provider.color" :label="row.phonenumber.provider.name" />
                                    <span v-else class="text-xs text-gray-400">-</span>
                                </td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <ColorBadge v-if="row.phonenumber?.company" :color="row.phonenumber.company.color" :label="row.phonenumber.company.name" />
                                    <span v-else class="text-xs text-gray-400">-</span>
                                </td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <ColorBadge v-if="row.source ?? row.phonenumber?.source" :color="(row.source ?? row.phonenumber.source).color" :label="(row.source ?? row.phonenumber.source).name" />
                                    <span v-else class="text-xs text-gray-400">-</span>
                                </td>
                                <td class="px-1.5 py-1.5 text-gray-900">{{ row.brand_name || '-' }}</td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <div class="text-xs text-gray-900">{{ agentLabel(row.agent_name) ?? '-' }}</div>
                                    <div v-if="centerOf(row.agent_name, centers)" class="text-xs text-gray-400">{{ centerOf(row.agent_name, centers).label }}</div>
                                </td>
                                <td class="px-1.5 py-1.5 text-xs whitespace-nowrap text-gray-900">{{ carrierName(row.carrier) ?? '-' }}</td>
                                <!-- Durée totale, partie agent et partie transfert, chacune avec son écoute -->
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <div class="flex items-center gap-1">
                                        <span class="w-10 tabular-nums text-gray-700">{{ formatDuration(row.total_duration) }}</span>
                                        <component
                                            :is="row.record_agent ? 'button' : 'span'"
                                            class="flex items-center gap-1 rounded px-1 py-0.5 text-xs tabular-nums"
                                            :class="row.record_agent ? (isPlaying(row, 'agent') ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-200') : 'text-gray-400'"
                                            :title="row.record_agent ? (isPlaying(row, 'agent') ? 'Pause' : 'Écouter la partie agent') : 'Durée agent'"
                                            @click.stop="row.record_agent && play(row, 'agent')"
                                        >
                                            <Pause v-if="isPlaying(row, 'agent')" class="size-3" />
                                            <Play v-else-if="row.record_agent" class="size-3" />
                                            <User v-else class="size-3" />
                                            {{ formatDuration(row.duration_agent) }}
                                        </component>
                                        <component
                                            :is="row.record_transfer ? 'button' : 'span'"
                                            class="flex items-center gap-1 rounded px-1 py-0.5 text-xs tabular-nums"
                                            :class="row.record_transfer ? (isPlaying(row, 'transfer') ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-200') : 'text-gray-400'"
                                            :title="row.record_transfer ? (isPlaying(row, 'transfer') ? 'Pause' : 'Écouter la partie transfert') : 'Durée transfert'"
                                            @click.stop="row.record_transfer && play(row, 'transfer')"
                                        >
                                            <Pause v-if="isPlaying(row, 'transfer')" class="size-3" />
                                            <Play v-else-if="row.record_transfer" class="size-3" />
                                            <PhoneForwarded v-else class="size-3" />
                                            {{ formatDuration(row.duration_transfer) }}
                                        </component>
                                    </div>
                                </td>
                                <td class="px-1.5 py-1.5 whitespace-nowrap">
                                    <div v-if="row.payout !== null && row.payout !== undefined" >
                                        <span class="text-xs text-gray-500">{{ amount(row.payout) }} - {{ amount(row.payout_source || 0) }} = </span>
                                        <span class="tabular-nums">{{ formatEuros(Number(row.payout) - Number(row.payout_source || 0)) }}</span>
                                    </div>
                                    <span v-else class="text-sm text-gray-400">-</span>
                                </td>
                                <td class="px-1.5 py-1.5 pr-3 whitespace-nowrap">
                                    <span  :class="qualityClass(row.audio_quality === null ? null : Number(row.audio_quality)) || 'text-gray-700'">
                                        {{ row.audio_quality === null || row.audio_quality === undefined ? '—' : formatQuality(Number(row.audio_quality), 1) }}
                                    </span>
                                    <div class="text-xs text-gray-400">{{ row.platform === 'selfhosted' ? 'Notre serveur' : 'Voxnode' }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="list.items.length" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-sm text-gray-500">
                    <div class="flex items-center gap-3">
                        <span>Page {{ list.page }} sur {{ list.lastPage }}<template v-if="list.total !== null"> · {{ formatInt(list.total) }} appel{{ list.total > 1 ? 's' : '' }}</template></span>
                        <select v-model.number="filters.per_page" class="rounded-md border border-gray-200 px-2 py-1 text-sm">
                            <option v-for="n in [50, 100, 250, 500]" :key="n" :value="n">{{ n }} par page</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="list.page <= 1" @click="filters.page = list.page - 1">Précédent</Button>
                        <Button variant="outline" size="sm" :disabled="list.page >= list.lastPage" @click="filters.page = list.page + 1">Suivant</Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lecteur -->
        <div v-if="player.call" class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur md:left-64">
            <div class="flex items-center gap-3 py-2.5 pr-20 pl-4">
                <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-900 text-white hover:bg-gray-800" :title="player.playing ? 'Pause' : 'Lecture'" @click="play(player.call, player.part)">
                    <LoaderCircle v-if="player.loading" class="size-4 animate-spin" />
                    <Pause v-else-if="player.playing" class="size-4" />
                    <Play v-else class="ml-0.5 size-4" />
                </button>
                <div class="w-56 min-w-0 shrink-0">
                    <div class="truncate text-sm font-medium text-gray-900">
                        {{ formatPhone(player.call.caller) }} · {{ agentLabel(player.call.agent_name) ?? '—' }}
                    </div>
                    <div class="truncate text-xs text-gray-500">
                        {{ formatDay(player.call.called_at) }}, {{ new Date(player.call.called_at).toLocaleTimeString('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit' }) }}
                        · {{ player.part === 'transfer' ? 'transfert' : 'conversation agent' }}
                    </div>
                </div>
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-gray-500">{{ formatDuration(player.current) }}</span>
                <input type="range" min="0" :max="player.duration || 0" step="0.1" :value="player.current" class="min-w-0 flex-1 accent-gray-900" aria-label="Position" @input="seek" />
                <span class="w-10 shrink-0 text-xs tabular-nums text-gray-500">{{ formatDuration(player.duration) }}</span>
                <p v-if="player.error" class="shrink-0 text-xs text-red-600">{{ player.error }}</p>
                <div class="flex shrink-0 items-center gap-1.5">
                    <button
                        v-if="player.call.record_transfer"
                        type="button"
                        class="h-7 rounded-md border px-2 text-xs"
                        :class="player.part === 'transfer' ? 'border-gray-900 text-gray-900' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                        @click="play(player.call, player.part === 'transfer' ? 'agent' : 'transfer')"
                    >
                        {{ player.part === 'transfer' ? 'Partie agent' : 'Partie transfert' }}
                    </button>
                    <button type="button" class="h-7 w-12 rounded-md border border-gray-200 text-xs tabular-nums text-gray-700 hover:bg-gray-50" title="Vitesse de lecture" @click="cycleRate">{{ String(player.rate).replace('.', ',') }}×</button>
                    <button type="button" class="flex h-7 items-center gap-1 rounded-md border border-gray-200 px-2 text-xs text-gray-700 hover:bg-gray-50" @click="openId = player.call.id"><FileText class="size-3.5" /> Fiche</button>
                    <button type="button" class="flex size-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Fermer le lecteur" @click="closePlayer"><X class="size-4" /></button>
                </div>
            </div>
        </div>

        <!-- Fiche d'un appel -->
        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader class="sr-only">
                    <SheetTitle>Fiche de l'appel</SheetTitle>
                    <SheetDescription>Enregistrements et détail de l'appel</SheetDescription>
                </SheetHeader>
                <div class="px-6 pt-6 pb-10">
                    <CallDetailPanel v-if="openId" :call-id="openId" :centers="centers" in-panel @open="(id) => (openId = id)" />
                </div>
            </SheetContent>
        </Sheet>

        <!-- Export CSV -->
        <Dialog v-model:open="exportOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Exporter les appels en CSV</DialogTitle>
                    <DialogDescription>Mêmes appels que la liste : période {{ range.from === range.to ? `du ${range.from}` : `du ${range.from} au ${range.to}` }} et filtres actifs.</DialogDescription>
                </DialogHeader>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                    <label v-for="col in exportableColumns" :key="col.key" class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <Checkbox :model-value="exportColumns.includes(col.key)" @update:model-value="toggleExportColumn(col.key)" />
                        {{ col.label }}
                    </label>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="exportOpen = false">Annuler</Button>
                    <Button :disabled="!exportColumns.length" @click="downloadExport"><Download class="h-4 w-4" /> Exporter</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
