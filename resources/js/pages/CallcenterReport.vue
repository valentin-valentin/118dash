<script setup>
import { computed, onMounted, ref } from 'vue'
import TrendChart from '@/components/TrendChart.vue'
import { Printer } from 'lucide-vue-next'
import { addDays, addSums, daysBetween, fmt, iso, metrics, parseIso, variation } from '@/lib/performance'

/**
 * Rapport imprimable de la page Performance (A4 paysage, enregistrable en PDF).
 * mode : full (tout), no_money (sans montants), indices (montants remplacés par des indices)
 *
 * Indices : 100 = moyenne de l'ensemble des centres sur la période, calculée sur les sommes
 * (donc pondérée par l'activité). 120 = 20 % au-dessus de la moyenne du groupe.
 */
const props = defineProps({ query: { type: Object, default: () => ({}) } })

const from = props.query.from
const to = props.query.to
const mode = props.query.mode ?? 'full'
const showAgents = props.query.agents !== '0'
const withMoney = mode === 'full'
const withIndices = mode === 'indices'

// ─── Libellé de la période ────────────────────────────────────────────────────
const periodLabel = computed(() => {
    const f = parseIso(from)
    const t = parseIso(to)
    const lastOfMonth = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate()
    if (f.getDate() === 1 && t.getDate() === lastOfMonth && f.getMonth() === t.getMonth() && f.getFullYear() === t.getFullYear()) {
        const label = f.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
        return label.charAt(0).toUpperCase() + label.slice(1)
    }
    const long = { day: 'numeric', month: 'long', year: 'numeric' }
    if (daysBetween(from, to) === 7 && f.getDay() === 1) {
        return `Semaine du ${f.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} au ${t.toLocaleDateString('fr-FR', long)}`
    }
    if (from === to) return f.toLocaleDateString('fr-FR', { weekday: 'long', ...long })
    return `Du ${f.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} au ${t.toLocaleDateString('fr-FR', long)}`
})

const previousLabel = computed(() => {
    if (!props.query.prev_from) return null
    const f = parseIso(props.query.prev_from)
    const t = parseIso(props.query.prev_to)
    return `${f.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} – ${t.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })}`
})

const modeLabel = { full: 'Rapport complet', no_money: 'Sans montants', indices: 'Indices' }[mode]

// ─── Données ──────────────────────────────────────────────────────────────────
const data = ref(null)
const error = ref(null)

onMounted(async () => {
    const params = new URLSearchParams({ from, to })
    for (const k of ['prev_from', 'prev_to', 'split_remote']) if (props.query[k]) params.set(k, props.query[k])
    try {
        const res = await fetch(`/data/callcenter-performance?${params}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        // Titre du document = nom proposé pour le PDF
        document.title = `Performance centres – ${periodLabel.value}`
    } catch (e) {
        error.value = e.message
    }
})

function centerMetrics(s) {
    const m = metrics(s)
    return { ...m, agents_per_day: m.days ? m.agent_days / m.days : null, calls_per_day: m.days ? m.calls / m.days : null }
}

const centers = computed(() =>
    (data.value?.centers ?? []).map((c) => {
        const prev = data.value.previous?.find((p) => p.id === c.id)
        return { ...c, m: centerMetrics(c), prev: prev ? centerMetrics(prev) : null }
    }),
)

// Ensemble des centres : référence des indices (= 100) et dernière colonne de la synthèse
const ensemble = computed(() => {
    const days = new Set((data.value?.daily ?? []).map((r) => r.date)).size
    const m = metrics(addSums(centers.value))
    const prevRows = data.value?.previous ?? []
    return {
        id: '_all',
        label: 'Ensemble',
        color: '#9ca3af',
        m: { ...m, days, agents_per_day: days ? m.agent_days / days : null, calls_per_day: days ? m.calls / days : null },
        // Jours d'activité inconnus sur la période précédente : pas d'évolution pour les moyennes par jour
        prev: prevRows.length ? { ...metrics(addSums(prevRows)), agents_per_day: null, calls_per_day: null } : null,
    }
})
const columns = computed(() => (centers.value.length > 1 ? [...centers.value, ensemble.value] : centers.value))
const index = (c, key) => (ensemble.value.m[key] ? (c.m[key] / ensemble.value.m[key]) * 100 : null)

// ─── Tableau de synthèse ──────────────────────────────────────────────────────
// money : montant (masqué hors rapport complet) ; better : sens favorable d'une hausse
const SECTIONS = computed(() => [
    {
        title: 'Activité',
        rows: [
            { key: 'calls', label: 'Appels', format: fmt.int, better: 'up' },
            { key: 'calls_per_day', label: 'Appels par jour d\'activité', format: fmt.int, better: 'up' },
            { key: 'agents', label: 'Agents actifs', format: fmt.int },
            { key: 'agents_per_day', label: 'Agents par jour (moyenne)', format: (v) => fmt.dec(v) },
            { key: 'calls_per_agent_day', label: 'Appels par agent et par jour', format: (v) => fmt.dec(v), better: 'up' },
        ],
    },
    {
        title: 'Durées moyennes par appel',
        rows: [
            { key: 'avg_total', label: 'Durée totale', format: fmt.dur },
            { key: 'avg_wait', label: 'Attente initiale', format: fmt.dur, better: 'down' },
            { key: 'avg_agent', label: 'Durée agent', format: fmt.dur, better: 'down' },
            { key: 'avg_talk', label: 'dont conversation', format: fmt.dur, indent: true },
            { key: 'avg_pause', label: 'dont pause', format: fmt.dur, indent: true, better: 'down' },
            { key: 'avg_transfer', label: 'Durée transfert', format: fmt.dur, better: 'up' },
            { key: 'agent_ratio', label: 'Ratio temps agent', format: (v) => fmt.pct(v), better: 'down' },
            { key: 'transfer_ratio', label: 'Ratio transfert', format: (v) => fmt.pct(v), better: 'up' },
        ],
    },
    withMoney && {
        title: 'Finance',
        rows: [
            { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v), better: 'up' },
            { key: 'cost', label: 'Coût du centre', format: (v) => fmt.eur(v) },
            { key: 'margin', label: 'Marge après coût', format: (v) => fmt.eur(v), better: 'up' },
            { key: 'margin_rate', label: 'Taux de marge', format: (v) => fmt.pct(v), better: 'up' },
            { key: 'payout_per_agent_day', label: 'Payout par journée-agent', format: (v) => fmt.eur(v), better: 'up' },
            { key: 'payout_per_agent_hour', label: 'Payout par heure agent', format: (v) => fmt.eur(v, 2), better: 'up' },
            { key: 'payout_per_call', label: 'Payout par appel', format: (v) => fmt.eur(v, 2), better: 'up' },
        ],
    },
    withIndices && {
        title: 'Indices (100 = moyenne de l\'ensemble des centres)',
        rows: [
            { key: 'payout_per_agent_day', label: 'Production par journée-agent', index: true, better: 'up' },
            { key: 'payout_per_agent_hour', label: 'Production par heure agent', index: true, better: 'up' },
            { key: 'payout_per_call', label: 'Production par appel', index: true, better: 'up' },
        ],
    },
].filter(Boolean))

function cell(c, r) {
    if (r.index) return fmt.dec(index(c, r.key), 1)
    return r.format(c.m[r.key])
}

function trend(c, r) {
    if (!c.prev || !r.better) return null
    return variation(c.m[r.key], c.prev[r.key])
}

function trendClass(v, better) {
    if (v === null || Math.abs(v) < 0.005) return 'text-gray-400'
    return (v > 0) === (better === 'up') ? 'text-green-700' : 'text-red-700'
}

// ─── Graphiques ───────────────────────────────────────────────────────────────
const days = computed(() => {
    const list = []
    for (let d = parseIso(from); iso(d) <= to; d = addDays(d, 1)) list.push(iso(d))
    return list
})

function dailySeries(key, zeroIfEmpty) {
    const daily = data.value?.daily ?? []
    return centers.value.map((c) => ({
        name: c.label,
        color: c.color,
        data: days.value.map((date) => {
            const row = daily.find((r) => r.id === c.id && r.date === date)
            if (!row) return zeroIfEmpty ? 0 : null
            const v = metrics(addSums([row]))[key]
            return v === null ? null : Math.round(v * 100) / 100
        }),
    }))
}

const chartDays = computed(() => days.value.map((d) => parseIso(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })))
const callsChart = computed(() => dailySeries('calls', true))
const productivityChart = computed(() =>
    withMoney
        ? { title: 'Payout par journée-agent', series: dailySeries('payout_per_agent_day'), format: (v) => fmt.eur(v) }
        : { title: 'Appels par agent et par jour', series: dailySeries('calls_per_agent_day'), format: (v) => fmt.dec(v) },
)

const PHASES = [
    { key: 'avg_wait', label: 'Attente initiale', color: '#e87ba4' },
    { key: 'avg_talk', label: 'Agent : conversation', color: '#4a3aa7' },
    { key: 'avg_pause', label: 'Agent : pause', color: '#9085e9' },
    { key: 'avg_transfer', label: 'Transfert', color: '#008300' },
]
const maxAvgTotal = computed(() => Math.max(...centers.value.map((c) => c.m.avg_total ?? 0), 1))
const phaseShare = (c, key) => Math.max(c.m[key] ?? 0, 0) / maxAvgTotal.value

// ─── Agents ───────────────────────────────────────────────────────────────────
const AGENT_COLUMNS = [
    { key: 'calls', label: 'Appels', format: fmt.int },
    { key: 'days', label: 'Jours', format: fmt.int },
    { key: 'calls_per_day', label: 'Appels / jour', format: (v) => fmt.dec(v) },
    ...(withMoney ? [
        { key: 'payout', label: 'Payout', format: (v) => fmt.eur(v) },
        { key: 'payout_per_day', label: '€ / jour', format: (v) => fmt.eur(v) },
        { key: 'payout_per_agent_hour', label: '€ / h agent', format: (v) => fmt.eur(v, 2) },
    ] : []),
    ...(withIndices ? [{ key: 'index_day', label: 'Indice / jour', format: (v) => fmt.dec(v, 0) }] : []),
    { key: 'avg_agent', label: 'Durée agent', format: fmt.dur },
    { key: 'avg_talk', label: 'Conversation', format: fmt.dur },
    { key: 'avg_pause', label: 'Pause', format: fmt.dur },
    { key: 'avg_transfer', label: 'Transfert', format: fmt.dur },
    { key: 'agent_ratio', label: 'Ratio agent', format: (v) => fmt.pct(v) },
]

const agentsByCenter = computed(() =>
    centers.value.map((c) => ({
        center: c,
        agents: (data.value?.agents ?? [])
            .filter((a) => a.callcenter_id === c.id)
            .map((a) => {
                const m = metrics(a)
                const perDay = m.days ? m.payout / m.days : null
                return {
                    name: a.username ?? a.id,
                    m: {
                        ...m,
                        calls_per_day: m.days ? m.calls / m.days : null,
                        payout_per_day: perDay,
                        // 100 = payout par journée-agent moyen de l'ensemble des centres
                        index_day: perDay !== null && ensemble.value.m.payout_per_agent_day ? (perDay / ensemble.value.m.payout_per_agent_day) * 100 : null,
                    },
                }
            })
            .sort((x, y) => y.m.calls - x.m.calls),
    })).filter((g) => g.agents.length),
)

const print = () => window.print()

const generatedAt = new Date().toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
const ready = computed(() => !!data.value)
</script>

<template>
    <div class="report min-h-screen bg-gray-100 py-6 print:bg-white print:py-0">
        <!-- Barre d'actions (non imprimée) -->
        <div class="mx-auto mb-4 flex max-w-[297mm] items-center justify-between px-2 print:hidden">
            <div class="text-sm text-gray-600">
                Aperçu du rapport · {{ modeLabel }}. Dans la fenêtre d'impression, choisir « Enregistrer au format PDF ».
            </div>
            <button
                type="button"
                class="flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                :disabled="!ready"
                @click="print"
            >
                <Printer class="h-4 w-4" />
                Imprimer / PDF
            </button>
        </div>

        <p v-if="error" class="mx-auto max-w-[297mm] rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <div v-else-if="!ready" class="mx-auto max-w-[297mm] p-10 text-center text-sm text-gray-500">Chargement…</div>

        <template v-else>
            <!-- ─── Page 1 : synthèse ─────────────────────────────────────── -->
            <section class="sheet">
                <header class="mb-5 flex items-end justify-between border-b-2 border-gray-900 pb-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Performance des centres d'appels</div>
                        <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ periodLabel }}</h1>
                    </div>
                    <div class="text-right text-xs text-gray-500">
                        <div v-if="previousLabel">Évolutions comparées au {{ previousLabel }}</div>
                        <div>Généré le {{ generatedAt }}</div>
                    </div>
                </header>

                <table class="w-full text-[12px]">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="py-1.5 text-left font-medium text-gray-500" />
                            <th
                                v-for="c in columns"
                                :key="c.id"
                                class="py-1.5 pl-4 text-right font-semibold"
                                :class="c.id === '_all' ? 'border-l border-gray-200 text-gray-500' : 'text-gray-900'"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ background: c.color }" />
                                    {{ c.label }}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody v-for="s in SECTIONS" :key="s.title">
                        <tr>
                            <td :colspan="columns.length + 1" class="pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ s.title }}</td>
                        </tr>
                        <tr v-for="r in s.rows" :key="s.title + r.key" class="border-b border-gray-100">
                            <td class="py-1 text-gray-700" :class="{ 'pl-4 text-gray-500': r.indent }">{{ r.label }}</td>
                            <td
                                v-for="c in columns"
                                :key="c.id"
                                class="py-1 pl-4 text-right tabular-nums"
                                :class="{ 'border-l border-gray-200 bg-gray-50': c.id === '_all' }"
                            >
                                <span class="font-medium" :class="c.id === '_all' ? 'text-gray-600' : 'text-gray-900'">{{ cell(c, r) }}</span>
                                <span
                                    v-if="trend(c, r) !== null"
                                    class="ml-2 inline-block w-14 text-[10px]"
                                    :class="trendClass(trend(c, r), r.better)"
                                >{{ fmt.variation(trend(c, r)) }}</span>
                                <span v-else-if="previousLabel" class="ml-2 inline-block w-14" />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="mt-4 text-[10px] leading-relaxed text-gray-500">
                    Journée-agent : un agent qui a pris au moins un appel dans la journée. Ratio temps agent : part de la durée d'un appel
                    où un agent est mobilisé (plus il est bas, mieux c'est). Conversation / pause : répartition mesurée sur les appels où
                    l'application agent l'a enregistrée.
                    <template v-if="withIndices">
                        Indices : 100 = moyenne de l'ensemble des centres sur la période (pondérée par l'activité) ; 120 = 20 % au-dessus de
                        cette moyenne. Indice / jour d'un agent : sa production par jour comparée à la même moyenne.
                    </template>
                    <template v-if="withMoney && centers.some((c) => c.cost_estimated)"> Période partielle : coût mensuel réparti au prorata des jours.</template>
                </p>
            </section>

            <!-- ─── Page 2 : graphiques ───────────────────────────────────── -->
            <section class="sheet break-before-page">
                <h2 class="mb-3 text-base font-semibold text-gray-900">Évolution jour par jour</h2>
                <div class="grid grid-cols-2 gap-12">
                    <div>
                        <div class="text-xs font-medium text-gray-700">Appels par jour</div>
                        <TrendChart :categories="chartDays" :series="callsChart" :format="fmt.int" :height="230" />
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-700">{{ productivityChart.title }}</div>
                        <TrendChart :categories="chartDays" :series="productivityChart.series" :format="productivityChart.format" :height="230" />
                    </div>
                </div>

                <h2 class="mb-2 mt-6 text-base font-semibold text-gray-900">Composition moyenne d'un appel</h2>
                <div class="mb-3 flex flex-wrap gap-4 text-[11px] text-gray-600">
                    <span v-for="p in PHASES" :key="p.key" class="flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: p.color, printColorAdjust: 'exact' }" />
                        {{ p.label }}
                    </span>
                </div>
                <div class="space-y-3">
                    <div v-for="c in centers" :key="c.id" class="flex items-center gap-3">
                        <div class="w-36 shrink-0 text-xs font-medium text-gray-900">
                            {{ c.label }}
                            <div class="font-normal text-gray-500">{{ fmt.dur(c.m.avg_total) }} au total</div>
                        </div>
                        <div class="flex h-6 flex-1 gap-0.5">
                            <div
                                v-for="p in PHASES"
                                :key="p.key"
                                class="flex items-center justify-center overflow-hidden text-[10px] font-medium text-white first:rounded-l last:rounded-r"
                                :style="{ width: `${phaseShare(c, p.key) * 100}%`, background: p.color, printColorAdjust: 'exact' }"
                            >
                                <span v-if="phaseShare(c, p.key) > 0.09" class="truncate px-1">{{ fmt.dur(c.m[p.key]) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─── Pages suivantes : agents ──────────────────────────────── -->
            <template v-if="showAgents">
                <section
                    v-for="(g, i) in agentsByCenter"
                    :key="g.center.id"
                    class="sheet"
                    :class="{ 'break-before-page': i === 0, 'break-inside-avoid': g.agents.length <= 20 }"
                >
                    <h2 class="mb-2 flex items-center gap-2 text-base font-semibold text-gray-900">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: g.center.color, printColorAdjust: 'exact' }" />
                        Agents · {{ g.center.label }}
                        <span class="text-xs font-normal text-gray-500">{{ g.agents.length }} agent{{ g.agents.length > 1 ? 's' : '' }}</span>
                    </h2>
                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="border-b border-gray-300 text-gray-500">
                                <th class="py-1 text-left font-medium">Agent</th>
                                <th v-for="col in AGENT_COLUMNS" :key="col.key" class="py-1 pl-3 text-right font-medium">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in g.agents" :key="a.name" class="break-inside-avoid border-b border-gray-100">
                                <td class="py-1 font-medium text-gray-900">{{ a.name }}</td>
                                <td v-for="col in AGENT_COLUMNS" :key="col.key" class="py-1 pl-3 text-right tabular-nums text-gray-700">{{ col.format(a.m[col.key]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </template>
    </div>
</template>

<style>
@page {
    size: A4 landscape;
    margin: 10mm;
}

/* Aperçu à l'écran : une feuille A4 paysage par section */
.report .sheet {
    box-sizing: border-box;
    width: 297mm;
    max-width: 100%;
    margin: 0 auto 16px;
    padding: 12mm;
    background: #fff;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
}

@media print {
    .report .sheet {
        width: auto;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }

    .report .sheet + .sheet {
        margin-top: 8mm;
    }

    .report * {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>
