<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TrendChart from '@/components/TrendChart.vue'
import { Input } from '@/components/ui/input'
import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, ChevronUp, Info } from 'lucide-vue-next'
import {
    addDays, addSums, daysBetween, fmt, formatRange, iso, metrics, parseIso, variation, weekStart,
} from '@/lib/performance'

// ─── Période ──────────────────────────────────────────────────────────────────
const PRESETS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: '7d', label: '7 derniers jours' },
    { key: 'this_month', label: 'Mois en cours' },
    { key: 'last_month', label: 'Mois précédent' },
    { key: 'custom', label: 'Personnalisée' },
]

const preset = ref('last_month')
const customFrom = ref(iso(addDays(new Date(), -30)))
const customTo = ref(iso(new Date()))
const compare = ref(true)

const period = computed(() => {
    const today = new Date()
    const y = today.getFullYear()
    const m = today.getMonth()
    switch (preset.value) {
        case 'today':
            return { from: iso(today), to: iso(today) }
        case 'yesterday':
            return { from: iso(addDays(today, -1)), to: iso(addDays(today, -1)) }
        case '7d':
            return { from: iso(addDays(today, -6)), to: iso(today) }
        case 'this_month':
            return { from: iso(new Date(y, m, 1)), to: iso(today) }
        case 'last_month':
            return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) }
        default:
            return { from: customFrom.value, to: customTo.value }
    }
})

// Période de comparaison : mois précédent pour les mois, sinon la même durée juste avant
const previousPeriod = computed(() => {
    const { from, to } = period.value
    const f = parseIso(from)
    if (preset.value === 'last_month') {
        return { from: iso(new Date(f.getFullYear(), f.getMonth() - 1, 1)), to: iso(new Date(f.getFullYear(), f.getMonth(), 0)) }
    }
    if (preset.value === 'this_month') {
        const t = parseIso(to)
        const lastDay = new Date(t.getFullYear(), t.getMonth(), 0).getDate()
        return {
            from: iso(new Date(f.getFullYear(), f.getMonth() - 1, 1)),
            to: iso(new Date(t.getFullYear(), t.getMonth() - 1, Math.min(t.getDate(), lastDay))),
        }
    }
    const length = daysBetween(from, to)
    return { from: iso(addDays(f, -length)), to: iso(addDays(f, -1)) }
})

const periodValid = computed(() => period.value.from && period.value.to && period.value.from <= period.value.to)

// ─── Chargement ───────────────────────────────────────────────────────────────
const data = ref(null)
const loading = ref(false)
const error = ref(null)

async function load() {
    if (!periodValid.value) return
    loading.value = true
    error.value = null
    expandedAgent.value = null
    const params = new URLSearchParams(period.value)
    if (compare.value) {
        params.set('prev_from', previousPeriod.value.from)
        params.set('prev_to', previousPeriod.value.to)
    }
    try {
        const res = await fetch(`/data/callcenter-performance?${params}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

// ─── Centres ──────────────────────────────────────────────────────────────────
// Sélection : les centres suivis (config) cochés par défaut, les autres décochés.
// overrides garde les choix manuels d'un chargement à l'autre.
const overrides = ref({})
const isSelected = (c) => overrides.value[c.id] ?? c.tracked
const selected = computed(() => (data.value?.centers ?? []).filter(isSelected).map((c) => c.id))
function toggleCenter(c) {
    overrides.value = { ...overrides.value, [c.id]: !isSelected(c) }
}

const centersById = computed(() => Object.fromEntries((data.value?.centers ?? []).map((c) => [c.id, c])))

const centers = computed(() =>
    (data.value?.centers ?? [])
        .filter((c) => selected.value?.includes(c.id))
        .map((c) => {
            const prev = data.value.previous?.find((p) => p.id === c.id)
            return { ...c, m: metrics(c), prev: prev ? metrics(prev) : null }
        }),
)

const totals = computed(() => metrics(addSums(centers.value)))
const previousTotals = computed(() => {
    if (!data.value?.previous) return null
    const rows = data.value.previous.filter((p) => selected.value?.includes(p.id))
    return rows.length ? metrics(addSums(rows)) : null
})

const costEstimated = computed(() => centers.value.some((c) => c.cost_estimated))
const costMissing = computed(() => centers.value.some((c) => c.cost === null))

// ─── KPI ──────────────────────────────────────────────────────────────────────
// better : sens dans lequel une hausse est positive
const KPIS = [
    { key: 'calls', label: 'Appels', format: fmt.int, better: 'up' },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v), better: 'up' },
    { key: 'margin', label: 'Marge après coût', format: (v) => fmt.eur(v), better: 'up' },
    { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v), better: 'up' },
    { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), better: 'up' },
    { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), better: 'down' },
]

function variationClass(v, better) {
    if (v === null || Math.abs(v) < 0.005) return 'text-gray-500'
    return (v > 0) === (better === 'up') ? 'text-green-700' : 'text-red-700'
}

// ─── Comparatif détaillé ──────────────────────────────────────────────────────
// best : 'max' ou 'min' = valeur mise en avant quand plusieurs centres sont affichés
const TABLE = [
    {
        title: 'Activité',
        rows: [
            { key: 'calls', label: 'Appels', format: fmt.int },
            { key: 'agents', label: 'Agents actifs', format: fmt.int },
            { key: 'agent_days', label: 'Journées-agent actives', format: fmt.int },
            { key: 'calls_per_agent_day', label: 'Appels par journée-agent', format: (v) => fmt.dec(v), best: 'max' },
        ],
    },
    {
        title: 'Durées moyennes par appel',
        rows: [
            { key: 'avg_total', label: 'Durée totale', format: fmt.dur },
            { key: 'avg_wait', label: 'Attente initiale', format: fmt.dur, secondary: true },
            { key: 'avg_agent', label: 'Durée agent', format: fmt.dur },
            { key: 'avg_talk', label: 'dont conversation', format: fmt.dur, secondary: true, indent: true },
            { key: 'avg_pause', label: 'dont pause', format: fmt.dur, secondary: true, indent: true },
            { key: 'avg_transfer', label: 'Durée transfert', format: fmt.dur },
            { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), best: 'min' },
            { key: 'transfer_ratio', label: 'Ratio transfert', format: (v) => fmt.pct(v), best: 'max' },
            { key: 'agent_hours', label: 'Temps agent total', format: fmt.hours, secondary: true },
        ],
    },
    {
        title: 'Financier',
        rows: [
            { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
            { key: 'payout_per_call', label: 'Payout par appel', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'cost', label: 'Coût du centre', format: (v) => fmt.eur(v) },
            { key: 'margin', label: 'Marge après coût', format: (v) => fmt.eur(v), best: 'max' },
            { key: 'margin_rate', label: 'Taux de marge', format: (v) => fmt.pct(v), best: 'max' },
            { key: 'payout_over_cost', label: 'Payout / coût', format: fmt.mult, best: 'max', secondary: true },
            { key: 'cost_per_call', label: 'Coût par appel', format: (v) => fmt.eur(v, 2), best: 'min', secondary: true },
            { key: 'margin_per_call', label: 'Marge par appel', format: (v) => fmt.eur(v, 2), best: 'max', secondary: true },
            { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v), best: 'max' },
        ],
    },
]

const showSecondary = ref(false)

function bestId(key, direction) {
    if (!direction || centers.value.length < 2) return null
    const values = centers.value.filter((c) => c.m[key] !== null)
    if (values.length < 2) return null
    const pick = values.reduce((a, b) => ((direction === 'max' ? b.m[key] > a.m[key] : b.m[key] < a.m[key]) ? b : a))
    return pick.id
}

// Indicateurs mis en avant dans les cartes des centres
const CARD_ROWS = [
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'agents', label: 'Agents actifs', format: fmt.int },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'cost', label: 'Coût', format: (v) => fmt.eur(v) },
    { key: 'margin', label: 'Marge', format: (v) => fmt.eur(v) },
    { key: 'margin_rate', label: 'Taux de marge', format: (v) => fmt.pct(v), best: 'max' },
    { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), best: 'max' },
    { key: 'transfer_ratio', label: 'Ratio transfert', format: (v) => fmt.pct(v), best: 'max' },
]

// ─── Évolution ────────────────────────────────────────────────────────────────
const TREND_METRICS = [
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v) },
    { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v) },
    { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v, 0) },
]

const trendMetric = ref('payout')
const granularity = ref('auto')
const trendFormat = computed(() => TREND_METRICS.find((t) => t.key === trendMetric.value).format)

const bucketByWeek = computed(() =>
    granularity.value === 'week' || (granularity.value === 'auto' && daysBetween(period.value.from, period.value.to) > 62),
)

// Regroupe des lignes journalières (date + sommes) par jour ou semaine sur toute la période
function buildBuckets(rows) {
    const keyOf = (date) => (bucketByWeek.value ? weekStart(date) : date)
    const keys = []
    for (let d = parseIso(period.value.from); iso(d) <= period.value.to; d = addDays(d, 1)) {
        const k = keyOf(iso(d))
        if (keys[keys.length - 1] !== k) keys.push(k)
    }
    const grouped = Object.fromEntries(keys.map((k) => [k, []]))
    for (const r of rows) grouped[keyOf(r.date)]?.push(r)
    return keys.map((k) => ({ key: k, m: grouped[k].length ? metrics(addSums(grouped[k])) : null }))
}

function bucketLabel(key) {
    const d = parseIso(key)
    const label = d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
    return bucketByWeek.value ? `sem. ${label}` : label
}

// Valeur d'un bucket : 0 pour les volumes, vide pour les ratios sans activité
function bucketValue(b, key) {
    if (!b.m) return ['payout', 'calls'].includes(key) ? 0 : null
    return b.m[key]
}

const trend = computed(() => {
    const daily = data.value?.daily ?? []
    const perCenter = centers.value.map((c) => ({
        center: c,
        buckets: buildBuckets(daily.filter((r) => r.id === c.id)),
    }))
    return {
        categories: perCenter[0]?.buckets.map((b) => bucketLabel(b.key)) ?? [],
        series: perCenter.map(({ center, buckets }) => ({
            name: center.label,
            color: center.color,
            data: buckets.map((b) => {
                const v = bucketValue(b, trendMetric.value)
                return v === null ? null : Math.round(v * 10000) / 10000
            }),
        })),
    }
})

// ─── Composition d'un appel ───────────────────────────────────────────────────
const PHASES = [
    { key: 'avg_wait', label: 'Attente initiale', color: '#e87ba4' },
    { key: 'avg_agent', label: 'Agent', color: '#4a3aa7' },
    { key: 'avg_transfer', label: 'Transfert', color: '#008300' },
]
const maxAvgTotal = computed(() => Math.max(...centers.value.map((c) => c.m.avg_total ?? 0), 1))
const phaseWidth = (c, key) => `${(Math.max(c.m[key] ?? 0, 0) / maxAvgTotal.value) * 100}%`

// ─── Productivité ─────────────────────────────────────────────────────────────
const PRODUCTIVITY = [
    {
        key: 'payout_per_agent_hour',
        label: 'Payout par heure agent',
        hint: 'Ce que rapporte une heure passée par un agent en ligne.',
        format: (v) => fmt.eur(v, 2),
    },
    {
        key: 'payout_per_agent_day',
        label: 'Payout par journée-agent active',
        hint: 'Ce que rapporte un agent sur une journée où il a pris au moins un appel.',
        format: (v) => fmt.eur(v),
    },
]
const maxOf = (key) => Math.max(...centers.value.map((c) => c.m[key] ?? 0), 1)

// ─── Agents ───────────────────────────────────────────────────────────────────
const MIN_CALLS = 30
const agentFilters = reactive({ search: '', center: '', hideSmall: true })
const agentSort = reactive({ key: 'payout', dir: 'desc' })

const AGENT_COLUMNS = [
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'days', label: 'Jours actifs', format: fmt.int },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'payout_per_call', label: '€ / appel', format: (v) => fmt.eur(v, 2) },
    { key: 'payout_per_day', label: '€ / jour actif', format: (v) => fmt.eur(v) },
    { key: 'payout_per_agent_hour', label: '€ / h agent', format: (v) => fmt.eur(v, 2) },
    { key: 'avg_agent', label: 'Agent', format: fmt.dur },
    { key: 'avg_talk', label: 'Conv.', format: fmt.dur },
    { key: 'avg_pause', label: 'Pause', format: fmt.dur },
    { key: 'avg_transfer', label: 'Transfert', format: fmt.dur },
    { key: 'agent_ratio', label: 'Ratio agent', format: (v) => fmt.pct(v) },
]

const agents = computed(() => {
    const q = agentFilters.search.trim().toLowerCase()
    const rows = (data.value?.agents ?? [])
        .filter((a) => selected.value?.includes(a.callcenter_id))
        .filter((a) => !agentFilters.center || a.callcenter_id === Number(agentFilters.center))
        .filter((a) => !agentFilters.hideSmall || Number(a.calls) >= MIN_CALLS)
        .filter((a) => !q || [a.name, a.username].some((v) => v && v.toLowerCase().includes(q)))
        .map((a) => {
            const m = metrics(a)
            return {
                ...a,
                center: centersById.value[a.callcenter_id],
                m: { ...m, payout_per_day: m.days ? m.payout / m.days : null },
            }
        })

    const dir = agentSort.dir === 'asc' ? 1 : -1
    return rows.sort((a, b) => {
        const va = agentSort.key === 'name' ? (a.name ?? a.username ?? '') : a.m[agentSort.key]
        const vb = agentSort.key === 'name' ? (b.name ?? b.username ?? '') : b.m[agentSort.key]
        if (va === null) return 1
        if (vb === null) return -1
        return (va > vb ? 1 : va < vb ? -1 : 0) * dir
    })
})

const hiddenSmallAgents = computed(
    () => (data.value?.agents ?? []).filter((a) => selected.value?.includes(a.callcenter_id) && Number(a.calls) < MIN_CALLS).length,
)

function sortAgents(key) {
    if (agentSort.key === key) agentSort.dir = agentSort.dir === 'asc' ? 'desc' : 'asc'
    else Object.assign(agentSort, { key, dir: key === 'name' ? 'asc' : 'desc' })
}

// Détail quotidien d'un agent
const expandedAgent = ref(null)
const agentDaily = ref(null)
const agentDailyLoading = ref(false)
const agentMetric = ref('payout')

async function toggleAgent(agent) {
    if (expandedAgent.value === agent.id) {
        expandedAgent.value = null
        return
    }
    expandedAgent.value = agent.id
    agentDaily.value = null
    agentDailyLoading.value = true
    try {
        const params = new URLSearchParams(period.value)
        const res = await fetch(`/data/callcenter-performance/agents/${agent.id}?${params}`, { headers: { Accept: 'application/json' } })
        agentDaily.value = await res.json()
    } finally {
        agentDailyLoading.value = false
    }
}

const agentTrend = computed(() => {
    const agent = agents.value.find((a) => a.id === expandedAgent.value)
    if (!agent || !agentDaily.value) return null
    const buckets = buildBuckets(agentDaily.value)
    return {
        categories: buckets.map((b) => bucketLabel(b.key)),
        series: [{
            name: agent.name ?? agent.username,
            color: agent.center?.color ?? '#2a78d6',
            data: buckets.map((b) => {
                const v = bucketValue(b, agentMetric.value)
                return v === null ? null : Math.round(v * 10000) / 10000
            }),
        }],
        format: TREND_METRICS.find((t) => t.key === agentMetric.value).format,
    }
})

const agentTrendMetrics = TREND_METRICS.filter((t) => t.key !== 'payout_per_agent_day')

// Rechargement à chaque changement de période
watch([period, compare], load, { immediate: true, deep: true })
</script>

<template>
    <Head title="Performance call centers" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Performance call centers' }]" />

            <!-- ─── Filtres ─────────────────────────────────────────────────── -->
            <div class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex flex-wrap rounded-md border border-gray-200 p-0.5">
                        <button
                            v-for="p in PRESETS"
                            :key="p.key"
                            type="button"
                            class="rounded px-3 py-1.5 text-sm transition"
                            :class="preset === p.key ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'"
                            @click="preset = p.key"
                        >
                            {{ p.label }}
                        </button>
                    </div>
                    <template v-if="preset === 'custom'">
                        <Input v-model="customFrom" type="date" class="h-9 w-40" />
                        <span class="text-gray-400">→</span>
                        <Input v-model="customTo" type="date" class="h-9 w-40" />
                    </template>
                    <label class="ml-auto flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="compare" type="checkbox" class="rounded border-gray-300" />
                        Comparer à la période précédente
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="c in data?.centers ?? []"
                        :key="c.id"
                        type="button"
                        class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm transition"
                        :class="selected?.includes(c.id) ? 'border-gray-300 bg-white text-gray-900' : 'border-dashed border-gray-200 text-gray-400'"
                        @click="toggleCenter(c)"
                    >
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: selected?.includes(c.id) ? c.color : '#d1d5db' }" />
                        {{ c.label }}
                    </button>
                    <span class="ml-auto text-sm text-gray-500">
                        {{ formatRange(period.from, period.to) }}
                        <template v-if="compare"> · comparé au {{ formatRange(previousPeriod.from, previousPeriod.to) }}</template>
                    </span>
                </div>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <div v-if="loading && !data" class="grid grid-cols-2 gap-4 lg:grid-cols-6">
                <div v-for="i in 6" :key="i" class="h-28 animate-pulse rounded-lg border border-gray-100 bg-white" />
            </div>

            <div
                v-else-if="data && !centers.length"
                class="rounded-lg border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-500"
            >
                Aucun appel sur cette période pour les centres sélectionnés.
            </div>

            <div v-else-if="data" class="space-y-6 transition-opacity" :class="{ 'opacity-50': loading }">
                <!-- ─── Synthèse ────────────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                    <div v-for="k in KPIS" :key="k.key" class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ k.label }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ k.format(totals[k.key]) }}</div>
                        <div class="mt-1 flex min-h-5 items-center gap-1 text-xs">
                            <template v-if="previousTotals && variation(totals[k.key], previousTotals[k.key]) !== null">
                                <span
                                    class="flex items-center font-medium"
                                    :class="variationClass(variation(totals[k.key], previousTotals[k.key]), k.better)"
                                >
                                    <ArrowUp v-if="variation(totals[k.key], previousTotals[k.key]) > 0" class="h-3 w-3" />
                                    <ArrowDown v-else-if="variation(totals[k.key], previousTotals[k.key]) < 0" class="h-3 w-3" />
                                    {{ fmt.variation(variation(totals[k.key], previousTotals[k.key])) }}
                                </span>
                                <span class="text-gray-400">vs {{ k.format(previousTotals[k.key]) }}</span>
                            </template>
                            <span v-if="k.key === 'margin' && totals.cost_estimated" class="text-gray-400">coût estimé</span>
                            <span v-if="k.key === 'margin' && totals.cost === null" class="text-gray-400">coût non renseigné</span>
                        </div>
                    </div>
                </div>

                <!-- ─── Centres ─────────────────────────────────────────────── -->
                <div class="grid gap-4" :class="centers.length >= 3 ? 'md:grid-cols-2 xl:grid-cols-3' : 'md:grid-cols-2'">
                    <div v-for="c in centers" :key="c.id" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <div class="h-1" :style="{ background: c.color }" />
                        <div class="p-5">
                            <div class="flex items-baseline justify-between">
                                <h3 class="text-lg font-semibold text-gray-900">{{ c.label }}</h3>
                                <span v-if="c.label !== c.name" class="text-xs text-gray-400">{{ c.name }}</span>
                            </div>

                            <!-- Les deux indicateurs les plus parlants -->
                            <div class="mt-4 grid grid-cols-2 gap-4 border-b border-gray-100 pb-4">
                                <div>
                                    <div class="text-xs text-gray-500">Payout / journée-agent</div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="text-2xl font-bold tabular-nums text-gray-900">{{ fmt.eur(c.m.payout_per_agent_day) }}</span>
                                        <span v-if="bestId('payout_per_agent_day', 'max') === c.id" class="rounded bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium text-white">1er</span>
                                    </div>
                                    <div v-if="c.prev" class="mt-0.5 text-xs" :class="variationClass(variation(c.m.payout_per_agent_day, c.prev.payout_per_agent_day), 'up')">
                                        {{ fmt.variation(variation(c.m.payout_per_agent_day, c.prev.payout_per_agent_day)) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">Ratio temps agent</div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="text-2xl font-bold tabular-nums text-gray-900">{{ fmt.pct(c.m.agent_ratio) }}</span>
                                        <span v-if="bestId('agent_ratio', 'min') === c.id" class="rounded bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium text-white">1er</span>
                                    </div>
                                    <div v-if="c.prev" class="mt-0.5 text-xs" :class="variationClass(variation(c.m.agent_ratio, c.prev.agent_ratio), 'down')">
                                        {{ fmt.variation(variation(c.m.agent_ratio, c.prev.agent_ratio)) }}
                                    </div>
                                </div>
                            </div>

                            <dl class="mt-3 space-y-1.5 text-sm">
                                <div v-for="r in CARD_ROWS" :key="r.key" class="flex items-center justify-between gap-2">
                                    <dt class="text-gray-500">{{ r.label }}</dt>
                                    <dd class="flex items-center gap-1.5 tabular-nums text-gray-900">
                                        <span v-if="r.best && bestId(r.key, r.best) === c.id" class="h-1.5 w-1.5 rounded-full bg-gray-900" title="Meilleur centre sur cet indicateur" />
                                        {{ r.format(c.m[r.key]) }}
                                        <span v-if="r.key === 'cost' && c.cost_estimated" class="text-xs text-gray-400">est.</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- ─── Évolution ───────────────────────────────────────────── -->
                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-900">Évolution</h3>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="trendMetric" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm">
                                <option v-for="t in TREND_METRICS" :key="t.key" :value="t.key">{{ t.label }}</option>
                            </select>
                            <select v-model="granularity" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm">
                                <option value="auto">Auto</option>
                                <option value="day">Par jour</option>
                                <option value="week">Par semaine</option>
                            </select>
                        </div>
                    </div>
                    <TrendChart :categories="trend.categories" :series="trend.series" :format="trendFormat" :height="320" />
                </div>

                <!-- ─── Composition + productivité ──────────────────────────── -->
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <h3 class="font-semibold text-gray-900">Composition moyenne d'un appel</h3>
                        <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-600">
                            <span v-for="p in PHASES" :key="p.key" class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: p.color }" />
                                {{ p.label }}
                            </span>
                        </div>
                        <div class="mt-5 space-y-5">
                            <div v-for="c in centers" :key="c.id">
                                <div class="mb-1.5 flex items-baseline justify-between text-sm">
                                    <span class="font-medium text-gray-900">{{ c.label }}</span>
                                    <span class="tabular-nums text-gray-500">{{ fmt.dur(c.m.avg_total) }} au total</span>
                                </div>
                                <div class="flex h-7 gap-0.5">
                                    <div
                                        v-for="p in PHASES"
                                        :key="p.key"
                                        class="group relative flex items-center justify-center overflow-hidden text-[11px] font-medium text-white first:rounded-l last:rounded-r"
                                        :style="{ width: phaseWidth(c, p.key), background: p.color }"
                                        :title="`${p.label} : ${fmt.dur(c.m[p.key])}`"
                                    >
                                        <span v-if="(c.m[p.key] ?? 0) / maxAvgTotal > 0.14" class="truncate px-1">{{ fmt.dur(c.m[p.key]) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <h3 class="font-semibold text-gray-900">Productivité</h3>
                        <div class="mt-4 space-y-6">
                            <div v-for="p in PRODUCTIVITY" :key="p.key">
                                <div class="text-sm font-medium text-gray-900">{{ p.label }}</div>
                                <div class="mb-2 text-xs text-gray-500">{{ p.hint }}</div>
                                <div class="space-y-1.5">
                                    <div v-for="c in centers" :key="c.id" class="flex items-center gap-3 text-sm">
                                        <span class="w-24 shrink-0 truncate text-gray-600">{{ c.label }}</span>
                                        <div class="h-5 flex-1">
                                            <div
                                                class="h-full rounded-r"
                                                :style="{ width: `${((c.m[p.key] ?? 0) / maxOf(p.key)) * 100}%`, background: c.color }"
                                            />
                                        </div>
                                        <span class="w-24 shrink-0 text-right tabular-nums text-gray-900">{{ p.format(c.m[p.key]) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── Comparatif détaillé ─────────────────────────────────── -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex items-center justify-between px-5 py-4">
                        <h3 class="font-semibold text-gray-900">Comparatif détaillé</h3>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="showSecondary" type="checkbox" class="rounded border-gray-300" />
                            Tous les indicateurs
                        </label>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-100 bg-gray-50/60">
                                    <th class="px-5 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-400">Indicateur</th>
                                    <th v-for="c in centers" :key="c.id" class="px-5 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2 w-2 rounded-full" :style="{ background: c.color }" />
                                            {{ c.label }}
                                        </span>
                                    </th>
                                    <th v-if="centers.length > 1" class="px-5 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-400">Total</th>
                                </tr>
                            </thead>
                            <tbody v-for="section in TABLE" :key="section.title">
                                <tr>
                                    <td :colspan="centers.length + 2" class="px-5 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-gray-900">
                                        {{ section.title }}
                                    </td>
                                </tr>
                                <template v-for="r in section.rows" :key="r.key">
                                    <tr v-if="!r.secondary || showSecondary" class="border-b border-gray-50 hover:bg-gray-50/60">
                                        <td class="px-5 py-1.5 text-gray-600" :class="{ 'pl-9 text-gray-500': r.indent }">
                                            {{ r.label }}
                                            <span v-if="['cost', 'margin', 'margin_rate'].includes(r.key) && costEstimated" class="text-xs text-gray-400">(estimé)</span>
                                        </td>
                                        <td
                                            v-for="c in centers"
                                            :key="c.id"
                                            class="px-5 py-1.5 text-right tabular-nums"
                                            :class="bestId(r.key, r.best) === c.id ? 'font-semibold text-gray-900' : 'text-gray-700'"
                                        >
                                            <span v-if="bestId(r.key, r.best) === c.id" class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-gray-900 align-middle" />
                                            {{ r.format(c.m[r.key]) }}
                                        </td>
                                        <td v-if="centers.length > 1" class="px-5 py-1.5 text-right tabular-nums text-gray-500">{{ r.format(totals[r.key]) }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-5 py-3 text-xs text-gray-400">
                        <span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-gray-900 align-middle" />
                        meilleur centre sur l'indicateur. Ratio temps agent : plus il est bas, moins un appel mobilise l'agent.
                    </p>
                </div>

                <!-- ─── Agents ──────────────────────────────────────────────── -->
                <div class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <h3 class="mr-auto font-semibold text-gray-900">Agents</h3>
                        <Input v-model="agentFilters.search" type="text" placeholder="Rechercher un agent" class="h-8 w-48" />
                        <select v-model="agentFilters.center" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm">
                            <option value="">Tous les centres</option>
                            <option v-for="c in centers" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="agentFilters.hideSmall" type="checkbox" class="rounded border-gray-300" />
                            Masquer moins de {{ MIN_CALLS }} appels
                            <span v-if="agentFilters.hideSmall && hiddenSmallAgents" class="text-gray-400">({{ hiddenSmallAgents }})</span>
                        </label>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-wide text-gray-400">
                                    <th :colspan="7" />
                                    <th colspan="4" class="border-b border-gray-100 px-2 pb-1 text-center font-medium">Durées moyennes par appel</th>
                                    <th />
                                </tr>
                                <tr class="border-y border-gray-100 bg-gray-50/60 text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <th class="cursor-pointer select-none whitespace-nowrap px-4 py-2 text-left hover:text-gray-700" @click="sortAgents('name')">
                                        Agent
                                        <ChevronUp v-if="agentSort.key === 'name' && agentSort.dir === 'asc'" class="inline h-3 w-3" />
                                        <ChevronDown v-else-if="agentSort.key === 'name'" class="inline h-3 w-3" />
                                    </th>
                                    <th
                                        v-for="col in AGENT_COLUMNS"
                                        :key="col.key"
                                        class="cursor-pointer select-none whitespace-nowrap px-2 py-2 text-right hover:text-gray-700"
                                        :class="{ 'text-gray-700': agentSort.key === col.key }"
                                        @click="sortAgents(col.key)"
                                    >
                                        {{ col.label }}
                                        <ChevronUp v-if="agentSort.key === col.key && agentSort.dir === 'asc'" class="inline h-3 w-3" />
                                        <ChevronDown v-else-if="agentSort.key === col.key" class="inline h-3 w-3" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="a in agents" :key="`${a.id}-${a.callcenter_id}`">
                                    <tr
                                        class="cursor-pointer border-b border-gray-50 hover:bg-gray-50/60"
                                        :class="{ 'bg-gray-50': expandedAgent === a.id }"
                                        @click="toggleAgent(a)"
                                    >
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <div class="flex items-center gap-2">
                                                <ChevronDown v-if="expandedAgent === a.id" class="h-3.5 w-3.5 text-gray-400" />
                                                <ChevronRight v-else class="h-3.5 w-3.5 text-gray-400" />
                                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: a.center?.color }" :title="a.center?.label" />
                                                <span class="font-medium text-gray-900">{{ a.name ?? a.username ?? `#${a.id}` }}</span>
                                                <span v-if="a.name && a.username && a.name !== a.username" class="font-mono text-xs text-gray-400">{{ a.username }}</span>
                                            </div>
                                        </td>
                                        <td
                                            v-for="col in AGENT_COLUMNS"
                                            :key="col.key"
                                            class="whitespace-nowrap px-2 py-2 text-right tabular-nums"
                                            :class="col.key === 'calls' && a.m.calls < MIN_CALLS ? 'text-amber-700' : 'text-gray-700'"
                                        >
                                            {{ col.format(a.m[col.key]) }}
                                        </td>
                                    </tr>
                                    <tr v-if="expandedAgent === a.id">
                                        <td :colspan="AGENT_COLUMNS.length + 1" class="border-b border-gray-100 bg-gray-50/40 px-5 py-4">
                                            <div class="mb-1 flex items-center justify-between">
                                                <span class="text-sm text-gray-600">
                                                    {{ a.center?.label }} · {{ formatRange(period.from, period.to) }}
                                                </span>
                                                <select v-model="agentMetric" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm" @click.stop>
                                                    <option v-for="t in agentTrendMetrics" :key="t.key" :value="t.key">{{ t.label }}</option>
                                                </select>
                                            </div>
                                            <div v-if="agentDailyLoading" class="flex h-56 items-center justify-center text-sm text-gray-400">Chargement...</div>
                                            <TrendChart
                                                v-else-if="agentTrend"
                                                :categories="agentTrend.categories"
                                                :series="agentTrend.series"
                                                :format="agentTrend.format"
                                                :height="230"
                                            />
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!agents.length">
                                    <td :colspan="AGENT_COLUMNS.length + 1" class="px-5 py-8 text-center text-sm text-gray-400">Aucun agent</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ─── Notes ───────────────────────────────────────────────── -->
                <div class="flex gap-2 text-xs leading-relaxed text-gray-500">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div class="space-y-1">
                        <p>Journée-agent : un agent qui a pris au moins un appel dans la journée (heure de Paris). Ce n'est pas une présence RH.</p>
                        <p v-if="costEstimated">Période partielle : le coût mensuel est réparti au prorata des jours, la marge est une estimation.</p>
                        <p v-if="costMissing">Pas de coût renseigné pour certains centres : marge non calculée (config/callcenters.php).</p>
                        <p v-if="totals.anomalies">{{ fmt.int(totals.anomalies) }} appel(s) avec une durée totale inférieure à agent + transfert : attente négative, laissée telle quelle dans les calculs.</p>
                        <p v-if="Number(data.unattributed?.calls)">
                            {{ fmt.int(Number(data.unattributed.calls)) }} appels sans call center sur la période ({{ fmt.eur(Number(data.unattributed.payout)) }}),
                            non comptés ici.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
