<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import VueApexCharts from 'vue3-apexcharts'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import FilterBar from '@/components/FilterBar.vue'
import { Input } from '@/components/ui/input'
import { ArrowLeftRight, PhoneOff, X } from 'lucide-vue-next'
import { addDays, iso } from '@/lib/performance'

/**
 * Pilotage des effectifs (GET /pilotage de voxnode118).
 *   1. Ce qu'il faut retenir (phrases de l'API) et à faire (recommandations, sur plusieurs semaines)
 *   2. Heure par heure : une case par demi-heure, couleur = niveau, repère de cause dans les cases en manque ;
 *      au clic, le détail de la demi-heure sous la grille
 *   3. Journée type (7 derniers jours et semaines) : agents présents, occupés, en pause, et clients pénalisés
 *   4. Ce que vivent les clients : notre serveur, Voxnode, ensemble
 *   5. Évolution jour par jour (7 derniers jours et semaines)
 * Client pénalisé = perdu en attente d'un agent ou décroché après plus de longWaitMinutes ; l'accueil et
 * l'attente forcée sont voulus. Mêmes définitions que la page Attente et pertes.
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
        const res = await fetch(`/data/pilotage?${new URLSearchParams(query.value)}`, { headers: { Accept: 'application/json' } })
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

// Gardées d'un chargement à l'autre : le filtre reste utilisable si un chargement échoue
const teams = ref([{ id: 'all', name: 'Tous' }])
watch(data, (d) => d?.teams?.length && (teams.value = d.teams))
const longWait = computed(() => data.value?.longWaitMinutes ?? 8)
const live = computed(() => (data.value?.rows ?? []).some((r) => r.live))
const multiDay = computed(() => ['week', 'weeks'].includes(data.value?.period))

// Relu toutes les 5 min dès qu'une ligne est en direct
let timer = null
onMounted(() => (timer = setInterval(() => live.value && document.visibilityState === 'visible' && load(), 5 * 60 * 1000)))
onUnmounted(() => clearInterval(timer))

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
const slotEnd = (slot) => {
    const [h, m] = slot.split(':').map(Number)
    const t = h * 60 + m + 30
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
}

// ─── Recommandations ──────────────────────────────────────────────────────────
const RECO_TYPES = {
    reinforce: { label: 'Renforcer', class: 'text-red-700' },
    breaks: { label: 'Décaler des pauses', class: 'text-amber-700' },
    distribution: { label: 'Répartition', class: 'text-violet-700' },
    answering: { label: 'Décrochage', class: 'text-violet-700' },
    lighten: { label: 'Alléger', class: 'text-blue-700' },
}

// ─── Grille heure par heure ───────────────────────────────────────────────────
const MODES = [
    { key: 'free', label: 'Agents libres', value: (c) => fmt(c.free) },
    { key: 'suffered', label: 'Clients pénalisés', value: (c) => num(c.suffered) },
    { key: 'calls', label: 'Appels', value: (c) => num(c.calls) },
    { key: 'agents', label: 'Agents présents', value: (c) => num(c.agents) },
    { key: 'breaks', label: 'Pauses + déjeuner', value: (c) => fmt((c.onPause ?? 0) + (c.onLunch ?? 0), 1) },
    { key: 'wait', label: 'Vraie attente', value: (c) => mmss(c.avgQueueWaitSec) },
]
const mode = ref('free')
const currentMode = computed(() => MODES.find((m) => m.key === mode.value))

const LEVELS = {
    short: 'bg-red-100 text-red-900',
    ok: 'bg-emerald-50 text-emerald-950',
    over: 'bg-blue-50 text-blue-950',
    none: 'bg-white text-gray-500 ring-1 ring-inset ring-gray-100',
    closed: 'hatched',
    future: 'bg-gray-50/60',
}
// Repère de cause dans une case en manque (rien quand tout le monde était occupé)
const CAUSES = {
    misplaced: { icon: ArrowLeftRight, label: "agents libres sur l'autre plateforme" },
    not_answering: { icon: PhoneOff, label: 'agents qui ne décrochent pas' },
    unclear: { mark: '?', label: 'cause à préciser' },
}
const hasFigures = (c) => c && c.calls !== undefined
const slots = computed(() => data.value?.slots ?? [])
const rows = computed(() => (data.value?.rows ?? []).map((r) => ({ ...r, bySlot: Object.fromEntries(r.cells.map((c) => [c.slot, c])) })))

// Demi-heure sélectionnée (clic), gardée tant qu'elle existe dans les données rechargées
const selected = ref(null)
function select(row, slot) {
    selected.value = selected.value?.row === row.key && selected.value?.slot === slot ? null : { row: row.key, slot }
}
const isSelected = (row, slot) => selected.value?.row === row.key && selected.value?.slot === slot
const detail = computed(() => {
    if (!selected.value) return null
    const row = rows.value.find((r) => r.key === selected.value.row)
    const cell = row?.bySlot[selected.value.slot]
    if (!hasFigures(cell)) return null
    const span = `${cell.slot}–${slotEnd(cell.slot)}`
    const title = row.days > 1 ? `${row.label}, ${span} (moyenne de ${row.days} ${row.label.toLowerCase()}s)` : `${row.label}, ${span}`
    const clients = [
        ['Appels', num(cell.calls)],
        ['Décrochés', num(cell.answered)],
        ['Perdus', `${num(cell.lost)} (${fmt(cell.lostPct)} %)`],
        ["dont en attente d'un agent", num(cell.lostQueue)],
        // Le reste des perdus : seulement quand l'étape est connue pour tous les perdus de la case
        // (moyennes à une décimale sur plusieurs jours : tolérance de 0,05 ; lostMeasured absent ou 0 = inconnu)
        ...(cell.lostMeasured > 0 && Math.abs(cell.lostMeasured - cell.lost) < 0.05 ? [["dont avant l'attente d'un agent", num(+(cell.lost - cell.lostQueue).toFixed(1))]] : []),
        ['Attente moyenne avant décroché', mmss(cell.avgWaitSec)],
        ["Vraie attente d'un agent", cell.avgQueueWaitSec == null ? '—' : `${mmss(cell.avgQueueWaitSec)}${cell.queueWaitKnownPct != null && cell.queueWaitKnownPct < 100 ? ` (connue pour ${fmt(cell.queueWaitKnownPct)} % des appels)` : ''}`],
        [`Décrochés après plus de ${longWait.value} min`, num(cell.longWaits)],
    ]
    const agents = [
        ['Présents', num(cell.agents)],
        ['En appel ou pause après appel', num(cell.busy)],
        ['Libres', num(cell.free)],
        ['En pause', num(cell.onPause)],
        ['En déjeuner', num(cell.onLunch)],
        ['Appels sans réponse', num(cell.missed)],
    ]
    const m = cell.minutes
    const minutes = m
        ? `Minute par minute : ${m.waiting} min avec des clients en attente d'un agent, dont ${m.allBusy} tous les agents occupés, ${m.misplaced} agents libres sur l'autre plateforme, ${m.notAnswering} agents libres sur la même plateforme. Agents libres en moyenne : notre serveur ${num(m.freeServer)}, Voxnode ${num(m.freeVoxnode)}.`
        : null
    return { title, explanation: cell.explanation, clients, agents, minutes }
})
watch(data, () => {
    if (selected.value && !detail.value) selected.value = null
})

// ─── Journée type (7 derniers jours et semaines) ──────────────────────────────
const typicalKey = ref(null)
watch(rows, (list) => {
    if (!list.some((r) => r.key === typicalKey.value)) typicalKey.value = list[0]?.key ?? null
})
const typicalRow = computed(() => rows.value.find((r) => r.key === typicalKey.value) ?? null)
const AXIS_STYLE = { colors: '#6b7280', fontSize: '11px' }
const baseChart = (type, height) => ({
    chart: { type, height, toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false }, fontFamily: 'inherit', group: undefined },
    grid: { borderColor: '#f3f4f6', xaxis: { lines: { show: false } }, padding: { left: 8, right: 8 } },
    dataLabels: { enabled: false },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: '#374151' }, markers: { size: 5, shape: 'circle' }, itemMargin: { horizontal: 10 } },
})
// Agents : pauses + déjeuner en aire grise, présents et occupés en lignes ; l'écart entre les deux lignes = agents libres
const typicalAgents = computed(() => {
    const cells = slots.value.map((s) => typicalRow.value?.bySlot[s])
    const val = (c, f) => (hasFigures(c) ? f(c) : null)
    return {
        series: [
            { name: 'En pause + en déjeuner', type: 'area', data: cells.map((c) => val(c, (x) => +((x.onPause ?? 0) + (x.onLunch ?? 0)).toFixed(1))) },
            { name: 'Agents présents', type: 'line', data: cells.map((c) => val(c, (x) => x.agents)) },
            { name: 'En appel ou pause après appel', type: 'line', data: cells.map((c) => val(c, (x) => x.busy)) },
        ],
        options: {
            ...baseChart('line', 240),
            colors: ['#d1d5db', '#111827', '#6b7280'],
            stroke: { width: [0, 2, 2], curve: 'straight' },
            fill: { opacity: [0.6, 1, 1] },
            markers: { size: 0, hover: { size: 4 } },
            xaxis: { categories: slots.value, tickAmount: 12, labels: { rotate: 0, hideOverlappingLabels: true, style: AXIS_STYLE }, axisBorder: { color: '#e5e7eb' }, axisTicks: { show: false }, tooltip: { enabled: false } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: (v) => fmt(v), style: AXIS_STYLE } },
            tooltip: { shared: true, intersect: false, y: { formatter: (v) => (v == null ? '—' : num(v)) } },
        },
    }
})
// Clients pénalisés, sous le graphique des agents, mêmes demi-heures (pas de second axe)
const typicalSuffered = computed(() => {
    const cells = slots.value.map((s) => typicalRow.value?.bySlot[s])
    return {
        series: [{ name: 'Clients pénalisés', data: cells.map((c) => (hasFigures(c) ? c.suffered : null)) }],
        options: {
            ...baseChart('bar', 130),
            colors: ['#dc2626'],
            plotOptions: { bar: { columnWidth: '60%', borderRadius: 2 } },
            legend: { show: false },
            xaxis: { categories: slots.value, tickAmount: 12, labels: { rotate: 0, hideOverlappingLabels: true, style: AXIS_STYLE }, axisBorder: { color: '#e5e7eb' }, axisTicks: { show: false }, tooltip: { enabled: false } },
            yaxis: { min: 0, forceNiceScale: true, tickAmount: 2, labels: { formatter: (v) => fmt(v), style: AXIS_STYLE } },
            tooltip: { y: { formatter: (v) => (v == null ? '—' : num(v)) } },
        },
    }
})

// ─── Ce que vivent les clients ────────────────────────────────────────────────
const CLIENT_COLUMNS = [
    { key: 'server', label: 'Notre serveur', color: 'bg-gray-900' },
    { key: 'voxnode', label: 'Voxnode', color: 'bg-gray-300' },
    { key: 'all', label: 'Ensemble', total: true },
]
// Ensemble : sommes ; moyennes pondérées par le nombre d'appels concernés
const clientGroups = computed(() => {
    const c = data.value?.clients
    if (!c) return null
    const parts = [c.server, c.voxnode].filter(Boolean)
    const sum = (k) => parts.reduce((n, p) => n + (p[k] ?? 0), 0)
    const weighted = (k, w) => {
        const used = parts.filter((p) => p[k] != null && p[w])
        const weight = used.reduce((n, p) => n + p[w], 0)
        return weight ? used.reduce((n, p) => n + p[k] * p[w], 0) / weight : null
    }
    const all = parts.length
        ? {
              calls: sum('calls'),
              answered: sum('answered'),
              lost: sum('lost'),
              lostMeasured: sum('lostMeasured'),
              lostIntro: sum('lostIntro'),
              lostForced: sum('lostForced'),
              lostQueue: sum('lostQueue'),
              avgWaitBeforeAnswerSec: weighted('avgWaitBeforeAnswerSec', 'answered'),
              avgRealWaitSec: weighted('avgRealWaitSec', 'realWaitMeasured'),
              realWaitMeasured: sum('realWaitMeasured'),
              answeredWithin60Pct: weighted('answeredWithin60Pct', 'realWaitMeasured'),
          }
        : null
    return { server: c.server, voxnode: c.voxnode, all }
})
const stage = (g, k) => (!g || !g.lostMeasured ? '—' : fmt(g[k]))
const clientRows = computed(() => {
    const groups = clientGroups.value
    if (!groups) return []
    return [
        { label: 'Appels', value: (g) => fmt(g?.calls) },
        { label: 'Décrochés', value: (g) => fmt(g?.answered) },
        { label: 'Perdus', value: (g) => fmt(g?.lost) },
        // Notre serveur : l'accueil se joue pendant l'attente forcée, une seule ligne pour les deux
        { label: "dont avant l'attente d'un agent (accueil et attente forcée voulue)", sub: true, value: (g) => (!g || !g.lostMeasured ? '—' : fmt(g.lostIntro + g.lostForced)) },
        { label: "dont en attente d'un agent", sub: true, value: (g) => stage(g, 'lostQueue') },
        { label: 'Attente moyenne avant décroché (accueil et attente forcée compris)', separated: true, value: (g) => mmss(g?.avgWaitBeforeAnswerSec) },
        { label: "Vraie attente d'un agent", value: (g) => mmss(g?.avgRealWaitSec) },
        { label: "Décrochés en moins d'1 min de vraie attente", value: (g) => (g?.answeredWithin60Pct == null ? '—' : `${fmt(g.answeredWithin60Pct)} %`) },
    ]
})

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
const evolution = computed(() => (data.value?.evolution ?? []).filter((d) => !evoWeekday.value || d.weekday === evoWeekday.value))
// Clients pénalisés en barres, appels reçus en dessous : deux graphiques alignés plutôt qu'un second axe
const evoChart = (name, key, type, color, height) => ({
    series: [{ name, data: evolution.value.map((d) => d[key]) }],
    options: {
        ...baseChart(type, height),
        colors: [color],
        legend: { show: false },
        stroke: { width: type === 'line' ? 2 : 0, curve: 'straight' },
        markers: { size: 0, hover: { size: 4 } },
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 2 } },
        xaxis: {
            categories: evolution.value.map((d) => shortDate(d.date)),
            tickAmount: Math.min(evolution.value.length, 14),
            labels: { rotate: 0, hideOverlappingLabels: true, style: AXIS_STYLE },
            axisBorder: { color: '#e5e7eb' },
            axisTicks: { show: false },
            tooltip: { enabled: false },
        },
        yaxis: { min: 0, forceNiceScale: true, tickAmount: type === 'line' ? 2 : 4, labels: { formatter: (v) => fmt(v), style: AXIS_STYLE } },
        tooltip: { x: { formatter: (v, { dataPointIndex }) => longDate(evolution.value[dataPointIndex]?.date) }, y: { formatter: (v) => fmt(v) } },
    },
})
const evoSuffered = computed(() => evoChart('Clients pénalisés', 'suffered', 'bar', '#dc2626', 200))
const evoCalls = computed(() => evoChart('Appels reçus', 'calls', 'line', '#6b7280', 120))
// ApexCharts perd ses formateurs lors d'une mise à jour : on recrée les graphiques
const chartKey = computed(() => `${data.value?.from}-${data.value?.to}-${data.value?.team}-${typicalKey.value}-${evoWeekday.value}`)
</script>

<template>
    <Head title="Pilotage des effectifs" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Pilotage des effectifs' }]" />

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
            <div v-else-if="!data" class="h-64 animate-pulse rounded-lg bg-gray-50" />

            <template v-if="data">
                <!-- ─── 1. Ce qu'il faut retenir, à faire ───────────────────── -->
                <section class="rounded-lg border border-gray-200 bg-white" :class="loading && 'opacity-60'">
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Ce qu'il faut retenir</h2>
                        <span class="text-xs text-gray-500">
                            <template v-if="data.from === data.to">{{ longDate(data.from) }}</template>
                            <template v-else>Du {{ shortDate(data.from) }} au {{ shortDate(data.to) }}</template>
                        </span>
                        <span v-if="live" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            En direct
                        </span>
                    </header>
                    <ul class="list-disc space-y-1 py-3 pr-4 pl-9 text-sm text-gray-800 marker:text-gray-300">
                        <li v-for="(line, i) in data.summary ?? []" :key="i">{{ line }}</li>
                    </ul>
                    <template v-if="data.recommendations?.length">
                        <h3 class="border-t border-gray-100 px-4 pt-3 text-sm font-semibold text-gray-900">À faire</h3>
                        <ul class="space-y-1.5 px-4 pt-2 pb-3 text-sm text-gray-800">
                            <li v-for="(r, i) in data.recommendations" :key="i" class="grid grid-cols-[9.5rem_1fr] gap-3">
                                <span class="text-xs font-medium leading-5" :class="RECO_TYPES[r.type]?.class ?? 'text-gray-500'">{{ RECO_TYPES[r.type]?.label ?? r.type }}</span>
                                <span>{{ r.text }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- ─── 2. Heure par heure ──────────────────────────────────── -->
                <section class="rounded-lg border border-gray-200 bg-white">
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Heure par heure</h2>
                        <span class="text-xs text-gray-500">{{ data.period === 'weeks' ? 'Moyenne par jour · ' : '' }}Cliquer sur une case pour le détail</span>
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

                    <div class="overflow-x-auto" :class="loading && 'opacity-60'">
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
                                        <button
                                            v-if="hasFigures(r.bySlot[s])"
                                            type="button"
                                            class="relative flex h-9 w-full items-center justify-center rounded-sm text-sm font-semibold tabular-nums hover:brightness-95"
                                            :class="[LEVELS[r.bySlot[s].level] ?? LEVELS.none, isSelected(r, s) && 'ring-2 ring-gray-900 ring-inset']"
                                            :aria-label="`${r.label}, ${s}–${slotEnd(s)}`"
                                            @click="select(r, s)"
                                        >
                                            {{ currentMode.value(r.bySlot[s]) }}
                                            <span
                                                v-if="r.bySlot[s].level === 'short' && CAUSES[r.bySlot[s].cause]"
                                                class="absolute top-0.5 right-0.5 z-10 text-[10px] leading-[10px] font-normal text-gray-500"
                                            >
                                                <component :is="CAUSES[r.bySlot[s].cause].icon" v-if="CAUSES[r.bySlot[s].cause].icon" class="h-2.5 w-2.5" />
                                                <template v-else>{{ CAUSES[r.bySlot[s].cause].mark }}</template>
                                            </span>
                                        </button>
                                        <div v-else class="h-9 rounded-sm" :class="LEVELS[r.bySlot[s]?.level] ?? LEVELS.future" :title="r.bySlot[s]?.level === 'closed' ? 'Fermé' : undefined" />
                                    </td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td :colspan="slots.length + 1" class="px-4 py-6 text-center text-sm text-gray-400">Aucune donnée sur la période</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Légende, sur une ligne -->
                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-gray-100 px-4 py-2.5 text-xs text-gray-500">
                        <span>Chiffre : {{ currentMode.label.toLowerCase() }}{{ mode === 'free' ? ' en moyenne sur la demi-heure' : '' }}.</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-red-100 ring-1 ring-red-300" />Rouge : des clients ont souffert d'un manque d'agent (au moins 3 % des appels).</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-50 ring-1 ring-emerald-300" />Vert : équilibré.</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-blue-50 ring-1 ring-blue-300" />Bleu : agents en trop, aucun client pénalisé.</span>
                        <span class="inline-flex items-center gap-1"><span class="hatched h-2.5 w-2.5 rounded-sm ring-1 ring-gray-200" />Fermé.</span>
                        <span v-for="(c, key) in CAUSES" :key="key" class="inline-flex items-center gap-1">
                            <component :is="c.icon" v-if="c.icon" class="h-2.5 w-2.5 text-gray-500" />
                            <span v-else class="text-[10px] text-gray-500">{{ c.mark }}</span>
                            {{ c.label }}
                        </span>
                    </p>

                    <!-- Détail de la demi-heure sélectionnée -->
                    <div v-if="detail" class="border-t border-gray-200 bg-gray-50/60 px-4 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <h3 class="text-sm font-semibold text-gray-900">{{ detail.title }}</h3>
                            <button type="button" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Fermer le détail" @click="selected = null">
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <p class="mt-1 max-w-4xl text-sm text-gray-800">{{ detail.explanation }}</p>
                        <div class="mt-3 grid max-w-5xl grid-cols-1 items-start gap-x-10 gap-y-3 lg:grid-cols-2">
                            <table v-for="block in [{ title: 'Clients', lines: detail.clients }, { title: 'Agents', lines: detail.agents }]" :key="block.title" class="text-sm">
                                <thead>
                                    <tr><th colspan="2" class="pb-1 text-left text-xs font-semibold text-gray-500">{{ block.title }}</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="[label, value] in block.lines" :key="label" class="border-t border-gray-100">
                                        <td class="py-1 pr-4 whitespace-nowrap text-gray-600">{{ label }}</td>
                                        <td class="py-1 text-right whitespace-nowrap tabular-nums text-gray-900">{{ value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="detail.minutes" class="mt-3 max-w-4xl text-sm text-gray-700">{{ detail.minutes }}</p>
                    </div>
                </section>

                <!-- ─── 3. Journée type ─────────────────────────────────────── -->
                <section v-if="multiDay && rows.length" class="rounded-lg border border-gray-200 bg-white">
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Journée type</h2>
                        <span class="text-xs text-gray-500">L'écart entre les agents présents et les agents en appel, ce sont les agents libres.</span>
                        <div class="ml-auto flex flex-wrap gap-1">
                            <button
                                v-for="r in rows"
                                :key="r.key"
                                type="button"
                                class="h-7 rounded-md px-2.5 text-xs font-medium"
                                :class="typicalKey === r.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                                @click="typicalKey = r.key"
                            >
                                {{ r.label }}
                            </button>
                        </div>
                    </header>
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-medium text-gray-700">Agents</h3>
                        <VueApexCharts :key="`a-${chartKey}`" type="line" :height="240" :options="typicalAgents.options" :series="typicalAgents.series" />
                        <h3 class="mt-1 text-xs font-medium text-gray-700">Clients pénalisés</h3>
                        <VueApexCharts :key="`s-${chartKey}`" type="bar" :height="130" :options="typicalSuffered.options" :series="typicalSuffered.series" />
                    </div>
                </section>

                <!-- ─── 4. Ce que vivent les clients ────────────────────────── -->
                <section v-if="clientGroups" class="rounded-lg border border-gray-200 bg-white">
                    <header class="border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Ce que vivent les clients</h2>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500">
                                    <th class="px-4 pt-3 pb-1.5 text-left font-normal" />
                                    <th v-for="col in CLIENT_COLUMNS" :key="col.key" class="w-36 px-4 pt-3 pb-1.5 text-right font-normal whitespace-nowrap" :class="col.total && 'bg-gray-50/70 font-medium text-gray-700'">
                                        <span class="inline-flex items-center gap-1.5"><span v-if="col.color" class="h-2.5 w-2.5 rounded-[2px]" :class="col.color" />{{ col.label }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in clientRows" :key="row.label" class="hover:bg-gray-50/60" :class="row.separated ? 'border-t border-gray-200' : 'border-t border-gray-50'">
                                    <td class="py-1.5 pr-4 text-gray-700" :class="row.sub ? 'pl-8 text-gray-500' : 'pl-4'">{{ row.label }}</td>
                                    <td v-for="col in CLIENT_COLUMNS" :key="col.key" class="px-4 py-1.5 text-right whitespace-nowrap tabular-nums text-gray-900" :class="[col.total && 'bg-gray-50/70 font-medium', row.sub && 'text-gray-600']">
                                        {{ row.value(clientGroups[col.key]) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-4 pt-1 pb-2 text-xs text-gray-500">Pour Voxnode, l'étape où le client raccroche n'est connue que pour les appels suivis en direct.</p>
                    <p class="border-t border-gray-100 px-4 py-2.5 text-sm">
                        <Link href="/wait-loss" class="text-gray-700 underline hover:text-gray-900">Détail des attentes et des pertes</Link>
                    </p>
                </section>

                <!-- ─── 5. Évolution ────────────────────────────────────────── -->
                <section v-if="multiDay && (data.evolution ?? []).length" class="rounded-lg border border-gray-200 bg-white">
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
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-medium text-gray-700">Clients pénalisés</h3>
                        <VueApexCharts :key="`es-${chartKey}`" type="bar" :height="200" :options="evoSuffered.options" :series="evoSuffered.series" />
                        <h3 class="mt-1 text-xs font-medium text-gray-700">Appels reçus</h3>
                        <VueApexCharts :key="`ec-${chartKey}`" type="line" :height="120" :options="evoCalls.options" :series="evoCalls.series" />
                    </div>
                </section>
            </template>
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
