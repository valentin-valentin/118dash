<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import TrendChart from '@/components/TrendChart.vue'
import { Download } from 'lucide-vue-next'
import { addDays, addSums, daysBetween, fmt, iso, metrics, parseIso, variation } from '@/lib/performance'

/**
 * Rapport PDF de la page Performance : chaque .sheet est une page A4 paysage,
 * capturée en image puis assemblée en PDF (bouton « Télécharger le PDF », ou automatiquement avec download=1).
 * mode : full (tout), no_money (sans montants), indices (montants remplacés par des indices)
 *
 * Indices : 100 = moyenne de l'ensemble des centres sur la période, calculée sur les sommes
 * (donc pondérée par l'activité). 120 = 20 % au-dessus de la moyenne du groupe.
 */
const props = defineProps({ query: { type: Object, default: () => ({}) } })

const from = props.query.from
const to = props.query.to
const mode = props.query.mode ?? 'full'
// Détail des agents : 'none', 'all' ou l'id d'un centre
const agentsScope = { 0: 'none', 1: 'all' }[props.query.agents] ?? props.query.agents ?? 'none'
const withMoney = mode === 'full'
const withIndices = mode === 'indices'
// Centres à inclure (ex : madagascar,lamia) ; vide = tous
const includedCenters = props.query.centers ? props.query.centers.split(',') : null
const isIncluded = (id) => !includedCenters || includedCenters.includes(id)

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

const modeLabel = { full: 'Rapport complet (montants bruts)', no_money: 'Sans montants', indices: 'Indices' }[mode]

// ─── Données ──────────────────────────────────────────────────────────────────
const data = ref(null)
const error = ref(null)

onMounted(async () => {
    const params = new URLSearchParams({ from, to })
    for (const k of ['prev_from', 'prev_to', 'split_remote']) if (props.query[k]) params.set(k, props.query[k])
    // Graphiques : chaque jour comparé au même jour de la semaine précédente
    params.set('cmp_from', iso(addDays(parseIso(from), -7)))
    params.set('cmp_to', iso(addDays(parseIso(to), -7)))
    try {
        const res = await fetch(`/data/callcenter-performance?${params}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        document.title = pdfName.value
    } catch (e) {
        error.value = e.message
        return
    }
    if (props.query.download === '1') {
        // Laisser les graphiques se dessiner avant la capture
        await nextTick()
        setTimeout(downloadPdf, 800)
    }
})

function centerMetrics(s) {
    const m = metrics(s)
    return { ...m, agents_per_day: m.days ? m.agent_days / m.days : null, calls_per_day: m.days ? m.calls / m.days : null }
}

const centers = computed(() =>
    (data.value?.centers ?? []).filter((c) => isIncluded(c.id)).map((c) => {
        const prev = data.value.previous?.find((p) => p.id === c.id)
        return { ...c, m: centerMetrics(c), prev: prev ? centerMetrics(prev) : null }
    }),
)

// Ensemble des centres : référence des indices (= 100) et dernière colonne de la synthèse
const ensemble = computed(() => {
    const days = new Set((data.value?.daily ?? []).filter((r) => isIncluded(r.id)).map((r) => r.date)).size
    const m = metrics(addSums(centers.value))
    const prevRows = (data.value?.previous ?? []).filter((p) => isIncluded(p.id))
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

// Écarts affichés en gris, sans jugement bon / mauvais (choix volontaire, à revoir plus tard).
// `better` indique quand même le sens favorable de chaque indicateur.
function trendClass() {
    return 'text-gray-400'
}

// ─── Graphiques ───────────────────────────────────────────────────────────────
// Jours ouvrés seulement : les centres sont fermés le dimanche
function workDays(start, end) {
    const list = []
    for (let d = parseIso(start); iso(d) <= end; d = addDays(d, 1)) if (d.getDay() !== 0) list.push(iso(d))
    return list
}
const days = computed(() => workDays(from, to))
// Comparaison des graphiques : le même jour de la semaine précédente (lundi avec le lundi d'avant)
const hasPrevious = computed(() => !!data.value?.compare_daily)
const weekBefore = (date) => iso(addDays(parseIso(date), -7))

// Valeur d'un indicateur pour un centre un jour donné. index_day : production par journée-agent
// rapportée à celle de l'ensemble des centres inclus ce jour-là (= 100).
function dayValue(source, c, date, key, zeroIfEmpty) {
    const row = source.find((r) => r.id === c.id && r.date === date)
    if (!row) return zeroIfEmpty ? 0 : null
    let v
    if (key === 'index_day') {
        const all = metrics(addSums(source.filter((r) => r.date === date && isIncluded(r.id)))).payout_per_agent_day
        const own = metrics(addSums([row])).payout_per_agent_day
        v = all && own !== null ? (own / all) * 100 : null
    } else {
        v = metrics(addSums([row]))[key]
    }
    return v === null ? null : Math.round(v * 100) / 100
}

function dailySeries(key, zeroIfEmpty = false) {
    const daily = data.value?.daily ?? []
    const series = centers.value.map((c) => ({
        name: c.label,
        color: c.color,
        data: days.value.map((date) => dayValue(daily, c, date, key, zeroIfEmpty)),
    }))
    if (hasPrevious.value) {
        const compareDaily = data.value.compare_daily
        for (const c of centers.value) {
            series.push({
                name: `${c.label} (sem. préc.)`,
                color: c.color,
                dashed: true,
                data: days.value.map((date) => dayValue(compareDaily, c, weekBefore(date), key, zeroIfEmpty)),
            })
        }
    }
    return series
}

// Libellés courts pour que l'axe reste lisible : « lun. 2 » sur une semaine, le numéro du jour au-delà
const chartDays = computed(() =>
    days.value.map((d) =>
        days.value.length > 10
            ? String(parseIso(d).getDate())
            : parseIso(d).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }),
    ),
)
// Tableau des valeurs sous chaque graphique quand il tient en largeur (une semaine).
// Plus lisible que des étiquettes sur les points, qui se chevauchent quand les courbes sont proches.
const valueTables = computed(() => days.value.length <= 7)

const CHART_FONT = 'Helvetica, Arial, sans-serif'

// ─── Page 2 : comparaison des centres sur la période ──────────────────────────
// Trois indicateurs relatifs, comparables d'un centre à l'autre quelle que soit sa taille.
const comparisonBlocks = computed(() => [
    withMoney
        ? { key: 'payout_per_agent_day', title: 'Payout par journée-agent', hint: 'Ce que rapporte un agent sur une journée travaillée.', format: (v) => fmt.eur(v) }
        : withIndices
            ? { key: 'index_production', title: 'Production par journée-agent', hint: 'Indice : 100 = moyenne de l\'ensemble des centres.', format: (v) => fmt.dec(v, 0) }
            : { key: 'avg_agent', title: 'Durée agent par appel', hint: 'Temps moyen d\'un agent sur un appel. Plus bas = plus efficace.', format: fmt.dur },
    { key: 'calls_per_agent_day', title: 'Appels par agent et par jour', hint: 'Nombre d\'appels pris par un agent dans sa journée.', format: (v) => fmt.dec(v, 1) },
    { key: 'agent_ratio', title: 'Ratio temps agent', hint: 'Part d\'un appel où l\'agent est mobilisé. Plus bas = mieux.', format: (v) => fmt.pct(v) },
])

// Valeur d'une ligne (centre ou Ensemble) sur la période ou la période précédente
function blockValue(entity, key, previous = false) {
    const m = previous ? entity.prev : entity.m
    if (!m) return null
    if (key === 'index_production') {
        const reference = previous ? ensemble.value.prev?.payout_per_agent_day : ensemble.value.m.payout_per_agent_day
        return reference && m.payout_per_agent_day !== null ? (m.payout_per_agent_day / reference) * 100 : null
    }
    return m[key] ?? null
}

const comparisonRows = computed(() => (centers.value.length > 1 ? [...centers.value, ensemble.value] : centers.value))

// Échelle commune à toutes les barres d'un indicateur (période et période précédente)
function blockMax(key) {
    const values = comparisonRows.value.flatMap((e) => [blockValue(e, key), blockValue(e, key, true)]).filter((v) => v !== null)
    return Math.max(...values, 0) * 1.08 || 1
}

// ─── Page 3 : évolution jour par jour ─────────────────────────────────────────
const evolutionCharts = computed(() =>
    [
        withMoney
            ? { key: 'payout_per_agent_day', title: 'Payout par journée-agent', format: (v) => fmt.eur(v) }
            : withIndices
                ? { key: 'index_day', title: 'Production par journée-agent', subtitle: 'indice, 100 = ensemble des centres ce jour-là', format: (v) => fmt.dec(v, 0) }
                : { key: 'agent_ratio', title: 'Ratio temps agent', format: (v) => fmt.pct(v, 0) },
        { key: 'calls_per_agent_day', title: 'Appels par agent et par jour', format: (v) => fmt.dec(v, 0) },
    ].map((c) => ({ ...c, series: dailySeries(c.key) })),
)

const PHASES = [
    { key: 'avg_wait', label: 'Attente initiale', color: '#e87ba4' },
    { key: 'avg_talk', label: 'Agent : conversation', color: '#4a3aa7' },
    { key: 'avg_pause', label: 'Agent : pause', color: '#9085e9' },
    { key: 'avg_transfer', label: 'Transfert', color: '#008300' },
]
const maxAvgTotal = computed(() => Math.max(...centers.value.map((c) => c.m.avg_total ?? 0), 1))
const phaseShare = (c, key) => Math.max(c.m[key] ?? 0, 0) / maxAvgTotal.value
const phasePercent = (c, key) => fmt.pct(c.m.avg_total ? c.m[key] / c.m.avg_total : null, 0)

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
    centers.value.filter((c) => agentsScope === 'all' || agentsScope === c.id).map((c) => ({
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

// Répartition des tableaux d'agents en pages : un tableau coûte ses lignes + 3 (titre, en-tête),
// un tableau trop long continue sur la page suivante
const ROWS_PER_PAGE = 26
const agentPages = computed(() => {
    if (agentsScope === 'none') return []
    const pages = []
    let page = []
    let used = 0
    for (const g of agentsByCenter.value) {
        let rest = g.agents
        let continued = false
        while (rest.length) {
            const room = ROWS_PER_PAGE - used - 3
            if (room < 3) {
                pages.push(page)
                page = []
                used = 0
                continue
            }
            const chunk = rest.slice(0, room)
            page.push({ center: g.center, agents: chunk, total: g.agents.length, continued })
            used += chunk.length + 3
            rest = rest.slice(room)
            continued = true
        }
    }
    if (page.length) pages.push(page)
    return pages
})
const pageCount = computed(() => 3 + agentPages.value.length)

// ─── Téléchargement du PDF ────────────────────────────────────────────────────
const pdfName = computed(() => `Performance centres – ${periodLabel.value}`)
const generating = ref(false)

// Police du site (Instrument Sans, servie par fonts.bunny.net) intégrée au PDF.
// html-to-image ne peut pas lire la feuille de style externe : on télécharge les fichiers nous-mêmes.
async function fontEmbedCss() {
    const toDataUrl = async (url) => {
        const blob = await (await fetch(url)).blob()
        return new Promise((resolve) => {
            const reader = new FileReader()
            reader.onload = () => resolve(reader.result)
            reader.readAsDataURL(blob)
        })
    }
    const faces = await Promise.all([400, 500, 600].map(async (weight) => {
        const src = await toDataUrl(`https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-${weight}-normal.woff2`)
        return `@font-face { font-family: 'Instrument Sans'; font-style: normal; font-weight: ${weight}; src: url(${src}) format('woff2'); }`
    }))
    return faces.join('\n')
}

async function downloadPdf() {
    if (generating.value) return
    generating.value = true
    try {
        const [{ jsPDF }, { toPng }] = await Promise.all([import('jspdf'), import('html-to-image')])
        // Sans la police (réseau indisponible…), on garde la police de secours plutôt que d'échouer
        const fontCss = await fontEmbedCss().catch(() => '')
        const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true })
        const sheets = [...document.querySelectorAll('.report .sheet')]
        swaps = await chartsToImages()
        for (const [i, sheet] of sheets.entries()) {
            const png = await toPng(sheet, {
                pixelRatio: 2,
                backgroundColor: '#ffffff',
                style: { margin: '0', boxShadow: 'none' },
                fontEmbedCSS: fontCss,
                skipFonts: !fontCss,
                // Les graphiques d'origine font échouer la capture : on capture leur image à la place
                filter: (node) => !node.classList?.contains('apexcharts-canvas'),
            })
            if (i > 0) pdf.addPage()
            pdf.addImage(png, 'PNG', 0, 0, 297, 210, undefined, 'FAST')
        }
        pdf.save(`${pdfName.value}.pdf`)
    } catch (e) {
        error.value = `Erreur pendant la génération du PDF : ${e?.message ?? e}`
    } finally {
        restoreCharts(swaps)
        generating.value = false
    }
}

// Remplace chaque graphique Apex par une image PNG de son SVG (le temps de la capture).
// Les graphiques du rapport utilisent une police système, disponible aussi dans l'image.
let swaps = []
async function chartsToImages() {
    const result = []
    for (const canvas of document.querySelectorAll('.report .apexcharts-canvas')) {
        const svg = canvas.querySelector('svg')
        const source = new Image()
        source.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg))
        await source.decode()
        const raster = document.createElement('canvas')
        raster.width = svg.clientWidth * 2
        raster.height = svg.clientHeight * 2
        const ctx = raster.getContext('2d')
        ctx.scale(2, 2)
        ctx.drawImage(source, 0, 0)
        const img = new Image(svg.clientWidth, svg.clientHeight)
        img.src = raster.toDataURL('image/png')
        await img.decode()
        canvas.style.display = 'none'
        canvas.parentNode.insertBefore(img, canvas)
        result.push({ canvas, img })
    }
    return result
}
function restoreCharts(list) {
    for (const { canvas, img } of list) {
        img.remove()
        canvas.style.display = ''
    }
    swaps = []
}

const ready = computed(() => !!data.value)
</script>

<template>
    <div class="report min-h-screen bg-gray-100 py-6 print:bg-white print:py-0">
        <!-- Barre d'actions (hors PDF) -->
        <div class="mx-auto mb-4 flex max-w-[297mm] items-center justify-between px-2 print:hidden">
            <div class="text-sm text-gray-600">
                Aperçu · {{ modeLabel }} · {{ pageCount }} page{{ pageCount > 1 ? 's' : '' }}
            </div>
            <button
                type="button"
                class="flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                :disabled="!ready || generating"
                @click="downloadPdf"
            >
                <Download class="h-4 w-4" />
                {{ generating ? 'Génération…' : 'Télécharger le PDF' }}
            </button>
        </div>

        <p v-if="error" class="mx-auto max-w-[297mm] rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <div v-else-if="!ready" class="mx-auto max-w-[297mm] p-10 text-center text-sm text-gray-500">Chargement…</div>

        <template v-else>
            <!-- ─── Page 1 : synthèse ─────────────────────────────────────── -->
            <section class="sheet">
                <header class="mb-3 flex items-end justify-between border-b-2 border-gray-900 pb-2.5">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Performance des centres d'appels</div>
                        <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ periodLabel }}</h1>
                    </div>
                    <div class="text-right text-xs text-gray-500">
                        <div v-if="previousLabel">Évolutions comparées au {{ previousLabel }}</div>
                    </div>
                </header>

                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="py-1.5 text-left font-medium text-gray-500" />
                            <th
                                v-for="c in columns"
                                :key="c.id"
                                class="py-1.5 pl-4 pr-3 text-right font-semibold"
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
                            <td :colspan="columns.length + 1" class="pb-0.5 pt-2.5 text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ s.title }}</td>
                        </tr>
                        <tr v-for="r in s.rows" :key="s.title + r.key" class="border-b border-gray-100">
                            <td class="py-[3px] text-gray-700" :class="{ 'pl-4 text-gray-500': r.indent }">{{ r.label }}</td>
                            <td
                                v-for="c in columns"
                                :key="c.id"
                                class="py-[3px] pl-4 pr-3 text-right tabular-nums"
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

                <p class="mt-3 text-[9px] leading-snug text-gray-500">
                    Journée-agent : un agent qui a pris au moins un appel dans la journée. Ratio temps agent : part de la durée d'un appel
                    où un agent est mobilisé (plus il est bas, mieux c'est). Conversation / pause : répartition mesurée sur les appels où
                    l'application agent l'a enregistrée.
                    <template v-if="withIndices">
                        Indices : 100 = moyenne de l'ensemble des centres sur la période (pondérée par l'activité) ; 120 = 20 % au-dessus de
                        cette moyenne. Indice / jour d'un agent : sa production par jour comparée à la même moyenne.
                    </template>
                    <template v-if="withMoney && centers.some((c) => c.cost_estimated)"> Période partielle : coût mensuel réparti au prorata des jours.</template>
                </p>
                <footer class="sheet-footer">
                    <span>Performance des centres d'appels · {{ periodLabel }}</span>
                    <span>{{ 1 }} / {{ pageCount }}</span>
                </footer>
            </section>

            <!-- ─── Page 2 : comparaison des centres sur la période ───────── -->
            <section class="sheet">
                <h2 class="text-lg font-semibold text-gray-900">Comparaison des centres</h2>
                <p class="mb-5 mt-0.5 text-xs text-gray-500">
                    Sur toute la période.
                    <template v-if="previousLabel">
                        Le trait noir sur chaque barre indique la valeur de la période précédente ({{ previousLabel }}).
                    </template>
                </p>

                <div class="grid grid-cols-3 gap-10">
                    <div v-for="block in comparisonBlocks" :key="block.key">
                        <div class="text-sm font-semibold text-gray-900">{{ block.title }}</div>
                        <div class="mb-3 min-h-8 text-[11px] leading-snug text-gray-500">{{ block.hint }}</div>
                        <div class="space-y-2.5">
                            <div v-for="e in comparisonRows" :key="e.id">
                                <div class="mb-1 flex items-baseline justify-between text-[11px]">
                                    <span :class="e.id === '_all' ? 'text-gray-500' : 'font-medium text-gray-800'">{{ e.label }}</span>
                                    <span class="tabular-nums">
                                        <span class="text-[13px] font-semibold" :class="e.id === '_all' ? 'text-gray-500' : 'text-gray-900'">
                                            {{ block.format(blockValue(e, block.key)) }}
                                        </span>
                                        <span
                                            v-if="!(e.id === '_all' && block.key === 'index_production') && variation(blockValue(e, block.key), blockValue(e, block.key, true)) !== null"
                                            class="ml-1.5 text-[10px] text-gray-400"
                                        >{{ fmt.variation(variation(blockValue(e, block.key), blockValue(e, block.key, true))) }}</span>
                                    </span>
                                </div>
                                <div class="relative h-3.5 rounded-sm bg-gray-100">
                                    <div
                                        class="h-full rounded-sm"
                                        :style="{
                                            width: `${((blockValue(e, block.key) ?? 0) / blockMax(block.key)) * 100}%`,
                                            background: e.id === '_all' ? '#d1d5db' : e.color,
                                        }"
                                    />
                                    <!-- Repère : période précédente -->
                                    <div
                                        v-if="blockValue(e, block.key, true) !== null"
                                        class="absolute -top-0.5 h-[18px] w-[2px] bg-gray-900"
                                        :style="{ left: `calc(${(blockValue(e, block.key, true) / blockMax(block.key)) * 100}% - 1px)` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-9 mb-2.5 flex items-baseline justify-between gap-4">
                    <h2 class="text-sm font-semibold text-gray-900">Composition moyenne d'un appel</h2>
                    <div class="flex flex-wrap gap-4 text-[11px] text-gray-600">
                        <span v-for="p in PHASES" :key="p.key" class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: p.color }" />
                            {{ p.label }}
                        </span>
                    </div>
                </div>
                <div class="space-y-3">
                    <div v-for="c in centers" :key="c.id" class="flex items-start gap-4">
                        <div class="w-32 shrink-0 text-xs font-medium leading-tight text-gray-900">
                            {{ c.label }}
                            <div class="mt-0.5 font-normal text-gray-500">{{ fmt.dur(c.m.avg_total) }} au total</div>
                        </div>
                        <div class="flex-1">
                            <div class="flex h-5 gap-0.5">
                                <div
                                    v-for="p in PHASES"
                                    :key="p.key"
                                    class="flex items-center justify-center overflow-hidden text-[10px] font-medium text-white first:rounded-l last:rounded-r"
                                    :style="{ width: `${phaseShare(c, p.key) * 100}%`, background: p.color }"
                                >
                                    <span v-if="phaseShare(c, p.key) > 0.035" class="truncate px-0.5">{{ fmt.dur(c.m[p.key]) }}</span>
                                </div>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-5 text-[10px] text-gray-500">
                                <span v-for="p in PHASES" :key="p.key">
                                    {{ p.label }} <span class="font-medium text-gray-800">{{ fmt.dur(c.m[p.key]) }}</span> ({{ phasePercent(c, p.key) }})
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <footer class="sheet-footer">
                    <span>Performance des centres d'appels · {{ periodLabel }}</span>
                    <span>2 / {{ pageCount }}</span>
                </footer>
            </section>

            <!-- ─── Page 3 : évolution jour par jour ─────────────────────── -->
            <section class="sheet">
                <div class="mb-4 flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-semibold text-gray-900">Évolution jour par jour</h2>
                    <div class="flex flex-wrap items-center gap-4 text-[11px] text-gray-600">
                        <span v-for="c in centers" :key="c.id" class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ background: c.color }" />
                            {{ c.label }}
                        </span>
                        <span v-if="hasPrevious" class="flex items-center gap-1.5 text-gray-500">
                            <span class="w-5 border-t-2 border-dashed border-gray-400" />
                            même jour, semaine précédente
                        </span>
                    </div>
                </div>
                <div class="space-y-5">
                    <div v-for="chart in evolutionCharts" :key="chart.key">
                        <div class="text-sm font-semibold text-gray-900">
                            {{ chart.title }}
                            <span v-if="chart.subtitle" class="text-xs font-normal text-gray-500">· {{ chart.subtitle }}</span>
                        </div>
                        <div class="grid items-center gap-6" :class="valueTables ? 'grid-cols-[minmax(0,1fr)_auto]' : 'grid-cols-1'">
                            <div>
                                <TrendChart
                                    :categories="chartDays"
                                    :series="chart.series"
                                    :format="chart.format"
                                    :height="valueTables ? 235 : 255"
                                    :legend="false"
                                    :font-family="CHART_FONT"
                                />
                            </div>
                            <!-- Sur une semaine : les valeurs à côté du graphique -->
                            <table v-if="valueTables" class="value-table w-auto">
                                <thead>
                                    <tr>
                                        <th />
                                        <th v-for="d in chartDays" :key="d">{{ d }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(serie, i) in chart.series.filter((x) => !x.dashed)" :key="i">
                                        <td>
                                            <span class="inline-block h-2 w-2 rounded-full align-middle" :style="{ background: serie.color }" />
                                            {{ serie.name }}
                                        </td>
                                        <td v-for="(v, j) in serie.data" :key="j">{{ v === null ? '–' : chart.format(v) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <footer class="sheet-footer">
                    <span>Performance des centres d'appels · {{ periodLabel }}</span>
                    <span>3 / {{ pageCount }}</span>
                </footer>
            </section>

            <!-- ─── Pages suivantes : agents ──────────────────────────────── -->
            <section v-for="(page, p) in agentPages" :key="'agents-' + p" class="sheet">
                <div v-for="block in page" :key="block.center.id + block.continued" class="mb-5">
                    <h2 class="mb-2 flex items-center gap-2 text-base font-semibold text-gray-900">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: block.center.color }" />
                        Agents · {{ block.center.label }}
                        <span class="text-xs font-normal text-gray-500">
                            {{ block.continued ? '(suite)' : `${block.total} agent${block.total > 1 ? 's' : ''}` }}
                        </span>
                    </h2>
                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="border-b border-gray-300 text-gray-500">
                                <th class="py-1 text-left font-medium">Agent</th>
                                <th v-for="col in AGENT_COLUMNS" :key="col.key" class="py-1 pl-3 text-right font-medium">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in block.agents" :key="a.name" class="border-b border-gray-100">
                                <td class="py-1 font-medium text-gray-900">{{ a.name }}</td>
                                <td v-for="col in AGENT_COLUMNS" :key="col.key" class="py-1 pl-3 text-right tabular-nums text-gray-700">{{ col.format(a.m[col.key]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <footer class="sheet-footer">
                    <span>Performance des centres d'appels · {{ periodLabel }}</span>
                    <span>{{ 4 + p }} / {{ pageCount }}</span>
                </footer>
            </section>
        </template>
    </div>
</template>

<style>
@page {
    size: A4 landscape;
    margin: 0;
}

/* Une feuille = une page A4 paysage, à l'écran comme dans le PDF */
.report .sheet {
    position: relative;
    box-sizing: border-box;
    width: 297mm;
    height: 210mm;
    overflow: hidden;
    margin: 0 auto 16px;
    padding: 12mm 12mm 16mm;
    background: #fff;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
}

.report .value-table {
    width: 100%;
    margin-top: 2px;
    font-size: 10px;
    font-variant-numeric: tabular-nums;
    color: #374151;
}

.report .value-table th {
    padding: 1px 4px;
    font-weight: 500;
    color: #9ca3af;
    text-align: right;
}

.report .value-table td {
    padding: 2px 5px;
    border-top: 1px solid #f3f4f6;
    text-align: right;
    white-space: nowrap;
}

.report .value-table td:first-child {
    text-align: left;
    color: #6b7280;
}

.report .sheet-footer {
    position: absolute;
    right: 12mm;
    bottom: 7mm;
    left: 12mm;
    display: flex;
    justify-content: space-between;
    font-size: 9px;
    color: #9ca3af;
}

@media print {
    .report .sheet {
        margin: 0;
        box-shadow: none;
        break-after: page;
    }

    .report * {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>
