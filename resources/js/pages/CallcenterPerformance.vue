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

const preset = ref('7d')
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
// Les centres suivis (config) sont cochés par défaut, les autres décochés.
// overrides garde les choix manuels d'un chargement à l'autre.
const overrides = ref({})
const isSelected = (c) => overrides.value[c.id] ?? c.tracked
const selected = computed(() => (data.value?.centers ?? []).filter(isSelected).map((c) => c.id))
function toggleCenter(c) {
    overrides.value = { ...overrides.value, [c.id]: !isSelected(c) }
}

const centersById = computed(() => Object.fromEntries((data.value?.centers ?? []).map((c) => [c.id, c])))

// Indicateurs propres à la page, en plus de metrics()
function centerMetrics(s) {
    const m = metrics(s)
    return { ...m, agents_per_day: m.days ? m.agent_days / m.days : null, calls_per_day: m.days ? m.calls / m.days : null }
}

const centers = computed(() =>
    (data.value?.centers ?? [])
        .filter((c) => selected.value.includes(c.id))
        .map((c) => {
            const prev = data.value.previous?.find((p) => p.id === c.id)
            return { ...c, m: centerMetrics(c), prev: prev ? centerMetrics(prev) : null }
        }),
)

// Jours avec au moins un appel, tous centres sélectionnés confondus
const activeDays = computed(
    () => new Set((data.value?.daily ?? []).filter((r) => selected.value.includes(r.id)).map((r) => r.date)).size,
)

const totals = computed(() => {
    const m = metrics(addSums(centers.value))
    return { ...m, calls_per_day: activeDays.value ? m.calls / activeDays.value : null, payout_per_day: activeDays.value ? m.payout / activeDays.value : null }
})
const previousTotals = computed(() => {
    if (!data.value?.previous) return null
    const rows = data.value.previous.filter((p) => selected.value.includes(p.id))
    return rows.length ? metrics(addSums(rows)) : null
})

const costEstimated = computed(() => centers.value.some((c) => c.cost_estimated))
const costMissing = computed(() => centers.value.some((c) => c.cost === null))

// Meilleur centre sur un indicateur ('max' ou 'min'), seulement s'il y a de quoi comparer
function bestId(key, direction) {
    if (!direction || centers.value.length < 2) return null
    const values = centers.value.filter((c) => c.m[key] !== null && c.m[key] !== undefined)
    if (values.length < 2) return null
    return values.reduce((a, b) => ((direction === 'max' ? b.m[key] > a.m[key] : b.m[key] < a.m[key]) ? b : a)).id
}

function variationClass(v, better) {
    if (v === null || Math.abs(v) < 0.005) return 'text-gray-500'
    return (v > 0) === (better === 'up') ? 'text-green-700' : 'text-red-700'
}

// ─── Synthèse ─────────────────────────────────────────────────────────────────
// better : sens dans lequel une hausse est positive
const KPIS = [
    { key: 'calls', label: 'Appels', format: fmt.int, better: 'up', sub: (t) => `${fmt.int(t.calls_per_day)} par jour` },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v), better: 'up', sub: (t) => `${fmt.eur(t.payout_per_day)} par jour` },
    { key: 'margin', label: 'Marge après coût', format: (v) => fmt.eur(v), better: 'up', sub: (t) => (t.cost === null ? 'coût non renseigné' : `${fmt.pct(t.margin_rate)} du payout${t.cost_estimated ? ' · coût estimé' : ''}`) },
    { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v), better: 'up', sub: (t) => `${fmt.dec(t.calls_per_agent_day)} appels par journée-agent` },
    { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), better: 'up', sub: (t) => `${fmt.hours(t.agent_hours)} de temps agent` },
    { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), better: 'down', sub: (t) => `agent ${fmt.dur(t.avg_agent)} sur ${fmt.dur(t.avg_total)} par appel` },
]

// ─── Cartes des centres ───────────────────────────────────────────────────────
const CARD_GROUPS = [
    {
        title: 'Volume',
        rows: [
            { key: 'calls', label: 'Appels', format: fmt.int },
            { key: 'calls_per_day', label: 'Appels par jour', format: fmt.int },
            { key: 'agents', label: 'Agents actifs', format: fmt.int },
            { key: 'agents_per_day', label: 'Agents par jour (moy.)', format: (v) => fmt.dec(v) },
            { key: 'calls_per_agent_day', label: 'Appels par agent et par jour', format: (v) => fmt.dec(v), best: 'max' },
        ],
    },
    {
        title: 'Finance',
        rows: [
            { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
            { key: 'cost', label: 'Coût', format: (v) => fmt.eur(v) },
            { key: 'margin', label: 'Marge', format: (v) => fmt.eur(v), best: 'max' },
            { key: 'margin_rate', label: 'Taux de marge', format: (v) => fmt.pct(v), best: 'max' },
        ],
    },
    {
        title: 'Efficacité',
        rows: [
            { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'payout_per_call', label: 'Payout / appel', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'avg_total', label: 'Durée moyenne d\'un appel', format: fmt.dur },
            { key: 'transfer_ratio', label: 'Part du temps en transfert', format: (v) => fmt.pct(v), best: 'max' },
        ],
    },
]

// ─── Comparatif détaillé ──────────────────────────────────────────────────────
const TABLE = [
    {
        title: 'Activité',
        rows: [
            { key: 'calls', label: 'Appels', format: fmt.int },
            { key: 'calls_per_day', label: 'Appels par jour d\'activité', format: fmt.int },
            { key: 'agents', label: 'Agents actifs', format: fmt.int },
            { key: 'agents_per_day', label: 'Agents par jour (moyenne)', format: (v) => fmt.dec(v) },
            { key: 'agent_days', label: 'Journées-agent', format: fmt.int, secondary: true },
            { key: 'days', label: 'Jours d\'activité', format: fmt.int, secondary: true },
            { key: 'calls_per_agent_day', label: 'Appels par agent et par jour', format: (v) => fmt.dec(v), best: 'max' },
        ],
    },
    {
        title: 'Durées moyennes par appel',
        rows: [
            { key: 'avg_total', label: 'Durée totale', format: fmt.dur },
            { key: 'avg_wait', label: 'Attente initiale', format: fmt.dur },
            { key: 'avg_agent', label: 'Durée agent', format: fmt.dur },
            { key: 'avg_talk', label: 'dont conversation', format: fmt.dur, secondary: true, indent: true },
            { key: 'avg_pause', label: 'dont pause', format: fmt.dur, secondary: true, indent: true },
            { key: 'avg_transfer', label: 'Durée transfert', format: fmt.dur },
            { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), best: 'min' },
            { key: 'transfer_ratio', label: 'Ratio transfert', format: (v) => fmt.pct(v), best: 'max' },
            { key: 'wait_ratio', label: 'Ratio attente', format: (v) => fmt.pct(v), secondary: true },
            { key: 'agent_hours', label: 'Temps agent total', format: fmt.hours, secondary: true },
        ],
    },
    {
        title: 'Financier',
        rows: [
            { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
            { key: 'payout_per_call', label: 'Payout par appel', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'cost', label: 'Coût du centre', format: (v) => fmt.eur(v), cost: true },
            { key: 'margin', label: 'Marge après coût', format: (v) => fmt.eur(v), best: 'max', cost: true },
            { key: 'margin_rate', label: 'Taux de marge', format: (v) => fmt.pct(v), best: 'max', cost: true },
            { key: 'payout_over_cost', label: 'Payout / coût', format: fmt.mult, best: 'max', secondary: true },
            { key: 'cost_per_call', label: 'Coût par appel', format: (v) => fmt.eur(v, 2), best: 'min', secondary: true },
            { key: 'margin_per_call', label: 'Marge par appel', format: (v) => fmt.eur(v, 2), best: 'max', secondary: true },
            { key: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), best: 'max' },
            { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v), best: 'max' },
        ],
    },
]

const showSecondary = ref(false)

// Colonne « Ensemble » : les moyennes par jour d'un centre ne s'additionnent pas
function totalValue(key) {
    if (key === 'agents_per_day' || key === 'days') return null
    return totals.value[key]
}

// ─── Évolution ────────────────────────────────────────────────────────────────
const TREND_METRICS = [
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'agents_per_day', label: 'Agents actifs', format: (v) => fmt.dec(v) },
    { key: 'calls_per_agent_day', label: 'Appels / agent', format: (v) => fmt.dec(v) },
    { key: 'payout_per_agent_day', label: 'Payout / journée-agent', format: (v) => fmt.eur(v) },
    { key: 'payout_per_agent_hour', label: 'Payout / h agent', format: (v) => fmt.eur(v) },
    { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v, 0) },
]

const trendMetric = ref('payout_per_agent_day')
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
    return keys.map((k) => {
        if (!grouped[k].length) return { key: k, m: null }
        const m = metrics(addSums(grouped[k]))
        // Une ligne = un jour : agents par jour = journées-agent / jours avec activité
        return { key: k, m: { ...m, agents_per_day: m.agent_days / grouped[k].length } }
    })
}

function bucketLabel(key) {
    const label = parseIso(key).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
    return bucketByWeek.value ? `sem. ${label}` : label
}

function bucketTitle(key) {
    const d = parseIso(key)
    if (bucketByWeek.value) return `Semaine du ${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}`
    return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}

// Volumes à 0 sans activité, ratios vides
function bucketValue(b, key) {
    if (!b.m) return ['payout', 'calls', 'agents_per_day'].includes(key) ? 0 : null
    const v = b.m[key]
    return v === null || v === undefined ? null : Math.round(v * 10000) / 10000
}

const trend = computed(() => {
    const daily = data.value?.daily ?? []
    const perCenter = centers.value.map((c) => ({ center: c, buckets: buildBuckets(daily.filter((r) => r.id === c.id)) }))
    const keys = perCenter[0]?.buckets.map((b) => b.key) ?? []
    return {
        categories: keys.map(bucketLabel),
        titles: keys.map(bucketTitle),
        series: perCenter.map(({ center, buckets }) => ({
            name: center.label,
            color: center.color,
            data: buckets.map((b) => bucketValue(b, trendMetric.value)),
        })),
    }
})

// ─── Activité par heure ───────────────────────────────────────────────────────
const decAuto = (v) => fmt.dec(v, Math.abs(v) >= 10 ? 0 : 1)
const HOUR_METRICS = [
    { key: 'calls', label: 'Appels par jour', format: decAuto },
    { key: 'agents', label: 'Agents en ligne', format: decAuto },
    { key: 'calls_per_agent', label: 'Appels par agent', format: (v) => fmt.dec(v) },
]
const hourMetric = ref('calls')

const hourly = computed(() => {
    const rows = (data.value?.hourly ?? []).filter((r) => selected.value.includes(r.id) && Number(r.calls) > 0)
    if (!rows.length) return null
    // Heures utiles : au moins 1 % de l'heure la plus chargée (évite une matinée vide pour quelques appels isolés)
    const perHour = {}
    for (const r of rows) perHour[r.hour] = (perHour[r.hour] ?? 0) + Number(r.calls)
    const peak = Math.max(...Object.values(perHour))
    const hours = Object.keys(perHour).map(Number).filter((h) => perHour[h] >= peak * 0.01)
    const range = []
    for (let h = Math.min(...hours); h <= Math.max(...hours); h++) range.push(h)

    // Moyennes par jour d'activité du centre
    const value = (c, r) => {
        if (!r || !c.m.days) return hourMetric.value === 'calls_per_agent' ? null : 0
        const calls = Number(r.calls) / c.m.days
        const agents = Number(r.agent_hours) / c.m.days
        if (hourMetric.value === 'calls') return calls
        if (hourMetric.value === 'agents') return agents
        return agents ? calls / agents : null
    }

    return {
        categories: range.map((h) => `${h}h`),
        titles: range.map((h) => `De ${h}h à ${h + 1}h`),
        series: centers.value.map((c) => ({
            name: c.label,
            color: c.color,
            data: range.map((h) => {
                const v = value(c, rows.find((r) => r.id === c.id && r.hour === h))
                return v === null ? null : Math.round(v * 100) / 100
            }),
        })),
        format: HOUR_METRICS.find((m) => m.key === hourMetric.value).format,
    }
})

// ─── Composition d'un appel ───────────────────────────────────────────────────
const PHASES = [
    { key: 'avg_wait', label: 'Attente initiale', color: '#e87ba4' },
    { key: 'avg_agent', label: 'Agent', color: '#4a3aa7' },
    { key: 'avg_transfer', label: 'Transfert', color: '#008300' },
]
const maxAvgTotal = computed(() => Math.max(...centers.value.map((c) => c.m.avg_total ?? 0), 1))
const phaseShare = (c, key) => Math.max(c.m[key] ?? 0, 0) / maxAvgTotal.value

// ─── Productivité ─────────────────────────────────────────────────────────────
const PRODUCTIVITY = [
    { key: 'payout_per_agent_hour', label: 'Payout par heure agent', hint: 'Une heure passée en ligne par un agent.', format: (v) => fmt.eur(v, 2) },
    { key: 'payout_per_agent_day', label: 'Payout par journée-agent', hint: 'Un agent sur une journée où il a pris des appels.', format: (v) => fmt.eur(v) },
    { key: 'calls_per_agent_day', label: 'Appels par agent et par jour', hint: 'Nombre d\'appels pris par un agent dans sa journée.', format: (v) => fmt.dec(v) },
]
const maxOf = (key) => Math.max(...centers.value.map((c) => c.m[key] ?? 0), 1)

// ─── Agents ───────────────────────────────────────────────────────────────────
const agentFilters = reactive({ search: '', center: '', hideSmall: true })
const agentSort = reactive({ key: 'calls', dir: 'desc' })

const AGENT_COLUMNS = [
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'days', label: 'Jours', format: fmt.int },
    { key: 'calls_per_day', label: 'Appels par jour', format: (v) => fmt.dec(v) },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'payout_per_day', label: '€/jour', format: (v) => fmt.eur(v) },
    { key: 'vs_center', label: 'vs centre', format: (v) => fmt.variation(v), hint: '€ / jour de l\'agent comparé au payout / journée-agent de son centre' },
    { key: 'payout_per_agent_hour', label: '€/h agent', format: (v) => fmt.eur(v, 2) },
    { key: 'payout_per_call', label: '€/appel', format: (v) => fmt.eur(v, 2) },
    { key: 'avg_agent', label: 'Durée agent', format: fmt.dur, hint: 'Moyenne par appel' },
    { key: 'avg_transfer', label: 'Durée transfert', format: fmt.dur, hint: 'Moyenne par appel' },
    { key: 'agent_ratio', label: 'Ratio agent', format: (v) => fmt.pct(v) },
]

const agentKey = (a) => `${a.id}-${a.callcenter_id}`

function agentMetrics(a) {
    const m = metrics(a)
    const center = centersById.value[a.callcenter_id]
    const centerPerDay = center ? metrics(center).payout_per_agent_day : null
    const perDay = m.days ? m.payout / m.days : null
    return {
        ...m,
        calls_per_day: m.days ? m.calls / m.days : null,
        payout_per_day: perDay,
        vs_center: perDay !== null && centerPerDay ? perDay / centerPerDay - 1 : null,
    }
}

const agentsInScope = computed(() =>
    (data.value?.agents ?? [])
        .filter((a) => selected.value.includes(a.callcenter_id))
        .filter((a) => !agentFilters.center || a.callcenter_id === Number(agentFilters.center)),
)

// Petit volume : moins de 10 % du nombre d'appels médian des agents affichés
const smallThreshold = computed(() => {
    const calls = agentsInScope.value.map((a) => Number(a.calls)).sort((x, y) => x - y)
    if (!calls.length) return 0
    return Math.max(1, Math.round(calls[Math.floor(calls.length / 2)] * 0.1))
})

const agents = computed(() => {
    const q = agentFilters.search.trim().toLowerCase()
    const rows = agentsInScope.value
        .filter((a) => !agentFilters.hideSmall || Number(a.calls) >= smallThreshold.value)
        .filter((a) => !q || [a.name, a.username].some((v) => v && v.toLowerCase().includes(q)))
        .map((a) => ({ ...a, center: centersById.value[a.callcenter_id], m: agentMetrics(a) }))

    const dir = agentSort.dir === 'asc' ? 1 : -1
    const val = (a) => (agentSort.key === 'name' ? (a.name ?? a.username ?? '').toLowerCase() : a.m[agentSort.key])
    return rows.sort((a, b) => {
        const va = val(a)
        const vb = val(b)
        if (va === null) return 1
        if (vb === null) return -1
        return (va > vb ? 1 : va < vb ? -1 : 0) * dir
    })
})

const hiddenSmallAgents = computed(() => agentsInScope.value.filter((a) => Number(a.calls) < smallThreshold.value).length)

// Ligne de total des agents affichés
const agentsTotal = computed(() => {
    const m = metrics(addSums(agents.value))
    return { ...m, calls_per_day: m.days ? m.calls / m.days : null, payout_per_day: m.days ? m.payout / m.days : null, vs_center: null }
})

function sortAgents(key) {
    if (agentSort.key === key) agentSort.dir = agentSort.dir === 'asc' ? 'desc' : 'asc'
    else Object.assign(agentSort, { key, dir: key === 'name' ? 'asc' : 'desc' })
}

// Détail quotidien d'un agent
const expandedAgent = ref(null)
const agentDaily = ref(null)
const agentDailyLoading = ref(false)
const agentMetric = ref('calls')

async function toggleAgent(agent) {
    if (expandedAgent.value === agentKey(agent)) {
        expandedAgent.value = null
        return
    }
    expandedAgent.value = agentKey(agent)
    agentDaily.value = null
    agentDailyLoading.value = true
    try {
        const params = new URLSearchParams({ ...period.value, callcenter_id: agent.callcenter_id })
        const res = await fetch(`/data/callcenter-performance/agents/${agent.id}?${params}`, { headers: { Accept: 'application/json' } })
        agentDaily.value = await res.json()
    } finally {
        agentDailyLoading.value = false
    }
}

const AGENT_TREND_METRICS = [
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
    { key: 'payout_per_agent_hour', label: 'Payout / h agent', format: (v) => fmt.eur(v) },
    { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v, 0) },
]

const agentTrend = computed(() => {
    const agent = agents.value.find((a) => agentKey(a) === expandedAgent.value)
    if (!agent || !agentDaily.value) return null
    const buckets = buildBuckets(agentDaily.value)
    return {
        categories: buckets.map((b) => bucketLabel(b.key)),
        titles: buckets.map((b) => bucketTitle(b.key)),
        series: [{
            name: agent.name ?? agent.username,
            color: agent.center?.color ?? '#2a78d6',
            data: buckets.map((b) => bucketValue(b, agentMetric.value)),
        }],
        format: AGENT_TREND_METRICS.find((t) => t.key === agentMetric.value).format,
    }
})

// L'agent comparé à la moyenne de son centre
const AGENT_COMPARE = [
    { key: 'calls_per_day', centerKey: 'calls_per_agent_day', label: 'Appels par jour', format: (v) => fmt.dec(v), better: 'up' },
    { key: 'payout_per_day', centerKey: 'payout_per_agent_day', label: 'Payout par jour', format: (v) => fmt.eur(v), better: 'up' },
    { key: 'payout_per_agent_hour', centerKey: 'payout_per_agent_hour', label: 'Payout / heure agent', format: (v) => fmt.eur(v, 2), better: 'up' },
    { key: 'avg_agent', centerKey: 'avg_agent', label: 'Durée agent par appel', format: fmt.dur, better: 'down' },
    { key: 'avg_talk', centerKey: 'avg_talk', label: 'dont conversation', format: fmt.dur, better: 'down', indent: true },
    { key: 'avg_pause', centerKey: 'avg_pause', label: 'dont pause', format: fmt.dur, better: 'down', indent: true },
    { key: 'avg_transfer', centerKey: 'avg_transfer', label: 'Transfert par appel', format: fmt.dur, better: 'up' },
    { key: 'agent_ratio', centerKey: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), better: 'down' },
]

const agentName = (a) => a.name ?? a.username ?? `#${a.id}`

// Le détail d'un agent prend la largeur visible du tableau, pas sa largeur totale
const agentsScroll = ref(null)
const detailWidth = ref(900)
watch(expandedAgent, () => {
    if (agentsScroll.value) detailWidth.value = agentsScroll.value.clientWidth
})

// Rechargement à chaque changement de période
watch([period, compare], load, { immediate: true, deep: true })
</script>

<template>
    <Head title="Performance call centers" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Performance call centers' }]" />

            <!-- ─── Filtres ─────────────────────────────────────────────────── -->
            <div class="sticky top-0 z-20 space-y-3 rounded-lg border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur">
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
                        :class="isSelected(c) ? 'border-gray-300 bg-white text-gray-900 hover:bg-gray-50' : 'border-dashed border-gray-200 text-gray-400 hover:text-gray-600'"
                        @click="toggleCenter(c)"
                    >
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: isSelected(c) ? c.color : '#d1d5db' }" />
                        {{ c.label }}
                        <span class="text-xs text-gray-400">{{ fmt.int(c.calls) }}</span>
                    </button>
                    <span class="ml-auto text-sm text-gray-500">
                        <span class="font-medium text-gray-900">{{ formatRange(period.from, period.to) }}</span>
                        <template v-if="compare"> · comparé au {{ formatRange(previousPeriod.from, previousPeriod.to) }}</template>
                        <span v-if="loading" class="ml-2 text-gray-400">chargement…</span>
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
                    <div v-for="k in KPIS" :key="k.key" class="flex flex-col rounded-lg border border-gray-200 bg-white p-4">
                        <div class="min-h-8 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ k.label }}</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ k.format(totals[k.key]) }}</div>
                        <div class="mb-2 mt-1 text-xs text-gray-500">{{ k.sub(totals) }}</div>
                        <div
                            v-if="previousTotals && variation(totals[k.key], previousTotals[k.key]) !== null"
                            class="mt-auto flex items-center gap-1 border-t border-gray-100 pt-2 text-xs"
                        >
                            <span class="flex items-center font-medium" :class="variationClass(variation(totals[k.key], previousTotals[k.key]), k.better)">
                                <ArrowUp v-if="variation(totals[k.key], previousTotals[k.key]) > 0" class="h-3 w-3" />
                                <ArrowDown v-else-if="variation(totals[k.key], previousTotals[k.key]) < 0" class="h-3 w-3" />
                                {{ fmt.variation(variation(totals[k.key], previousTotals[k.key])) }}
                            </span>
                            <span class="truncate text-gray-400">vs {{ k.format(previousTotals[k.key]) }}</span>
                        </div>
                    </div>
                </div>

                <!-- ─── Centres ─────────────────────────────────────────────── -->
                <div class="grid gap-4" :class="centers.length >= 3 ? 'md:grid-cols-2 xl:grid-cols-3' : 'md:grid-cols-2'">
                    <div v-for="c in centers" :key="c.id" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <div class="h-1" :style="{ background: c.color }" />
                        <div class="p-5">
                            <div class="flex items-baseline justify-between gap-2">
                                <h3 class="text-lg font-semibold text-gray-900">{{ c.label }}</h3>
                                <span class="text-xs text-gray-400">
                                    <template v-if="c.label !== c.name">{{ c.name }} · </template>{{ c.m.days }} jour{{ c.m.days > 1 ? 's' : '' }} d'activité
                                </span>
                            </div>

                            <!-- Les deux indicateurs les plus parlants -->
                            <div class="mt-4 grid grid-cols-2 gap-4 rounded-md bg-gray-50 p-3">
                                <div>
                                    <div class="text-xs text-gray-500">Payout / journée-agent</div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="text-2xl font-bold tabular-nums text-gray-900">{{ fmt.eur(c.m.payout_per_agent_day) }}</span>
                                        <span v-if="bestId('payout_per_agent_day', 'max') === c.id" class="rounded bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium text-white">1er</span>
                                    </div>
                                    <div v-if="c.prev" class="mt-0.5 text-xs" :class="variationClass(variation(c.m.payout_per_agent_day, c.prev.payout_per_agent_day), 'up')">
                                        {{ fmt.variation(variation(c.m.payout_per_agent_day, c.prev.payout_per_agent_day)) }}
                                        <span class="text-gray-400">vs {{ fmt.eur(c.prev.payout_per_agent_day) }}</span>
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
                                        <span class="text-gray-400">vs {{ fmt.pct(c.prev.agent_ratio) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-for="g in CARD_GROUPS" :key="g.title" class="mt-4">
                                <div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ g.title }}</div>
                                <dl class="text-sm">
                                    <div
                                        v-for="r in g.rows"
                                        :key="r.key"
                                        class="-mx-2 flex items-baseline gap-2 rounded px-2 py-1 hover:bg-gray-100"
                                    >
                                        <dt class="text-gray-600">{{ r.label }}</dt>
                                        <span class="flex-1 -translate-y-1 border-b border-dotted border-gray-200" />
                                        <dd class="flex items-center gap-1.5 tabular-nums text-gray-900" :class="{ 'font-semibold': r.best && bestId(r.key, r.best) === c.id }">
                                            <span v-if="r.best && bestId(r.key, r.best) === c.id" class="h-1.5 w-1.5 rounded-full bg-gray-900" title="Meilleur centre sur cet indicateur" />
                                            {{ r.format(c.m[r.key]) }}
                                            <span v-if="r.key === 'cost' && c.cost_estimated" class="text-xs font-normal text-gray-400">est.</span>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── Évolution ───────────────────────────────────────────── -->
                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-900">Évolution</h3>
                        <div class="flex rounded-md border border-gray-200 p-0.5 text-xs">
                            <button
                                v-for="g in [{ k: 'auto', l: 'Auto' }, { k: 'day', l: 'Jour' }, { k: 'week', l: 'Semaine' }]"
                                :key="g.k"
                                type="button"
                                class="rounded px-2.5 py-1"
                                :class="granularity === g.k ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                @click="granularity = g.k"
                            >
                                {{ g.l }}
                            </button>
                        </div>
                    </div>
                    <div class="mb-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="t in TREND_METRICS"
                            :key="t.key"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs transition"
                            :class="trendMetric === t.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:bg-gray-100'"
                            @click="trendMetric = t.key"
                        >
                            {{ t.label }}
                        </button>
                    </div>
                    <TrendChart :categories="trend.categories" :titles="trend.titles" :series="trend.series" :format="trendFormat" :height="320" />
                </div>

                <!-- ─── Activité par heure ──────────────────────────────────── -->
                <div v-if="hourly" class="rounded-lg border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">Activité par heure</h3>
                            <p class="text-xs text-gray-500">Moyenne sur les jours d'activité de chaque centre, heure de Paris.</p>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in HOUR_METRICS"
                                :key="t.key"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs transition"
                                :class="hourMetric === t.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:bg-gray-100'"
                                @click="hourMetric = t.key"
                            >
                                {{ t.label }}
                            </button>
                        </div>
                    </div>
                    <TrendChart :categories="hourly.categories" :titles="hourly.titles" :series="hourly.series" :format="hourly.format" :height="260" />
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
                        <div class="mt-4 space-y-2">
                            <div v-for="c in centers" :key="c.id" class="-mx-2 rounded px-2 py-2 hover:bg-gray-50">
                                <div class="mb-1.5 flex items-baseline justify-between text-sm">
                                    <span class="font-medium text-gray-900">{{ c.label }}</span>
                                    <span class="tabular-nums text-gray-500">{{ fmt.dur(c.m.avg_total) }} au total</span>
                                </div>
                                <div class="flex h-7 gap-0.5">
                                    <div
                                        v-for="p in PHASES"
                                        :key="p.key"
                                        class="flex items-center justify-center overflow-hidden text-[11px] font-medium text-white first:rounded-l last:rounded-r"
                                        :style="{ width: `${phaseShare(c, p.key) * 100}%`, background: p.color }"
                                    >
                                        <span v-if="phaseShare(c, p.key) > 0.14" class="truncate px-1">{{ fmt.dur(c.m[p.key]) }}</span>
                                    </div>
                                </div>
                                <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                                    <span v-for="p in PHASES" :key="p.key" class="flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-sm" :style="{ background: p.color }" />
                                        {{ p.label }} <span class="font-medium tabular-nums text-gray-900">{{ fmt.dur(c.m[p.key]) }}</span>
                                        <span class="text-gray-400">({{ fmt.pct(c.m.avg_total ? c.m[p.key] / c.m.avg_total : null, 0) }})</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <h3 class="font-semibold text-gray-900">Productivité</h3>
                        <div class="mt-3 space-y-5">
                            <div v-for="p in PRODUCTIVITY" :key="p.key">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                                    <span class="text-sm font-medium text-gray-900">{{ p.label }}</span>
                                    <span class="text-xs text-gray-400">{{ p.hint }}</span>
                                </div>
                                <div class="mt-1.5 space-y-0.5">
                                    <div v-for="c in centers" :key="c.id" class="-mx-2 flex items-center gap-3 rounded px-2 py-0.5 text-sm hover:bg-gray-100">
                                        <span class="w-24 shrink-0 truncate text-gray-600">{{ c.label }}</span>
                                        <div class="h-4 flex-1">
                                            <div class="h-full rounded-r" :style="{ width: `${((c.m[p.key] ?? 0) / maxOf(p.key)) * 100}%`, background: c.color }" />
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
                                <tr class="border-y border-gray-100 bg-gray-50">
                                    <th class="px-5 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-400">Indicateur</th>
                                    <th v-for="c in centers" :key="c.id" class="px-5 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-600">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2 w-2 rounded-full" :style="{ background: c.color }" />
                                            {{ c.label }}
                                        </span>
                                    </th>
                                    <th v-if="centers.length > 1" class="border-l border-gray-100 px-5 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-400">Ensemble</th>
                                </tr>
                            </thead>
                            <tbody v-for="section in TABLE" :key="section.title">
                                <tr>
                                    <td :colspan="centers.length + 2" class="px-5 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                                        {{ section.title }}
                                    </td>
                                </tr>
                                <template v-for="r in section.rows" :key="r.key">
                                    <tr v-if="!r.secondary || showSecondary" class="group border-b border-gray-50 hover:bg-sky-100/70">
                                        <td class="px-5 py-1.5 text-gray-700 group-hover:text-gray-900" :class="{ 'pl-9 text-gray-500': r.indent }">
                                            {{ r.label }}
                                            <span v-if="r.cost && costEstimated" class="text-xs text-gray-400">(estimé)</span>
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
                                        <td v-if="centers.length > 1" class="border-l border-gray-100 px-5 py-1.5 text-right tabular-nums text-gray-500">
                                            {{ r.format(totalValue(r.key)) }}
                                        </td>
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
                        <h3 class="font-semibold text-gray-900">Agents</h3>
                        <span class="mr-auto text-sm text-gray-400">{{ agents.length }} affiché{{ agents.length > 1 ? 's' : '' }}</span>
                        <Input v-model="agentFilters.search" type="text" placeholder="Rechercher un agent" class="h-8 w-48" />
                        <select v-model="agentFilters.center" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm">
                            <option value="">Tous les centres</option>
                            <option v-for="c in centers" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="agentFilters.hideSmall" type="checkbox" class="rounded border-gray-300" />
                            Masquer les petits volumes (&lt; {{ smallThreshold }} appels)
                            <span v-if="agentFilters.hideSmall && hiddenSmallAgents" class="text-gray-400">· {{ hiddenSmallAgents }} masqué{{ hiddenSmallAgents > 1 ? 's' : '' }}</span>
                        </label>
                    </div>
                    <div ref="agentsScroll" class="overflow-x-auto border-t border-gray-100">
                        <table class="w-full border-separate border-spacing-0 text-[13px]">
                            <thead class="bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-400">
                                <tr>
                                    <th
                                        class="sticky left-0 z-10 cursor-pointer select-none whitespace-nowrap border-b border-gray-200 bg-gray-50 px-4 py-2 text-left align-bottom hover:text-gray-700"
                                        @click="sortAgents('name')"
                                    >
                                        Agent
                                        <ChevronUp v-if="agentSort.key === 'name' && agentSort.dir === 'asc'" class="inline h-3 w-3" />
                                        <ChevronDown v-else-if="agentSort.key === 'name'" class="inline h-3 w-3" />
                                    </th>
                                    <th
                                        v-for="col in AGENT_COLUMNS"
                                        :key="col.key"
                                        class="cursor-pointer select-none border-b border-gray-200 px-2 py-2 text-right align-bottom leading-tight hover:text-gray-700"
                                        :class="{ 'text-gray-900': agentSort.key === col.key }"
                                        :title="col.hint"
                                        @click="sortAgents(col.key)"
                                    >
                                        <span class="inline-flex items-end justify-end gap-0.5">
                                            <ChevronUp v-if="agentSort.key === col.key && agentSort.dir === 'asc'" class="mb-px h-3 w-3 shrink-0" />
                                            <ChevronDown v-else-if="agentSort.key === col.key" class="mb-px h-3 w-3 shrink-0" />
                                            <span>{{ col.label }}</span>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="a in agents" :key="agentKey(a)">
                                    <tr
                                        class="group cursor-pointer"
                                        :class="expandedAgent === agentKey(a) ? 'bg-sky-50' : 'hover:bg-sky-50'"
                                        @click="toggleAgent(a)"
                                    >
                                        <td
                                            class="sticky left-0 whitespace-nowrap border-b border-gray-100 px-4 py-2"
                                            :class="expandedAgent === agentKey(a) ? 'bg-sky-50' : 'bg-white group-hover:bg-sky-50'"
                                        >
                                            <div class="flex items-center gap-2">
                                                <ChevronDown v-if="expandedAgent === agentKey(a)" class="h-3.5 w-3.5 text-gray-400" />
                                                <ChevronRight v-else class="h-3.5 w-3.5 text-gray-400" />
                                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: a.center?.color }" :title="a.center?.label" />
                                                <span class="font-medium text-gray-900">{{ agentName(a) }}</span>
                                                <span v-if="a.name && a.username && a.name !== a.username" class="font-mono text-xs text-gray-400">{{ a.username }}</span>
                                            </div>
                                        </td>
                                        <td
                                            v-for="col in AGENT_COLUMNS"
                                            :key="col.key"
                                            class="whitespace-nowrap border-b border-gray-100 px-2 py-2 text-right tabular-nums"
                                            :class="[
                                                agentSort.key === col.key ? 'font-medium text-gray-900' : 'text-gray-700',
                                                col.key === 'vs_center' && a.m.vs_center !== null ? variationClass(a.m.vs_center, 'up') : '',
                                            ]"
                                        >
                                            {{ col.format(a.m[col.key]) }}
                                        </td>
                                    </tr>
                                    <tr v-if="expandedAgent === agentKey(a)">
                                        <td :colspan="AGENT_COLUMNS.length + 1" class="border-b border-gray-200 bg-sky-50/40 py-4">
                                            <div class="sticky left-0 flex flex-wrap items-start gap-6 px-5" :style="{ width: `${detailWidth}px` }">
                                                <!-- L'agent face à la moyenne de son centre -->
                                                <div class="w-full max-w-xs shrink-0">
                                                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                                                        {{ agentName(a) }} vs {{ a.center?.label }}
                                                    </div>
                                                    <table class="w-full text-sm">
                                                        <tr class="text-[11px] text-gray-400">
                                                            <td />
                                                            <td class="pb-1 text-right">Agent</td>
                                                            <td class="pb-1 text-right">Centre</td>
                                                        </tr>
                                                        <tr v-for="r in AGENT_COMPARE" :key="r.key" class="hover:bg-white">
                                                            <td class="py-1 pr-3 text-gray-600" :class="{ 'pl-3 text-gray-500': r.indent }">{{ r.label }}</td>
                                                            <td
                                                                class="py-1 text-right font-medium tabular-nums"
                                                                :class="a.center ? variationClass(variation(a.m[r.key], metrics(a.center)[r.centerKey]), r.better) : 'text-gray-900'"
                                                            >
                                                                {{ r.format(a.m[r.key]) }}
                                                            </td>
                                                            <td class="py-1 pl-3 text-right tabular-nums text-gray-500">{{ a.center ? r.format(metrics(a.center)[r.centerKey]) : '—' }}</td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="min-w-[320px] flex-1" @click.stop>
                                                    <div class="mb-1 flex flex-wrap gap-1.5">
                                                        <button
                                                            v-for="t in AGENT_TREND_METRICS"
                                                            :key="t.key"
                                                            type="button"
                                                            class="rounded-full border px-2.5 py-0.5 text-xs transition"
                                                            :class="agentMetric === t.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                                                            @click="agentMetric = t.key"
                                                        >
                                                            {{ t.label }}
                                                        </button>
                                                    </div>
                                                    <div v-if="agentDailyLoading" class="flex h-52 items-center justify-center text-sm text-gray-400">Chargement…</div>
                                                    <TrendChart
                                                        v-else-if="agentTrend"
                                                        :categories="agentTrend.categories"
                                                        :titles="agentTrend.titles"
                                                        :series="agentTrend.series"
                                                        :format="agentTrend.format"
                                                        :height="220"
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!agents.length">
                                    <td :colspan="AGENT_COLUMNS.length + 1" class="px-5 py-8 text-center text-sm text-gray-400">Aucun agent</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="agents.length > 1" class="bg-gray-50 font-medium text-gray-900">
                                <tr>
                                    <td class="sticky left-0 border-t border-gray-200 bg-gray-50 px-4 py-2">Total affiché</td>
                                    <td
                                        v-for="col in AGENT_COLUMNS"
                                        :key="col.key"
                                        class="whitespace-nowrap border-t border-gray-200 px-2 py-2 text-right tabular-nums"
                                    >
                                        {{ col.key === 'vs_center' ? '' : col.format(agentsTotal[col.key]) }}
                                    </td>
                                </tr>
                            </tfoot>
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
                            {{ fmt.int(Number(data.unattributed.calls)) }} appels sans call center sur la période ({{ fmt.eur(Number(data.unattributed.payout)) }}), non comptés ici.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
