<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import FilterBar from '@/components/FilterBar.vue'
import TrendChart from '@/components/TrendChart.vue'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

/**
 * Affluence et effectifs (GET /staffing de voxnode118, calculé chaque nuit jusqu'à hier).
 *   1. Grille jours de semaine × demi-heures : moyennes par jour, couleur selon le niveau (manque / ok / trop)
 *   2. Évolution jour par jour : appels, heures d'agents, occupation
 * Agents = agents au travail en moyenne, pauses et déjeuners déduits ; nécessaires = en appel / occupation visée.
 */
const PERIODS = [4, 8, 12, 26]
const weeks = ref(8)
const team = ref('all')

const data = ref(null)
const loading = ref(false)
const error = ref(null)
let requestId = 0
async function load() {
    const id = ++requestId
    loading.value = true
    try {
        const res = await fetch(`/data/staffing?${new URLSearchParams({ weeks: weeks.value, team: team.value })}`, { headers: { Accept: 'application/json' } })
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
watch([weeks, team], load, { immediate: true })

// Gardées d'un chargement à l'autre : le filtre reste utilisable si un chargement échoue
const teams = ref([{ id: 'all', name: 'Tous' }])
watch(data, (d) => d?.teams?.length && (teams.value = d.teams))
const target = computed(() => data.value?.targetOccupancyPct ?? null)

// ─── Formats ──────────────────────────────────────────────────────────────────
const fmt = (v, digits = 0) =>
    v === null || v === undefined ? '—' : new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v).replace(/ | /g, ' ')
// « −4 », « +3 », « 0 » (signe moins typographique)
function signed(v) {
    const n = Math.round(v)
    if (n === 0) return '0'
    return n > 0 ? `+${n}` : `−${Math.abs(n)}`
}
const shortDate = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
const longDate = (d) => {
    const label = new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
    return label.charAt(0).toUpperCase() + label.slice(1)
}
const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s)

// ─── Grille ───────────────────────────────────────────────────────────────────
const MODES = [
    { key: 'gap', label: 'Écart', value: (c) => signed(c.gap) },
    { key: 'occupancy', label: 'Occupation', value: (c) => `${fmt(c.occupancyPct)} %` },
    { key: 'calls', label: 'Appels', value: (c) => fmt(c.calls) },
    { key: 'agents', label: 'Agents', value: (c) => fmt(c.agents) },
    { key: 'breaks', label: 'En pause + déjeuner', value: (c) => fmt((c.onPause ?? 0) + (c.onLunch ?? 0), 1) },
]
const mode = ref('gap')
const currentMode = computed(() => MODES.find((m) => m.key === mode.value))

const LEVELS = {
    short: { cell: 'bg-red-100 text-red-800', swatch: 'bg-red-100 ring-1 ring-red-300', label: "Manque d'agents" },
    ok: { cell: 'bg-emerald-50 text-emerald-800', swatch: 'bg-emerald-50 ring-1 ring-emerald-300', label: 'Correct' },
    over: { cell: 'bg-blue-50 text-blue-800', swatch: 'bg-blue-50 ring-1 ring-blue-300', label: "Trop d'agents" },
    none: { cell: 'bg-gray-50 text-gray-400', swatch: 'bg-gray-50 ring-1 ring-gray-200', label: 'Moins de 2 appels' },
}
const slots = computed(() => data.value?.slots ?? [])
const rows = computed(() =>
    [...(data.value?.weekdays ?? [])]
        .sort((a, b) => a.weekday - b.weekday)
        .map((w) => ({ ...w, bySlot: Object.fromEntries(w.cells.map((c) => [c.slot, c])) })),
)
const slotEnd = (slot) => {
    const [h, m] = slot.split(':').map(Number)
    const t = h * 60 + m + 30
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
}
const details = (c) => [
    ['Appels', fmt(c.calls, 1)],
    ['Décrochés', fmt(c.answered, 1)],
    ['Non décrochés', `${fmt(c.notAnsweredPct)} %`],
    ['Agents au travail', fmt(c.agents, 1)],
    ['En appel', fmt(c.busy, 1)],
    ['En pause', fmt(c.onPause, 1)],
    ['En déjeuner', fmt(c.onLunch, 1)],
    ['Occupation', `${fmt(c.occupancyPct)} %`],
    ['Nécessaires', fmt(c.needed, 1)],
    ['Écart', `${signed(c.gap)} (${fmt(c.gap, 1)})`],
]

// ─── Évolution ────────────────────────────────────────────────────────────────
const evoWeekday = ref(0)
const evoWeekdays = computed(() => {
    const present = new Set((data.value?.evolution ?? []).map((d) => d.weekday))
    return [...present].sort((a, b) => a - b).map((n) => ({ n, label: capitalize(new Date(2024, 0, n).toLocaleDateString('fr-FR', { weekday: 'long' })) }))
})
watch(evoWeekdays, (list) => {
    if (evoWeekday.value && !list.some((w) => w.n === evoWeekday.value)) evoWeekday.value = 0
})
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
            { name: 'En appel', color: '#9ca3af', data: evolution.value.map((d) => d.talkHours) },
        ],
    },
    {
        title: 'Occupation',
        format: (v) => `${fmt(v)} %`,
        series: [{ name: 'Occupation', color: '#111827', data: evolution.value.map((d) => d.occupancyPct) }],
    },
])
</script>

<template>
    <Head title="Affluence et effectifs" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Affluence et effectifs' }]" />

            <FilterBar :has-active-filters="weeks !== 8 || team !== 'all'" @reset="(weeks = 8), (team = 'all')">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500">Période</span>
                        <button
                            v-for="w in PERIODS"
                            :key="w"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="weeks === w ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="weeks = w"
                        >
                            {{ w }} semaines
                        </button>
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
                    <span v-if="data" class="text-xs text-gray-500">Du {{ longDate(data.from).toLowerCase() }} au {{ longDate(data.to).toLowerCase() }}</span>
                </div>
            </FilterBar>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── 1. Grille jours de semaine × demi-heures ──────────────── -->
            <section v-if="data || (loading && !error)" class="rounded-lg border border-gray-200 bg-white">
                <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">Par jour de semaine et par demi-heure</h2>
                    <span class="text-xs text-gray-500">Moyenne par jour</span>
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

                <div v-if="!data" class="p-4"><div class="h-56 animate-pulse rounded bg-gray-50" /></div>
                <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                    <table class="w-full border-separate border-spacing-0.5 px-3 py-2 text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th class="sticky left-0 z-10 bg-white" />
                                <th v-for="s in slots" :key="s" class="min-w-11 px-0.5 pb-1 text-center font-normal tabular-nums">{{ s }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.weekday">
                                <td class="sticky left-0 z-10 bg-white pr-3 whitespace-nowrap">
                                    <span class="font-medium text-gray-900">{{ capitalize(r.name) }}</span>
                                    <span class="ml-1.5 text-xs text-gray-400" :title="r.dates.map(longDate).join('\n')">{{ r.days }} j</span>
                                </td>
                                <td v-for="s in slots" :key="s" class="p-0">
                                    <Tooltip v-if="r.bySlot[s]">
                                        <TooltipTrigger as-child>
                                            <div
                                                class="flex h-9 cursor-default items-center justify-center rounded-sm text-sm font-semibold tabular-nums"
                                                :class="(LEVELS[r.bySlot[s].level] ?? LEVELS.none).cell"
                                            >
                                                {{ currentMode.value(r.bySlot[s]) }}
                                            </div>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p class="mb-1 font-semibold">{{ capitalize(r.name) }}, {{ s }}–{{ slotEnd(s) }}</p>
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
                                    <div v-else class="h-9 rounded-sm bg-gray-50/50" />
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
                        <span v-for="(l, key) in LEVELS" :key="key" class="inline-flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm" :class="l.swatch" />
                            {{ l.label }}
                        </span>
                        <span v-if="target !== null" class="ml-auto">
                            Occupation visée : <span class="font-medium text-gray-700">{{ target }} %</span>
                            <Link href="/sip-server/settings#trafic" class="ml-1.5 underline hover:text-gray-700">Modifier</Link>
                        </span>
                    </div>
                    <p>
                        Occupation = part du temps de travail des agents passée en appel. Nécessaires = agents en appel / occupation visée ({{ target ?? '—' }} %).
                        Rouge : plus de 85 % d'occupation (on manque d'agents). Bleu : moins de 50 % et au moins 2 agents en trop.
                        Non décrochés : appels raccrochés avant un agent (accueil, attente forcée ou file) ; il y en a toujours une part, même avec assez d'agents.
                        Calculé chaque nuit jusqu'à hier.
                    </p>
                </div>
            </section>

            <!-- ─── 2. Évolution jour par jour ────────────────────────────── -->
            <section v-if="data" class="rounded-lg border border-gray-200 bg-white">
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
                <p v-if="!evolution.length" class="px-4 py-6 text-center text-sm text-gray-400">Aucun jour sur la période</p>
                <div v-else class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-3 xl:grid-cols-3">
                    <div v-for="c in evoCharts" :key="c.title" class="min-w-0">
                        <h3 class="text-xs font-medium text-gray-700">{{ c.title }}</h3>
                        <TrendChart :categories="evoCategories" :titles="evoTitles" :series="c.series" :format="c.format" :height="220" />
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
