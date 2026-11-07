<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import FilterBar from '@/components/FilterBar.vue'
import TrendChart from '@/components/TrendChart.vue'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { addDays, iso } from '@/lib/performance'

/**
 * Affluence et effectifs (GET /staffing de voxnode118).
 *   1. Grille par demi-heure : une ligne par jour (aujourd'hui, un jour) ou par jour de semaine (moyennes)
 *   2. Évolution jour par jour (7 derniers jours et semaines)
 * Le manque d'agents se mesure à l'attente des clients : décrochés après plus de longWaitMinutes (8 min),
 * l'accueil et l'attente forcée (environ 3 min) étant voulus.
 */
const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: 'day', label: 'Un jour…' },
    { key: 'week', label: '7 derniers jours' },
    { key: 'w4', label: '4 semaines', weeks: 4 },
    { key: 'w8', label: '8 semaines', weeks: 8 },
    { key: 'w12', label: '12 semaines', weeks: 12 },
    { key: 'w26', label: '26 semaines', weeks: 26 },
]
const preset = ref('today')
const pickedDay = ref(iso(addDays(new Date(), -2)))
const team = ref('all')

const query = computed(() => {
    const p = PERIODS.find((x) => x.key === preset.value)
    if (preset.value === 'yesterday') return { period: 'day', date: iso(addDays(new Date(), -1)), team: team.value }
    if (preset.value === 'day') return { period: 'day', date: pickedDay.value, team: team.value }
    if (p.weeks) return { period: 'weeks', weeks: p.weeks, team: team.value }
    return { period: preset.value, team: team.value }
})

const data = ref(null)
const loading = ref(false)
const error = ref(null)
let requestId = 0
async function load() {
    if (query.value.period === 'day' && !query.value.date) return
    const id = ++requestId
    loading.value = true
    try {
        const res = await fetch(`/data/staffing?${new URLSearchParams(query.value)}`, { headers: { Accept: 'application/json' } })
        const json = res.ok ? await res.json() : { ok: false, error: `Erreur HTTP ${res.status}` }
        if (id !== requestId) return
        if (json.ok) {
            data.value = json.data
            error.value = null
        } else {
            data.value = null
            error.value = json.error
        }
    } catch (e) {
        if (id === requestId) error.value = e.message
    } finally {
        if (id === requestId) loading.value = false
    }
}
watch(query, (q, old) => JSON.stringify(q) !== JSON.stringify(old) && load(), { immediate: true })

// Aujourd'hui : relu toutes les 5 min
let timer = null
onMounted(() => (timer = setInterval(() => preset.value === 'today' && document.visibilityState === 'visible' && load(), 5 * 60 * 1000)))
onUnmounted(() => clearInterval(timer))

// Gardées d'un chargement à l'autre : le filtre reste utilisable si un chargement échoue
const teams = ref([{ id: 'all', name: 'Tous' }])
watch(data, (d) => d?.teams?.length && (teams.value = d.teams))
const longWait = computed(() => data.value?.longWaitMinutes ?? 8)
const live = computed(() => (data.value?.rows ?? []).some((r) => r.live))

// ─── Formats ──────────────────────────────────────────────────────────────────
const fmt = (v, digits = 0) =>
    v === null || v === undefined ? '—' : new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v).replace(/ | /g, ' ')
// Moyennes : une décimale tant que la valeur n'est pas entière
const num = (v) => (v === null || v === undefined ? '—' : fmt(v, Number.isInteger(v) ? 0 : 1))
const mmss = (s) => (s === null || s === undefined ? '—' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`)
const shortDate = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
const longDate = (d) => {
    const label = new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
    return label.charAt(0).toUpperCase() + label.slice(1)
}

// ─── Grille ───────────────────────────────────────────────────────────────────
const MODES = [
    // Clients décrochés après plus de 8 min : rien quand il n'y en a pas ; rouge seulement en manque d'agents
    { key: 'wait', label: 'Attente', value: (c) => (c.longWaits > 0 ? num(c.longWaits) : '') },
    { key: 'occupancy', label: 'Occupation', value: (c) => `${fmt(c.occupancyPct)} %` },
    { key: 'calls', label: 'Appels', value: (c) => num(c.calls) },
    { key: 'agents', label: 'Agents', value: (c) => num(c.agents) },
    { key: 'breaks', label: 'Pauses + déjeuner', value: (c) => fmt((c.onPause ?? 0) + (c.onLunch ?? 0), 1) },
]
const mode = ref('wait')
const currentMode = computed(() => MODES.find((m) => m.key === mode.value))

const LEVELS = {
    short: { cell: 'bg-red-100 text-red-800', swatch: 'bg-red-100 ring-1 ring-red-300', label: "Manque d'agents" },
    ok: { cell: 'bg-emerald-50 text-emerald-900', swatch: 'bg-emerald-50 ring-1 ring-emerald-300', label: 'Équilibré' },
    over: { cell: 'bg-blue-50 text-blue-900', swatch: 'bg-blue-50 ring-1 ring-blue-300', label: "Trop d'agents" },
    none: { cell: 'bg-white text-gray-400 ring-1 ring-inset ring-gray-100', swatch: 'bg-white ring-1 ring-gray-200', label: 'Moins de 2 appels' },
    closed: { cell: 'hatched text-gray-400', swatch: 'hatched ring-1 ring-gray-200', label: 'Fermé' },
    future: { cell: 'bg-gray-50', swatch: 'bg-gray-50 ring-1 ring-gray-200', label: 'Plus tard' },
}
const hasFigures = (c) => c && c.calls !== undefined
const slots = computed(() => data.value?.slots ?? [])
const rows = computed(() => (data.value?.rows ?? []).map((r) => ({ ...r, bySlot: Object.fromEntries(r.cells.map((c) => [c.slot, c])) })))
const usedLevels = computed(() => {
    const seen = new Set(rows.value.flatMap((r) => r.cells.map((c) => c.level)))
    return Object.entries(LEVELS).filter(([key]) => seen.has(key) || ['short', 'ok', 'over'].includes(key))
})
const slotEnd = (slot) => {
    const [h, m] = slot.split(':').map(Number)
    const t = h * 60 + m + 30
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
}
const details = (c) => [
    ['Appels', num(c.calls)],
    ['Décrochés', num(c.answered)],
    ['Non décrochés', `${fmt(c.notAnsweredPct)} %`],
    ['Attente moyenne avant décroché', mmss(c.avgWaitSec)],
    [`Attente de plus de ${longWait.value} min`, `${num(c.longWaits)} (${fmt(c.longWaitPct, 1)} %)`],
    ['Agents au travail', num(c.agents)],
    ['En appel ou pause après appel', num(c.busy)],
    ['En pause', num(c.onPause)],
    ['En déjeuner', num(c.onLunch)],
    ['Occupation', `${fmt(c.occupancyPct)} %`],
]

// ─── Évolution (7 derniers jours et semaines) ─────────────────────────────────
const evoWeekday = ref(0)
const evoWeekdays = computed(() => {
    const present = new Set((data.value?.evolution ?? []).map((d) => d.weekday))
    return [...present]
        .sort((a, b) => a - b)
        .map((n) => {
            const label = new Date(2024, 0, n).toLocaleDateString('fr-FR', { weekday: 'long' })
            return { n, label: label.charAt(0).toUpperCase() + label.slice(1) }
        })
})
watch(evoWeekdays, (list) => {
    if (evoWeekday.value && !list.some((w) => w.n === evoWeekday.value)) evoWeekday.value = 0
})
const showEvolution = computed(() => ['week', 'weeks'].includes(data.value?.period) && (data.value?.evolution ?? []).length > 0)
const evolution = computed(() => (data.value?.evolution ?? []).filter((d) => !evoWeekday.value || d.weekday === evoWeekday.value))
const evoCategories = computed(() => evolution.value.map((d) => shortDate(d.date)))
const evoTitles = computed(() => evolution.value.map((d) => longDate(d.date)))
const evoCharts = computed(() => [
    {
        title: 'Appels',
        format: (v) => fmt(v),
        series: [
            { name: 'Reçus', color: '#111827', data: evolution.value.map((d) => d.calls) },
            { name: 'Décrochés', color: '#9ca3af', data: evolution.value.map((d) => d.answered) },
        ],
    },
    {
        title: "Heures d'agents",
        format: (v) => `${fmt(v)} h`,
        series: [
            { name: 'Au travail', color: '#111827', data: evolution.value.map((d) => d.agentHours) },
            { name: 'Occupés', color: '#9ca3af', data: evolution.value.map((d) => d.busyHours) },
        ],
    },
    {
        title: `Clients ayant attendu plus de ${longWait.value} min`,
        format: (v) => fmt(v),
        series: [{ name: 'Clients', color: '#111827', data: evolution.value.map((d) => d.longWaits) }],
    },
])
</script>

<template>
    <Head title="Affluence et effectifs" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Affluence et effectifs' }]" />

            <FilterBar :has-active-filters="preset !== 'today' || team !== 'all'" @reset="(preset = 'today'), (team = 'all')">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500">Période</span>
                        <button
                            v-for="p in PERIODS"
                            :key="p.key"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="preset === p.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="preset = p.key"
                        >
                            {{ p.label }}
                        </button>
                        <Input v-if="preset === 'day'" v-model="pickedDay" type="date" :max="iso(new Date())" class="ml-1.5 h-8 w-40" />
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500">Équipe</span>
                        <button
                            v-for="t in teams"
                            :key="t.id"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="team === t.id ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="team = t.id"
                        >
                            {{ t.name }}
                        </button>
                    </div>
                </div>
            </FilterBar>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── 1. Grille par demi-heure ───────────────────────────────── -->
            <section v-if="data || (loading && !error)" class="rounded-lg border border-gray-200 bg-white">
                <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">Par demi-heure</h2>
                    <span v-if="data" class="text-xs text-gray-500">
                        <template v-if="data.period === 'weeks'">Moyenne par jour, du {{ shortDate(data.from) }} au {{ shortDate(data.to) }}</template>
                        <template v-else-if="data.period === 'week'">Du {{ shortDate(data.from) }} au {{ shortDate(data.to) }}</template>
                    </span>
                    <span v-if="live" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        En direct
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <button
                            v-for="m in MODES"
                            :key="m.key"
                            type="button"
                            class="h-7 rounded-md px-2.5 text-xs font-medium"
                            :class="mode === m.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="mode = m.key"
                        >
                            {{ m.label }}
                        </button>
                    </div>
                </header>

                <div v-if="!data" class="p-4"><div class="h-40 animate-pulse rounded bg-gray-50" /></div>
                <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                    <table class="w-full border-separate border-spacing-0.5 px-3 py-2 text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th class="sticky left-0 z-10 bg-white" />
                                <th v-for="s in slots" :key="s" class="min-w-10 px-0.5 pb-1 text-center font-normal tabular-nums">{{ s }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.key">
                                <td class="sticky left-0 z-10 bg-white pr-3 whitespace-nowrap">
                                    <span class="font-medium text-gray-900">{{ r.label }}</span>
                                    <span v-if="r.days > 1" class="ml-1.5 text-xs text-gray-400" :title="r.dates.map(longDate).join('\n')">{{ r.days }} j</span>
                                </td>
                                <td v-for="s in slots" :key="s" class="p-0">
                                    <Tooltip v-if="hasFigures(r.bySlot[s])">
                                        <TooltipTrigger as-child>
                                            <div
                                                class="flex h-9 cursor-default items-center justify-center rounded-sm text-sm font-semibold tabular-nums"
                                                :class="[(LEVELS[r.bySlot[s].level] ?? LEVELS.none).cell, mode === 'wait' && r.bySlot[s].level !== 'short' && 'text-gray-600']"
                                            >
                                                {{ currentMode.value(r.bySlot[s]) }}
                                            </div>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p class="mb-1 font-semibold">{{ r.label }}, {{ s }}–{{ slotEnd(s) }}</p>
                                            <table class="text-xs">
                                                <tbody>
                                                    <tr v-for="[label, value] in details(r.bySlot[s])" :key="label">
                                                        <td class="pr-3 opacity-70">{{ label }}</td>
                                                        <td class="text-right tabular-nums">{{ value }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </TooltipContent>
                                    </Tooltip>
                                    <div
                                        v-else
                                        class="h-9 rounded-sm"
                                        :class="(LEVELS[r.bySlot[s]?.level] ?? LEVELS.future).cell"
                                        :title="r.bySlot[s]?.level === 'closed' ? 'Fermé' : undefined"
                                    />
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td :colspan="slots.length + 1" class="px-4 py-6 text-center text-sm text-gray-400">Aucune donnée sur la période</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Légende et aide -->
                <div class="space-y-2 border-t border-gray-100 px-4 py-3 text-xs text-gray-500">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span v-for="[key, l] in usedLevels" :key="key" class="inline-flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm" :class="l.swatch" />
                            {{ l.label }}
                        </span>
                    </div>
                    <p>
                        Manque d'agents : au moins 2 % des clients décrochés après plus de {{ longWait }} minutes d'attente (l'accueil et l'attente forcée, environ 3 minutes, sont voulus).
                        Trop d'agents : aucune attente anormale et des agents en appel moins de la moitié du temps. Fermé : hors des horaires.
                    </p>
                </div>
            </section>

            <!-- ─── 2. Évolution jour par jour ────────────────────────────── -->
            <section v-if="showEvolution" class="rounded-lg border border-gray-200 bg-white">
                <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">Évolution jour par jour</h2>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <button
                            v-for="w in [{ n: 0, label: 'Tous les jours' }, ...evoWeekdays]"
                            :key="w.n"
                            type="button"
                            class="h-7 rounded-md px-2.5 text-xs font-medium"
                            :class="evoWeekday === w.n ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="evoWeekday = w.n"
                        >
                            {{ w.label }}
                        </button>
                    </div>
                </header>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-3 xl:grid-cols-3">
                    <div v-for="c in evoCharts" :key="c.title" class="min-w-0">
                        <h3 class="text-xs font-medium text-gray-700">{{ c.title }}</h3>
                        <TrendChart :categories="evoCategories" :titles="evoTitles" :series="c.series" :format="c.format" :height="220" :ticks="6" />
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Créneaux fermés : hachures grises */
.hatched {
    background-color: #f9fafb;
    background-image: repeating-linear-gradient(135deg, #e5e7eb 0 1px, transparent 1px 6px);
}
</style>
