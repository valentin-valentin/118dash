<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import FilterBar from '@/components/FilterBar.vue'
import { Input } from '@/components/ui/input'
import { addDays, iso } from '@/lib/performance'

/**
 * Attentes et agents (GET /pilotage de voxnode118), tous les chiffres viennent de l'API.
 *   1. Devenir des appels : décrochés (par attente), perdus (à quelle étape), attentes, par plateforme
 *   2. Quand les appelants raccrochent : perdus par temps passé avant de raccrocher
 *   3. Vue d'ensemble : appels et agents d'un jour, demi-heure par demi-heure, sur les mêmes colonnes
 *   4. Heure par heure : une case par demi-heure, au clic le détail de la demi-heure
 *   5. Créneaux à revoir, 6. Jour par jour
 * Agents nécessaires : ce qu'il fallait pour décrocher answerShare % des appels en moins de « within » secondes de vraie
 * attente (calcul de l'API, pour tout le centre d'appels seulement).
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
// Dates du jour à l'heure de Paris, quel que soit le fuseau de l'ordinateur (les journées de l'API sont à Paris)
const parisDay = (offset = 0) => {
    const [y, m, d] = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date()).split('-').map(Number)
    return iso(addDays(new Date(y, m - 1, d), offset))
}
// État lu dans l'adresse (lien partageable), écrit à chaque changement (history.replaceState)
const urlState = (() => {
    try {
        return new URLSearchParams(window.location.search)
    } catch {
        return new URLSearchParams()
    }
})()
const preset = ref(PERIODS.some((x) => x.key === urlState.get('period')) ? urlState.get('period') : 'today')
const pickedDay = ref(/^\d{4}-\d{2}-\d{2}$/.test(urlState.get('date') ?? '') ? urlState.get('date') : parisDay(-2))
const team = ref(urlState.get('team') || 'all')

// Attente maximale du calcul des agents nécessaires, gardée dans le navigateur
const WITHIN_CHOICES = [20, 30, 45, 60, 90, 120]
const within = ref(45)
try {
    const saved = Number(urlState.get('within') ?? localStorage.getItem('pilotage.within'))
    if (WITHIN_CHOICES.includes(saved)) within.value = saved
} catch {
    // stockage indisponible : valeur par défaut
}
watch(within, (v) => {
    try {
        localStorage.setItem('pilotage.within', String(v))
    } catch {
        // stockage indisponible
    }
})

const query = computed(() => {
    const p = PERIODS.find((x) => x.key === preset.value)
    const base = { team: team.value, within: within.value }
    if (preset.value === 'yesterday') return { period: 'day', date: parisDay(-1), ...base }
    if (preset.value === 'day') return { period: 'day', date: pickedDay.value, ...base }
    if (p.weeks) return { period: 'weeks', weeks: p.weeks, ...base }
    return { period: preset.value, ...base }
})

const data = ref(null)
const loading = ref(false)
const error = ref(null)
// Requête des données affichées : un rafraîchissement raté garde les données, un changement de filtre raté les retire
let dataKey = null
let requestId = 0
async function load() {
    if (query.value.period === 'day' && !query.value.date) return
    const id = ++requestId
    const key = JSON.stringify(query.value)
    loading.value = true
    const fail = (message) => {
        if (id !== requestId) return
        error.value = message
        if (dataKey !== key) data.value = null
    }
    try {
        const res = await fetch(`/data/pilotage?${new URLSearchParams(query.value)}`, { headers: { Accept: 'application/json' } })
        const json = res.ok ? await res.json() : { ok: false, error: `Erreur HTTP ${res.status}` }
        if (id !== requestId) return
        if (json.ok) {
            data.value = json.data
            dataKey = key
            error.value = null
        } else {
            fail(json.error)
        }
    } catch (e) {
        fail(e.message)
    } finally {
        if (id === requestId) loading.value = false
    }
}
watch(query, (q, old) => JSON.stringify(q) !== JSON.stringify(old) && load(), { immediate: true })

// Gardées d'un chargement à l'autre : le filtre reste utilisable si un chargement échoue
const teams = ref([{ id: 'all', name: 'Tous' }])
watch(data, (d) => d?.teams?.length && (teams.value = d.teams))
const teamName = computed(() => teams.value.find((t) => t.id === data.value?.team)?.name ?? data.value?.team)
const live = computed(() => (data.value?.rows ?? []).some((r) => r.live))

// Relu toutes les 5 min dès qu'une ligne est en direct
let timer = null
onMounted(() => (timer = setInterval(() => live.value && document.visibilityState === 'visible' && load(), 5 * 60 * 1000)))
onUnmounted(() => clearInterval(timer))

// ─── Formats ──────────────────────────────────────────────────────────────────
const fmt = (n) => (n == null || isNaN(n) ? '–' : Math.round(n).toLocaleString('fr-FR'))
const fmt1 = (n) => (n == null || isNaN(n) ? '–' : (Math.round(n * 10) / 10).toLocaleString('fr-FR'))
const pct = (n, d) => (d ? `${(Math.round((n / d) * 1000) / 10).toLocaleString('fr-FR')} %` : '')
const mmss = (s) => (s == null ? '–' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`)
const gapTxt = (g) => {
    if (g == null || isNaN(g)) return '–'
    const r = Math.round(g) || 0
    return (r > 0 ? '+' : r < 0 ? '−' : '') + Math.abs(r).toLocaleString('fr-FR')
}
const endOf = (s) => {
    const m = +s.slice(0, 2) * 60 + +s.slice(3) + 30
    return `${String((m / 60) | 0).padStart(2, '0')}:${m % 60 ? '30' : '00'}`
}
const dayLabel = (d) => new Date(`${d}T12:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit' })
const PLATS = [
    ['all', 'Ensemble'],
    ['server', 'Notre serveur'],
    ['voxnode', 'Voxnode'],
]
const COLS = [
    ['server', 'Notre serveur'],
    ['voxnode', 'Voxnode'],
    ['all', 'Ensemble'],
]

// 0 mesuré n'est pas 0 : étape des perdus inconnue pour tous, ou vraie attente jamais mesurée
const stageUnmeasured = (x) => (x?.lost ?? 0) > 0 && (x.lostUnknown ?? 0) >= x.lost
const lateUnmeasured = (x) => (x?.answered ?? 0) > 0 && x.realWaitMeasured === 0
const NOT_MEASURED = { text: 'non mesuré', muted: true }

// ─── 1. Devenir des appels ────────────────────────────────────────────────────
const REAL_WAITS = ['en moins de 30 s', 'de 30 s à 1 min', 'de 1 à 2 min', 'de 2 à 5 min', 'après plus de 5 min']
const ANSWER_WAITS = ['en moins de 30 s', 'de 30 s à 1 min', 'de 1 à 3 min', 'de 3 à 5 min', 'de 5 à 8 min', 'après plus de 8 min']
// Quatre petits tableaux côte à côte (notre serveur, Voxnode, ensemble) : vue générale, puis le détail des décrochés,
// des perdus et des attentes mesurées
const fateGroups = computed(() => {
    const F = data.value?.fate
    if (!F) return []
    const unknown = (x) => x.lostUnknown ?? 0
    const anyUnknown = COLS.some(([k]) => unknown(F[k] ?? {}) > 0)
    // Vraie attente : mesurée seulement sur une partie des appels (notre serveur, Voxnode suivi en direct)
    const real = (f) => (x) => (x.realWaitMeasured ? f(x) : { text: 'non mesuré', muted: true })
    return [
        {
            title: 'Appels',
            rows: [
                { label: 'Appels', strong: true, cell: (x) => ({ text: fmt(x.calls) }) },
                { label: 'Décrochés par un agent', cell: (x) => ({ text: fmt(x.answered), pct: pct(x.answered, x.calls) }) },
                { label: 'Perdus', cell: (x) => ({ text: fmt(x.lost), pct: pct(x.lost, x.calls) }) },
                {
                    label: "dont en attente d'un agent",
                    sub: true,
                    cell: (x) =>
                        stageUnmeasured(x)
                            ? NOT_MEASURED
                            : { text: fmt(x.lostQueue), red: true, pct: pct(x.lostQueue, x.calls), extra: unknown(x) > 0 ? `+ ${fmt(unknown(x))} non mesurés` : '' },
                },
                // Appels = décrochés + perdus + ni l'un ni l'autre (sans durée, refusés avant sonnerie, en cours)
                ...(COLS.some(([k]) => (F[k]?.other ?? 0) > 0)
                    ? [{ label: 'Ni décrochés ni perdus', cell: (x) => ({ text: fmt(x.other ?? 0), pct: pct(x.other ?? 0, x.calls) }) }]
                    : []),
            ],
        },
        {
            title: 'Perdus',
            rows: [
                { heading: 'Moment où ils raccrochent, en % des perdus' },
                { label: "Accueil ou attente forcée", cell: (x) => (stageUnmeasured(x) ? NOT_MEASURED : { text: fmt(x.lostForced), pct: pct(x.lostForced, x.lost) }) },
                { label: "En attente d'un agent", cell: (x) => (stageUnmeasured(x) ? NOT_MEASURED : { text: fmt(x.lostQueue), red: true, pct: pct(x.lostQueue, x.lost) }) },
                ...(anyUnknown ? [{ label: 'Étape non mesurée', cell: (x) => ({ text: fmt(unknown(x)), muted: unknown(x) === 0, grey: true, pct: pct(unknown(x), x.lost) }) }] : []),
                { heading: 'Temps avant de raccrocher' },
                { label: 'moyenne', cell: (x) => ({ text: mmss(x.lostWaitAvgSec) }) },
                { label: 'médiane', cell: (x) => ({ text: mmss(x.lostWaitMedianSec) }) },
            ],
        },
        {
            title: 'Décrochés',
            rows: [
                { heading: 'Attente totale avant décroché, accueil compris' },
                ...ANSWER_WAITS.map((label, i) => ({ label, cell: (x) => ({ text: fmt(x.answeredByWait?.[i]?.calls ?? 0), pct: pct(x.answeredByWait?.[i]?.calls ?? 0, x.answered) }) })),
                { label: 'moyenne', sep: true, cell: (x) => ({ text: mmss(x.answerWaitAvgSec) }) },
                { label: 'médiane', cell: (x) => ({ text: mmss(x.answerWaitMedianSec) }) },
            ],
        },
        {
            title: 'Attentes mesurées',
            rows: [
                { heading: 'Attente forcée faite (y compris ceux qui raccrochent pendant)' },
                { label: 'moyenne', cell: (x) => (x.forcedWaitAvgSec == null ? NOT_MEASURED : { text: mmss(x.forcedWaitAvgSec) }) },
                { label: 'médiane', cell: (x) => (x.forcedWaitMedianSec == null ? NOT_MEASURED : { text: mmss(x.forcedWaitMedianSec) }) },
                { heading: "Vraie attente d'un agent, après l'attente forcée" },
                { label: 'moyenne', cell: real((x) => ({ text: mmss(x.realWaitAvgSec) })) },
                { label: 'médiane', cell: real((x) => ({ text: mmss(x.realWaitMedianSec) })) },
                // Répartition complète de la vraie attente des décrochés mesurés (100 %) ; un seul « non mesuré » par colonne
                ...REAL_WAITS.map((label, i) => ({
                    label,
                    sep: i === 0,
                    cell: (x) =>
                        x.realWaitMeasured
                            ? { text: fmt(x.realWaitByWait?.[i]?.calls ?? 0), pct: pct(x.realWaitByWait?.[i]?.calls ?? 0, x.realWaitMeasured) }
                            : { text: i === 0 ? 'non mesuré' : '', muted: true },
                })),
            ],
        },
    ]
})
const outsideHours = computed(() => data.value?.fate?.outsideHours ?? null)

// ─── 2. Quand les appelants raccrochent ───────────────────────────────────────
const dur = (s) => (s < 60 ? `${s} s` : `${s / 60} min`)
const bucketLabel = (b) => {
    if (b.to == null) return `plus de ${dur(b.from)}`
    if (b.from < 60 && b.to <= 60) return b.to === 60 ? `${b.from} s – 1 min` : `${b.from} – ${b.to} s`
    return `${b.from / 60} – ${b.to / 60} min`
}
// Un petit tableau par plateforme : perdus par temps passé avant de raccrocher, dont en attente d'un agent, barre
const hangups = computed(() => {
    const H = data.value?.hangups
    const F = data.value?.fate
    if (!H || !F) return null
    const total = (s) => (s ? (s.forced ?? 0) + (s.queue ?? 0) + (s.unknown ?? 0) : 0)
    // Même échelle de barres pour les trois tableaux : part des appels de la plateforme
    const max = Math.max(1e-9, ...COLS.flatMap(([k]) => (H[k] ?? []).map((s) => (F[k]?.calls ? total(s) / F[k].calls : 0))))
    return {
        tables: COLS.map(([k, label]) => {
            const calls = F[k]?.calls ?? 0
            return {
                key: k,
                title: label,
                note: `${fmt(F[k]?.lost)} perdus sur ${fmt(calls)} appels`,
                rows: H.buckets.map((b, i) => {
                    const sl = H[k]?.[i] ?? {}
                    const t = total(sl)
                    // Étape inconnue pour tous les perdus de la tranche : « dont en attente d'un agent » non mesuré
                    const unmeasured = (sl.unknown ?? 0) > 0 && !sl.forced && !sl.queue
                    return {
                        label: bucketLabel(b),
                        total: t,
                        pct: t ? pct(t, calls) : '',
                        queue: unmeasured ? null : (sl.queue ?? 0),
                        parts: ['forced', 'queue', 'unknown'].filter((c) => sl[c]).map((c) => ({ c, width: `${calls ? (sl[c] / calls / max) * 100 : 0}%`, n: sl[c] })),
                    }
                }),
            }
        }),
        forcedText: `Attente forcée faite, médiane : notre serveur ${F.server?.forcedWaitMedianSec == null ? 'non mesurée' : mmss(F.server.forcedWaitMedianSec)}, Voxnode ${F.voxnode?.forcedWaitMedianSec == null ? 'non mesurée' : mmss(F.voxnode.forcedWaitMedianSec)}`,
    }
})

// ─── Cellules ─────────────────────────────────────────────────────────────────
// Chiffres d'une demi-heure pour une plateforme (appels) et pour les agents (les deux plateformes)
const fig = (cell, p) => {
    if (!cell || cell.state !== 'open') return null
    const c = cell[p] ?? {}
    return {
        calls: c.calls ?? 0,
        ans: c.answered ?? 0,
        late: c.late ?? 0,
        lf: c.lostForced ?? 0,
        lq: c.lostQueue ?? 0,
        lost: (c.lostForced ?? 0) + (c.lostQueue ?? 0) + (c.lostUnknown ?? 0),
        unknown: c.lostUnknown ?? 0,
        realMeasured: c.realWaitMeasured,
        stageUnm: stageUnmeasured({ lost: (c.lostForced ?? 0) + (c.lostQueue ?? 0) + (c.lostUnknown ?? 0), lostUnknown: c.lostUnknown }),
        lateUnm: lateUnmeasured(c),
        wait: c.waitSec,
        realWait: c.realWaitSec,
        level: c.level ?? 'none',
        ...(cell.agents ?? {}),
        // agents.answered : décrochés par les agents affichés (l'équipe en vue équipe)
        live: cell.live ?? null,
        need: cell.need,
        gap: cell.gap,
        plannedGap: cell.plannedGap,
        plannedGapLevel: cell.plannedGapLevel,
        gapLevel: cell.gapLevel,
        causes: cell.causes ?? null,
        // Perdus en attente d'un agent alors que des agents étaient libres (mesuré minute par minute)
        lostDespiteFree: !!cell.lostDespiteFree,
    }
}
// Une seule définition par chiffre : « libres » = téléphones relevés uniquement ; agents.free (feuille − en appel) = « présents sans appel »
const freeOf = (f) => (f.live ? (f.live.server?.free ?? 0) + (f.live.voxnode?.free ?? 0) : null)
// Écart : agents joignables (téléphone connecté) − nécessaires, seulement là où les téléphones sont relevés
// Deux écarts, deux noms : planning (feuille de présence) et réel (téléphones relevés) ; la différence = les injoignables
const GAP_LABEL = 'Écart réel (joignables − nécessaires)'
const PLANNED_LABEL = 'Écart de planning (présents − nécessaires)'
const LONG_WAIT_TEXT = "Longue attente : 5 min ou plus de vraie attente d'un agent, sur les appels où elle est mesurée"
const allRows = computed(() => [...(data.value?.rows ?? []), ...(data.value?.average ? [data.value.average] : [])])
const cellOf = (row, slot) => row?.cells.find((c) => c.slot === slot)
// Moyennes (plusieurs jours) : une décimale
const isAvg = (row) => (row?.days ?? 1) > 1

// ─── 3. Vue d'ensemble ────────────────────────────────────────────────────────
const ovDay = ref(urlState.get('ov') || null)
const ovPlat = ref(PLATS.some(([k]) => k === urlState.get('ovplat')) ? urlState.get('ovplat') : 'all')
const ovDefault = () => (data.value?.average ? 'average' : (data.value?.rows?.at(-1)?.key ?? null))
watch(allRows, (list) => {
    if (!list.some((r) => r.key === ovDay.value)) ovDay.value = ovDefault()
})
const ovRow = computed(() => allRows.value.find((r) => r.key === ovDay.value) ?? null)
const ovCols = computed(() => (ovRow.value ? ovRow.value.cells.filter((c) => c.state !== 'closed').map((c) => c.slot) : []))
const ov = computed(() => {
    const row = ovRow.value
    if (!row) return null
    const F = Object.fromEntries(ovCols.value.map((s) => [s, fig(cellOf(row, s), ovPlat.value)]))
    const figs = Object.values(F).filter(Boolean)
    const maxC = Math.max(0, ...figs.map((f) => f.calls))
    const maxA = Math.max(0, ...figs.map((f) => Math.max((f.present ?? 0) + (f.pause ?? 0) + (f.lunch ?? 0), f.need ?? 0)))
    const f1 = isAvg(row) ? fmt1 : fmt
    const v = (f, fn) => (f ? fn(f) : '')
    const line = (label, fn, cls = '', bad = () => false, optional = false) => ({
        optional,
        label,
        cls,
        cells: ovCols.value.map((s) => {
            const f = F[s]
            const text = v(f, fn)
            return { text, bad: !!f && bad(f), zero: text === '0' || text === '0,0' }
        }),
    })
    const showNeed = data.value?.needShown
    return {
        F,
        maxC,
        maxA,
        h: (val, max, px) => `${max ? (val / max) * px : 0}px`,
        calls: [
            line('Appels', (f) => f1(f.calls)),
            line('Décrochés', (f) => f1(f.ans)),
            line('dont après une longue attente', (f) => (f.lateUnm ? '–' : f1(f.late)), 'l1', (f) => f.late >= 1),
            line('Perdus à l\'accueil', (f) => (f.stageUnm ? '–' : f1(f.lf))),
            line("Perdus en attente d'un agent", (f) => (f.stageUnm ? '–' : f1(f.lq)), '', (f) => f.lq >= 1),
            ...(figs.some((f) => f.unknown > 0) ? [line('Perdus, étape non mesurée', (f) => f1(f.unknown), 'l1 muted')] : []),
            line('Attente moyenne', (f) => (f.wait == null ? '–' : mmss(f.wait))),
            line('Vraie attente moyenne', (f) => (f.realWait == null ? '–' : mmss(f.realWait))),
        ],
        agents: [
            ...(showNeed
                ? [
                      line('Nécessaires', (f) => fmt1(f.need), '', () => false, true),
                      line('Écart de planning', (f) => gapTxt(f.plannedGap), '', (f) => f.plannedGap != null && Math.round(f.plannedGap) < 0, true),
                      line('Écart réel', (f) => gapTxt(f.gap), '', (f) => f.gap != null && Math.round(f.gap) < 0, true),
                  ]
                : []),
            line('Décrochés par ces agents', (f) => f1(f.answered)),
            line('Présents (feuille)', (f) => fmt1(f.present)),
            line('en appel', (f) => fmt1(f.busy), 'l1'),
            line('occupation', (f) => (f.occupancyPct == null ? '–' : `${fmt(f.occupancyPct)} %`), 'l1'),
            line('présents sans appel', (f) => fmt1(f.free), 'l1'),
            // Téléphones relevés minute par minute : joignables par plateforme, injoignables
            ...(figs.some((f) => f.live)
                ? [
                      line('Joignables (téléphone)', (f) => (f.live ? fmt1(f.live.reachable) : '–')),
                      line('notre serveur', (f) => (f.live ? fmt1(f.live.server?.reachable) : '–'), 'l1'),
                      line('Voxnode', (f) => (f.live ? fmt1(f.live.voxnode?.reachable) : '–'), 'l1'),
                      line('Libres (téléphone)', (f) => (f.live ? fmt1(freeOf(f)) : '–'), '', (f) => f.live && freeOf(f) < 1 && f.calls > 2),
                      line('Injoignables', (f) => (f.live ? fmt1(f.live.unavailable) : '–'), '', (f) => (f.live?.unavailable ?? 0) >= 1),
                  ]
                : []),
            line('En pause', (f) => fmt1(f.pause)),
            line('En déjeuner', (f) => fmt1(f.lunch)),
            line('Sonnés sans réponse', (f) => f1(f.missed)),
        ].filter((l) => !l.optional || l.cells.some((c) => c.text && c.text !== '–')),
    }
})
const STAT_CLASS = { short: 'queue', warn: 'late', ok: 'free', over: 'over', none: '', unmeasured: '' }

// ─── 4. Heure par heure ───────────────────────────────────────────────────────
const NEED_MODES = ['planned', 'gap', 'need']
// Agents d'une plateforme : seulement avec le relevé des téléphones (live[p]), « – » sans relevé
const livePart = (f, p) => (p === 'all' ? null : (f.live?.[p] ?? undefined))
const agentValue = (f, p, all, part) => {
    if (p === 'all') return all(f)
    const l = livePart(f, p)
    return l ? part(l) : null
}
const MODES = [
    { key: 'planned', label: 'Écart de planning', name: 'écart de planning (présents sur la feuille − nécessaires)', value: (f) => gapTxt(f.plannedGap), agents: true, whole: true },
    { key: 'gap', label: 'Écart réel', name: 'écart réel (joignables − nécessaires)', value: (f) => gapTxt(f.gap), agents: true, whole: true },
    { key: 'need', label: 'Nécessaires', name: 'agents nécessaires', value: (f) => fmt(f.need), agents: true, whole: true },
    // Occupation : temps en appel / temps de présence, sans modèle ; couleur = niveau des pertes de la case
    { key: 'occupancy', label: 'Occupation', name: 'occupation (en appel / présence)', value: (f) => (f.occupancyPct == null ? '–' : `${fmt(f.occupancyPct)} %`), agents: true, sheet: true },
    { key: 'present', label: 'Présents (feuille)', name: 'agents présents (feuille de présence)', value: (f) => fmt1(f.present), agents: true, sheet: true },
    { key: 'reachable', label: 'Joignables', name: 'agents joignables (téléphone connecté)', value: (f, p) => fmt1(agentValue(f, p, (x) => x.live?.reachable ?? null, (l) => l.reachable)), agents: true },
    { key: 'free', label: 'Libres', name: 'agents libres (téléphones relevés)', value: (f, p) => fmt1(agentValue(f, p, freeOf, (l) => l.free)), agents: true },
    { key: 'breaks', label: 'En pause ou déjeuner', name: 'agents en pause ou déjeuner', value: (f, p) => fmt1(agentValue(f, p, (x) => (x.pause ?? 0) + (x.lunch ?? 0), (l) => (l.pause ?? 0) + (l.lunch ?? 0))), agents: true },
    {
        key: 'unavailable',
        label: 'Injoignables',
        name: 'agents injoignables (en poste, téléphone non connecté)',
        value: (f, p) => fmt1(p === 'all' ? (f.live?.unavailable ?? null) : (livePart(f, p)?.unavailable ?? null)),
        agents: true,
    },
    {
        key: 'freeWait',
        label: 'Attente avec agents libres',
        name: "minutes où un appelant attendait un agent depuis 30 s ou plus alors que des agents étaient libres",
        value: (f) => (f.causes ? fmt(f.causes.freeSame + f.causes.freeOther) : '–'),
        agents: true,
        whole: true,
    },
    { key: 'missed', label: 'Sonnés sans réponse', name: 'appels sonnés chez un agent sans réponse', value: (f) => fmt(f.missed), agents: true, whole: true },
    { key: 'lq', label: "Perdus en attente d'un agent", name: "appels perdus en attente d'un agent", value: (f) => (f.stageUnm ? '–' : fmt(f.lq)) },
    { key: 'lost', label: 'Perdus (tous)', name: 'appels perdus', value: (f) => fmt(f.lost) },
    { key: 'calls', label: 'Appels', name: 'appels', value: (f) => fmt(f.calls) },
    { key: 'realWait', label: 'Vraie attente moyenne', name: "vraie attente moyenne d'un agent", value: (f) => (f.realWait == null ? '–' : mmss(f.realWait)) },
]
const modeText = (v) => (typeof v === 'function' ? v(gPlat.value) : v)
const gPlat = ref(PLATS.some(([k]) => k === urlState.get('plat')) ? urlState.get('plat') : 'all')
const mode = ref(MODES.some((m) => m.key === urlState.get('mode')) ? urlState.get('mode') : 'planned')
// Mode choisi dans l'adresse : pas de repli automatique sur « perdus » à l'ouverture
let modeFromUrl = urlState.has('mode')
// Au moins une case de la grille avec le relevé des téléphones : agents par plateforme possibles
const anyLive = computed(() => (data.value?.rows ?? []).some((r) => r.cells.some((c) => c.live)))
const modeDisabled = (m) => {
    if (gPlat.value !== 'all' && m.whole)
        return m.key === 'missed'
            ? 'Les appels sonnés ne sont pas enregistrés par plateforme'
            : m.key === 'freeWait'
              ? "Mesuré pour tout le centre d'appels"
              : "Le besoin se calcule pour tout le centre d'appels"
    if (gPlat.value !== 'all' && m.sheet) return 'La feuille de présence ne se lit pas par plateforme'
    if (gPlat.value !== 'all' && m.agents && !anyLive.value) return 'Sans relevé des téléphones, les agents ne sont pas comptés par plateforme'
    if (NEED_MODES.includes(m.key) && data.value && !data.value.needShown) return "Le besoin se calcule pour tout le centre d'appels"
    return null
}
const currentMode = computed(() => MODES.find((m) => m.key === mode.value))
// Mode devenu indisponible (plateforme choisie, vue équipe) : perdus en attente d'un agent
// Ouverture sur l'écart de planning ; l'écart réel seulement si une case de la période a un relevé des téléphones
const anyDot = computed(() => (data.value?.rows ?? []).some((r) => r.cells.some((c) => c.lostDespiteFree)))
const anyGap = computed(() => (data.value?.rows ?? []).some((r) => r.cells.some((c) => c.gap != null)))
// Écart de planning à l'ouverture seulement si une case l'a (besoin mesuré), sinon l'occupation
const anyPlanned = computed(() => (data.value?.rows ?? []).some((r) => r.cells.some((c) => c.plannedGap != null)))
watch([gPlat, data], () => {
    if (!modeFromUrl) {
        if (mode.value === 'gap' && !anyGap.value) mode.value = 'planned'
        if (mode.value === 'planned' && !anyPlanned.value) mode.value = 'occupancy'
    }
    if (currentMode.value && modeDisabled(currentMode.value)) mode.value = !modeDisabled(MODES.find((m) => m.key === 'occupancy')) ? 'occupancy' : 'lq'
    modeFromUrl = false
})
const needMode = computed(() => NEED_MODES.includes(mode.value))
const cellClass = (cell) => {
    const f = fig(cell, gPlat.value)
    if (!f) return ''
    // Moins de 2 appels mesurés : case neutre, comme « none »
    const lvl = mode.value === 'gap' ? (f.gapLevel ?? 'none') : needMode.value ? (f.plannedGapLevel ?? 'none') : f.level === 'unmeasured' ? 'none' : f.level
    return [lvl, f.lostDespiteFree ? 'dot' : '']
}

// Demi-heure sélectionnée (clic) : par défaut le pire créneau de la période s'il est dans la grille
const sel = ref(urlState.get('sel')?.includes('~') ? { row: urlState.get('sel').split('~')[0], slot: urlState.get('sel').split('~')[1] } : null)
// Créneau cliqué dans « Créneaux à revoir » sur une période en semaines : le jour s'ouvre, puis la case est choisie
let pendingSel = null
watch(data, (d) => {
    const rows = d?.rows ?? []
    const has = (k) => k && rows.some((r) => r.key === k.row && cellOf(r, k.slot)?.state === 'open')
    if (has(pendingSel)) {
        sel.value = pendingSel
        pendingSel = null
        return
    }
    if (has(sel.value)) return
    const w = d?.worst?.[0]
    const fromWorst = w && { row: w.date, slot: w.slot }
    if (has(fromWorst)) return (sel.value = fromWorst)
    const r = rows.find((x) => x.cells.some((c) => c.state === 'open'))
    sel.value = r ? { row: r.key, slot: r.cells.find((c) => c.state === 'open').slot } : null
})
const isSel = (row, slot) => sel.value?.row === row.key && sel.value?.slot === slot
const detail = computed(() => {
    if (!sel.value) return null
    const row = allRows.value.find((r) => r.key === sel.value.row)
    const cell = cellOf(row, sel.value.slot)
    if (!cell || cell.state !== 'open') return null
    const x = Object.fromEntries(PLATS.map(([p]) => [p, fig(cell, p)]))
    // Minutes où un appelant attendait un agent depuis 30 s ou plus, selon les agents libres à ce moment
    const c = cell.causes
    const causes = c
        ? [
              [`Appelants en attente d'un agent depuis 30 s ou plus : ${fmt(c.waiting)} min sur 30`, null],
              ['dont avec des agents libres sur la même plateforme', `${fmt(c.freeSame)} min`],
              ['dont avec des agents libres seulement sur l\'autre plateforme', `${fmt(c.freeOther)} min`],
              ['dont sans aucun agent libre', `${fmt(c.noneFree)} min`],
          ]
        : null
    const measuredOn = (f) => (f.realMeasured != null && f.realMeasured < f.ans ? `sur ${fmt(f.realMeasured)} mesurés` : '')
    const a = x.all
    return {
        title: `${row.label}, ${cell.slot}–${endOf(cell.slot)}`,
        causes,
        causesDays: c?.days > 1 ? c.days : null,
        avg: isAvg(row),
        calls: [
            { label: 'Appels', v: (f) => ({ text: fmt(f.calls) }) },
            { label: 'Décrochés', v: (f) => ({ text: fmt(f.ans), pct: pct(f.ans, f.calls) }) },
            { label: 'dont après une longue attente', v: (f) => (f.lateUnm ? NOT_MEASURED : { text: fmt(f.late), red: f.late > 0, pct: measuredOn(f) }) },
            { label: "Perdus pendant l'accueil ou l'attente forcée", v: (f) => (f.stageUnm ? NOT_MEASURED : { text: fmt(f.lf) }) },
            { label: "Perdus en attente d'un agent", v: (f) => (f.stageUnm ? NOT_MEASURED : { text: fmt(f.lq), red: f.lq > 0, pct: f.unknown > 0 ? `+ ${fmt(f.unknown)} non mesurés` : '' }) },
            { label: 'Attente moyenne avant décroché', v: (f) => ({ text: f.wait == null ? '–' : mmss(f.wait) }) },
            { label: 'Vraie attente moyenne', v: (f) => ({ text: f.realWait == null ? '–' : mmss(f.realWait) }) },
        ].map((r) => ({ label: r.label, cells: PLATS.map(([p]) => r.v(x[p])) })),
        agents: [
            ...(data.value?.needShown ? [['Nécessaires', { text: fmt(a.need) }]] : []),
            ['Appels décrochés par ces agents', { text: fmt(a.answered) }],
            ['Présents (feuille de présence)', { text: fmt1(a.present) }],
            ...(a.live
                ? [
                      ['Joignables (téléphone connecté)', { text: fmt1(a.live.reachable) }],
                      [`Notre serveur : ${fmt1(a.live.server?.inCall)} en appel, ${fmt1(a.live.server?.free)} libres`, { text: '', sub: true }],
                      [`Voxnode : ${fmt1(a.live.voxnode?.inCall)} en appel, ${fmt1(a.live.voxnode?.free)} libres`, { text: '', sub: true }],
                      ['Injoignables (en poste, téléphone non connecté)', { text: fmt1(a.live.unavailable), red: a.live.unavailable >= 1 }],
                  ]
                : []),
            ...(data.value?.needShown
                ? [
                      [PLANNED_LABEL, { text: gapTxt(a.plannedGap), red: a.plannedGapLevel === 'short' }],
                      [GAP_LABEL, { text: gapTxt(a.gap), red: a.gapLevel === 'short' }],
                  ]
                : []),
            ['En appel', { text: fmt1(a.busy) }],
            ['Occupation (en appel / présence)', { text: a.occupancyPct == null ? '–' : `${fmt(a.occupancyPct)} %` }],
            ['Présents sans appel (feuille de présence)', { text: fmt1(a.free) }],
            ['Libres (téléphones relevés)', { text: a.live ? fmt1(freeOf(a)) : '–' }],
            ['En pause', { text: fmt1(a.pause) }],
            ['En déjeuner', { text: fmt1(a.lunch) }],
            ['Appels sonnés sans réponse', { text: fmt(a.missed) }],
        ],
    }
})
function selectWorst(w) {
    if (data.value?.rows?.some((r) => r.key === w.date)) {
        sel.value = { row: w.date, slot: w.slot }
    } else {
        // Période en semaines (lignes = jours de semaine) : ouvrir le jour du créneau
        pendingSel = { row: w.date, slot: w.slot }
        pickedDay.value = w.date
        preset.value = 'day'
    }
    document.getElementById('pilotage-grid')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
}

// ─── P6 : état dans l'adresse ─────────────────────────────────────────────────
watch(
    () => [preset.value, pickedDay.value, team.value, within.value, mode.value, gPlat.value, ovDay.value, ovPlat.value, sel.value],
    () => {
        const q = new URLSearchParams()
        if (preset.value !== 'today') q.set('period', preset.value)
        if (preset.value === 'day') q.set('date', pickedDay.value)
        if (team.value !== 'all') q.set('team', team.value)
        if (within.value !== 45) q.set('within', within.value)
        q.set('mode', mode.value)
        if (gPlat.value !== 'all') q.set('plat', gPlat.value)
        if (ovDay.value && ovDay.value !== ovDefault()) q.set('ov', ovDay.value)
        if (ovPlat.value !== 'all') q.set('ovplat', ovPlat.value)
        if (sel.value) q.set('sel', `${sel.value.row}~${sel.value.slot}`)
        try {
            history.replaceState(history.state, '', `${window.location.pathname}?${q}`)
        } catch {
            // adresse non modifiable : rien à faire
        }
    },
)

// ─── 6. Jour par jour ─────────────────────────────────────────────────────────
// Jours, ou semaines au-delà de 14 jours (weeksSummary) ; jours fermés avec des appels en ligne grise
const byWeek = computed(() => (data.value?.weeksSummary ?? []).length > 0)
const ddmm = (d) => new Date(`${d}T12:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
const periodRows = computed(() =>
    byWeek.value
        ? data.value.weeksSummary.map((w) => ({ ...w, key: w.week, label: `sem. du ${ddmm(w.week)}`, closed: w.openDays === 0 }))
        : (data.value?.days ?? []).map((d) => ({ ...d, key: d.date, label: dayLabel(d.date) })),
)
// Colonnes sans aucune valeur sur la liste (besoin ou téléphones non mesurés) : masquées
const worstPlanned = computed(() => (data.value?.worst ?? []).some((w) => w.plannedGap != null))
const worstGap = computed(() => (data.value?.worst ?? []).some((w) => w.gap != null))
const worstCauses = computed(() => (data.value?.worst ?? []).some((w) => w.causes))
const periodPlanned = computed(() => periodRows.value.some((d) => d.plannedOverHours != null))
const periodReal = computed(() => periodRows.value.some((d) => d.overHours != null))
const daysOther = computed(() => periodRows.value.some((d) => (d.other ?? 0) > 0))
const daysUnknown = computed(() => periodRows.value.some((d) => (d.lostUnknown ?? 0) > 0))
</script>

<template>
    <Head title="Attentes et agents" />

    <AppLayout>
        <div class="pilotage space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Attentes et agents' }]">
                <template v-if="data?.computedAt" #actions>
                    <span class="text-xs text-gray-500">Chiffres calculés à {{ data.computedAt.slice(11, 16) }}</span>
                </template>
            </PageHeader>

            <FilterBar :has-active-filters="preset !== 'today' || team !== 'all'" @reset="(preset = 'today'), (team = 'all')">
                <div class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2">
                    <span class="text-xs text-gray-500">Période</span>
                    <div class="flex flex-wrap items-center gap-1.5">
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
                        <Input v-if="preset === 'day'" v-model="pickedDay" type="date" :max="parisDay()" class="ml-1.5 h-8 w-40" />
                    </div>
                    <span class="text-xs text-gray-500">Équipe</span>
                    <div class="flex flex-wrap items-center gap-1.5">
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
            <div v-else-if="!data" class="space-y-4">
                <div v-for="i in 3" :key="i" class="h-40 animate-pulse rounded-lg border border-gray-100 bg-white" />
            </div>

            <section v-if="data && data.open === false" class="rounded-lg border border-gray-100 bg-white px-4 py-3 text-sm text-gray-700">
                <div>
                    {{ data.from === data.to ? 'Service fermé ce jour' : 'Service fermé sur cette période' }}<template v-if="outsideHours?.calls > 0"> · {{ fmt(outsideHours.calls) }} appels hors horaires</template>
                </div>
            </section>

            <template v-else-if="data">
                <div class="space-y-6" :class="loading && 'opacity-60'">
                    <!-- ─── 1. Devenir des appels : quatre petits tableaux ───── -->
                    <section>
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">
                            Devenir des appels <span class="ml-1 font-normal text-gray-500">Pendant les horaires d'ouverture · attentes comptées depuis l'arrivée de l'appel<template v-if="data.team !== 'all'"> · appels : tout le centre d'appels, agents : {{ teamName }}</template></span>
                        </h2>
                        <div>
                            <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-2">
                                <div v-for="g in fateGroups" :key="g.title" class="mini">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="title">{{ g.title }}<span v-if="g.note" class="note">{{ g.note }}</span></th>
                                                <th v-for="[k, label] in COLS" :key="k" :class="k === 'all' && 'total'">{{ label }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template v-for="(r, ri) in g.rows" :key="ri">
                                            <!-- Intitulé d'un groupe de lignes -->
                                            <tr v-if="r.heading" class="heading">
                                                <td :colspan="COLS.length + 1">{{ r.heading }}</td>
                                            </tr>
                                            <tr v-else :class="[r.strong && 'strong', r.sub && 'subrow', r.sep && 'sep', ri > 0 && g.rows[ri - 1]?.heading && 'first', g.rows.some((x) => x.heading) && 'ingroup']">
                                                <td>{{ r.label }}</td>
                                                <td v-for="[k] in COLS" :key="k" :class="k === 'all' && 'total'">
                                                    <template v-for="c in [r.cell(data.fate[k])]" :key="k">
                                                        <span v-if="c.muted" class="pct">{{ c.text }}</span>
                                                        <template v-else>
                                                            <span :class="[c.red && 'red', c.grey && 'text-gray-500']">{{ c.text }}</span> <span v-if="c.pct" class="pct">{{ c.pct }}</span>
                                                            <span v-if="c.extra" class="pct block">{{ c.extra }}</span>
                                                        </template>
                                                    </template>
                                                </td>
                                            </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <p v-if="data.fate.all?.lostMeasuredPct != null" class="legend">
                                Mesuré :
                                <template v-for="([k, label], i) in COLS" :key="k">{{ i ? ' ; ' : ' ' }}{{ label }} : étape des perdus {{ fmt1(data.fate[k]?.lostMeasuredPct) }} % · vraie attente {{ fmt1(data.fate[k]?.realWaitMeasuredPct) }} %</template>
                            </p>
                            <p v-if="outsideHours?.calls > 0" class="legend">
                                Hors horaires d'ouverture : {{ fmt(outsideHours.calls) }} appels (dont {{ fmt(outsideHours.answered) }} décrochés), non comptés ici
                            </p>
                        </div>
                    </section>

                    <!-- ─── Équipes : une ligne par centre d'appels ─── -->
                    <section v-if="data.teamSummary?.length">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">Équipes <span class="ml-1 font-normal text-gray-500">Agents de chaque centre d'appels sur la période</span></h2>
                        <div>
                            <div class="mini">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Équipe</th><th>Heures de présence</th><th>Appels décrochés</th><th>Décrochés par heure de présence</th>
                                            <th>Temps en appel</th><th>Pauses (h)</th><th>Déjeuner (h)</th><th>Sonnés sans réponse</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="t in data.teamSummary" :key="t.id" :class="t.id === 'none' ? 'closedrow' : t.id === data.team && 'strong selrow'">
                                            <td>{{ t.name }}</td>
                                            <td>{{ fmt(t.agentHours) }}</td>
                                            <td>{{ fmt(t.answered) }} <span class="pct">{{ fmt1(t.answeredPct) }} %</span></td>
                                            <td>{{ fmt1(t.answeredPerHour) }}</td>
                                            <td>{{ fmt1(t.talkPct) }} % <span class="pct">du temps de présence</span></td>
                                            <td>{{ fmt(t.pauseHours) }}</td>
                                            <td>{{ fmt(t.lunchHours) }}</td>
                                            <td>{{ fmt(t.missed) }} <span class="pct">{{ fmt1(t.missedPer100) }} pour 100 décrochés</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- ─── 2. Quand les appelants raccrochent : un tableau par plateforme ─── -->
                    <section v-if="hangups">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">Quand les appelants raccrochent <span class="ml-1 font-normal text-gray-500">Appels perdus selon le temps passé avant de raccrocher, en % de tous les appels de la plateforme</span></h2>
                        <div>
                            <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
                                <div v-for="t in hangups.tables" :key="t.key" class="mini">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="title">{{ t.title }}<span class="note">{{ t.note }}</span></th>
                                                <th>Perdus</th>
                                                <th class="wrap">dont en attente d'un agent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="r in t.rows" :key="r.label">
                                                <td>{{ r.label }}</td>
                                                <td>{{ r.total ? fmt(r.total) : '–' }} <span class="pct">{{ r.pct }}</span></td>
                                                <td>
                                                    <span v-if="r.queue === null" class="pct">non mesuré</span>
                                                    <span v-else :class="r.queue > 0 ? 'red' : 'text-gray-300'">{{ r.queue ? fmt(r.queue) : '–' }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <p class="legend">{{ hangups.forcedText }}</p>
                        </div>
                    </section>

                    <!-- ─── 3. Vue d'ensemble ───────────────────────────────── -->
                    <section v-if="ov">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">Vue d'ensemble <span class="ml-1 font-normal text-gray-500">Appels et agents demi-heure par demi-heure, sur les mêmes colonnes</span></h2>
                        <div class="toolbar">
                            <span class="tl">Jour</span>
                            <div class="chips">
                                <button v-for="r in allRows" :key="r.key" type="button" class="h-8 rounded-md px-3 text-xs font-medium transition-all" :class="ovDay === r.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'" @click="ovDay = r.key">{{ r.label }}</button>
                            </div>
                            <span class="tl">Appels de</span>
                            <div class="chips">
                                <button v-for="[k, l] in PLATS" :key="k" type="button" class="h-8 rounded-md px-3 text-xs font-medium transition-all" :class="ovPlat === k ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'" @click="ovPlat = k">{{ l }}</button>
                            </div>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-gray-100 bg-white p-4">
                            <div class="ov" :style="{ '--n': ovCols.length }">
                                <div class="lab hd" />
                                <div v-for="s in ovCols" :key="s" class="hd">{{ s }}</div>
                                <div class="lab">État</div>
                                <div v-for="s in ovCols" :key="s"><div v-if="ov.F[s]" class="stat" :class="STAT_CLASS[ov.F[s].level]" :title="ov.F[s].level === 'unmeasured' ? 'non mesuré' : undefined" /></div>

                                <div class="grp">Appels{{ ovPlat === 'all' ? '' : ' · ' + PLATS.find((p) => p[0] === ovPlat)[1] }}</div>
                                <div class="lab chart" style="--h: 120px"><span>{{ fmt(ov.maxC) }}</span><span>0</span></div>
                                <div v-for="s in ovCols" :key="s" class="chart" style="--h: 120px">
                                    <template v-if="ov.F[s]">
                                        <div class="answered" :style="{ height: ov.h(ov.F[s].ans - ov.F[s].late, ov.maxC, 116) }" />
                                        <div class="late" :style="{ height: ov.h(ov.F[s].late, ov.maxC, 116) }" />
                                        <div class="forced" :style="{ height: ov.h(ov.F[s].lf, ov.maxC, 116) }" />
                                        <div class="queue" :style="{ height: ov.h(ov.F[s].lq, ov.maxC, 116) }" />
                                    </template>
                                </div>
                                <template v-for="l in ov.calls" :key="l.label">
                                    <div class="lab" :class="l.cls">{{ l.label }}</div>
                                    <div v-for="(c, i) in l.cells" :key="i" :class="[c.bad && 'bad', c.zero && 'zero']">{{ c.text }}</div>
                                </template>

                                <div class="grp">Agents · {{ data.team === 'all' ? 'les deux plateformes' : teamName }}</div>
                                <div class="lab chart" style="--h: 90px"><span>{{ fmt(ov.maxA) }}</span><span>0</span></div>
                                <div v-for="s in ovCols" :key="s" class="chart" style="--h: 90px">
                                    <template v-if="ov.F[s]">
                                        <div class="busy" :style="{ height: ov.h(ov.F[s].busy, ov.maxA, 86) }" />
                                        <div class="free" :style="{ height: ov.h(ov.F[s].free, ov.maxA, 86) }" />
                                        <div class="brk" :style="{ height: ov.h((ov.F[s].pause ?? 0) + (ov.F[s].lunch ?? 0), ov.maxA, 86) }" />
                                        <div v-if="data.needShown && ov.F[s].need > 0" class="needmark" :style="{ bottom: `${2 + (ov.F[s].need / ov.maxA) * 86}px` }" :title="`Nécessaires : ${fmt1(ov.F[s].need)}`" />
                                    </template>
                                </div>
                                <template v-for="l in ov.agents" :key="l.label">
                                    <div class="lab" :class="l.cls">{{ l.label }}</div>
                                    <div v-for="(c, i) in l.cells" :key="i" :class="[c.bad && 'bad', c.zero && 'zero']">{{ c.text }}</div>
                                </template>
                            </div>
                            <div class="legend">
                                <span><i class="sw answered" />décrochés</span><span><i class="sw late" />décrochés après une longue attente</span>
                                <span><i class="sw forced" />perdus pendant l'accueil ou l'attente forcée</span><span><i class="sw queue" />perdus en attente d'un agent</span>
                                <span class="ml-3"><i class="sw busy" />en appel</span>
                                <span v-if="data.needShown"><i class="sw" style="height: 2px; background: #18181b" />agents nécessaires</span>
                                <span><i class="sw free" />présents sans appel</span><span><i class="sw brk" />en pause ou déjeuner</span>
                            </div>
                            <p class="legend">
                                Perdus à l'accueil : pendant l'accueil ou l'attente forcée. Présents (feuille) : feuille de présence, hors pause et déjeuner. Occupation : temps en appel / présence.
                                <template v-if="data.needShown"> Écart de planning : présents − nécessaires. Écart réel : joignables (téléphone connecté) − nécessaires.</template>
                            </p>
                        </div>
                    </section>

                    <!-- ─── 4. Heure par heure ──────────────────────────────── -->
                    <section>
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">Heure par heure <span class="ml-1 font-normal text-gray-500">Chiffre : {{ modeText(currentMode.name) }}</span></h2>
                        <div class="toolbar">
                            <span class="tl">Chiffre</span>
                            <select v-model="mode" class="flex h-8 w-72 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" aria-label="Chiffre affiché dans les cases">
                                <optgroup label="Agents">
                                    <option v-for="m in MODES.filter((x) => x.agents)" :key="m.key" :value="m.key" :disabled="!!modeDisabled(m)">{{ modeText(m.label) }}{{ modeDisabled(m) ? ' (indisponible)' : '' }}</option>
                                </optgroup>
                                <optgroup label="Appels">
                                    <option v-for="m in MODES.filter((x) => !x.agents)" :key="m.key" :value="m.key">{{ modeText(m.label) }}</option>
                                </optgroup>
                            </select>
                            <span class="tl">Appels de</span>
                            <div class="chips">
                                <button v-for="[k, l] in PLATS" :key="k" type="button" class="h-8 rounded-md px-3 text-xs font-medium transition-all" :class="gPlat === k ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'" @click="gPlat = k">{{ l }}</button>
                            </div>
                            <template v-if="needMode">
                                <span class="tl">Nécessaires</span>
                                <span class="text-xs text-gray-600">{{ data.answerShare }} % décrochés en moins de</span>
                                <select v-model.number="within" class="flex h-8 w-24 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm" aria-label="Attente maximale">
                                    <option v-for="v in WITHIN_CHOICES" :key="v" :value="v">{{ v }} s</option>
                                </select>
                            </template>
                        </div>
                        <div>
                            <div class="overflow-x-auto rounded-lg border border-gray-100 bg-white p-4">
                                <table id="pilotage-grid" class="hgrid">
                                    <thead>
                                        <tr><th />
                                            <th v-for="s in data.slots" :key="s">{{ s }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in data.rows" :key="r.key">
                                            <th class="day">{{ r.label }}<span v-if="r.days > 1" class="pct">{{ r.days }} j</span></th>
                                            <td v-for="s in data.slots" :key="s">
                                                <template v-for="c in [cellOf(r, s)]" :key="s">
                                                    <span v-if="!c || c.state === 'closed'" class="cell closed" />
                                                    <span v-else-if="c.state !== 'open'" class="cell empty" />
                                                    <button
                                                        v-else
                                                        type="button"
                                                        class="cell"
                                                        :class="[cellClass(c), isSel(r, s) && 'sel']"
                                                        :aria-label="`${r.label} ${s}`"
                                                        :title="!needMode && fig(c, gPlat)?.level === 'unmeasured' ? 'non mesuré' : undefined"
                                                        @click="sel = { row: r.key, slot: s }"
                                                    >
                                                        {{ currentMode.value(fig(c, gPlat), gPlat) }}
                                                    </button>
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="needMode" class="legend">
                                <span><i class="sw" style="background: #fee2e2; outline: 1px solid #dc2626" />il manquait des agents</span>
                                <span><i class="sw" style="background: #ecfdf3" />effectif juste (0 à 2 en plus)</span>
                                <span><i class="sw" style="background: #eef4ff" />3 agents ou plus en trop</span>
                                <span style="flex-basis: 100%">
                                    Nécessaires : agents qu'il fallait pour décrocher {{ data.answerShare }} % des appels en moins de {{ within }} s de vraie attente, avec la durée de traitement de la demi-heure (temps en appel, attente comprise). Ce choix change le calcul des agents nécessaires dans toute la page.
                                    <template v-if="mode === 'gap'">Écart réel : agents joignables (téléphone connecté, hors pause) moins nécessaires. – : téléphones non relevés.</template>
                                    <template v-else>Écart de planning : agents présents sur la feuille de présence (hors pause et déjeuner) moins nécessaires. – : appels de la demi-heure pas tous mesurés.</template>
                                </span>
                            </div>
                            <div v-else class="legend">
                                <span><i class="sw" style="background: #fee2e2; outline: 1px solid #dc2626" />3 % des appels ou plus perdus en attente d'un agent ou décrochés après une longue attente</span>
                                <span><i class="sw warn-sw" />de 1 à 3 %</span>
                                <span><i class="sw" style="background: #ecfdf3" />moins de 1 %</span>
                            </div>
                            <p v-if="anyDot" class="legend">
                                <span><i class="sw" style="background: #dc2626; border-radius: 50%; width: 7px; height: 7px" />point rouge : des appels perdus en attente d'un agent alors que des agents étaient libres</span>
                            </p>
                            <p class="legend">{{ LONG_WAIT_TEXT }}</p>

                            <div v-if="detail" class="mt-6">
                                <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ detail.title }}<span v-if="detail.avg" class="ml-1 font-normal text-gray-500">moyenne par jour</span></h3>
                                <!-- Appels à la largeur de ses chiffres (3 plateformes), Agents prend le reste -->
                                <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[max-content_1fr]">
                                <div class="mini">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="title">Appels</th>
                                                <th v-for="[k, l] in PLATS" :key="k" :class="k === 'all' && 'total'">{{ l }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="r in detail.calls" :key="r.label">
                                                <td>{{ r.label }}</td>
                                                <td v-for="(c, i) in r.cells" :key="i" :class="i === 0 && 'total'"><span :class="[c.red && 'red', c.muted && 'pct']">{{ c.text }}</span> <span v-if="c.pct" class="pct">{{ c.pct }}</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mini">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="title">Agents<span class="note">{{ data.team === 'all' ? 'les deux plateformes' : teamName }}, moyenne sur la demi-heure</span></th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="[label, c] in detail.agents" :key="label" :class="c.sub && 'subrow'">
                                                <td :colspan="c.sub ? 2 : 1">{{ label }}</td>
                                                <td v-if="!c.sub" :class="c.red && 'red'">{{ c.text }}</td>
                                            </tr>
                                            <!-- Attente avec ou sans agents libres, minute par minute -->
                                            <template v-if="detail.causes">
                                                <tr v-for="[label, v] in detail.causes" :key="label" :class="v === null ? 'sep' : 'subrow'">
                                                    <td :colspan="v === null ? 2 : 1">{{ label }}<span v-if="v === null && detail.causesDays" class="pct">moyenne sur {{ detail.causesDays }} jours relevés</span></td>
                                                    <td v-if="v !== null">{{ v }}</td>
                                                </tr>
                                            </template>
                                            <tr v-else class="sep">
                                                <td>Appelants en attente d'un agent depuis 30 s ou plus</td>
                                                <td class="pct">non mesuré</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ─── 5. Créneaux à revoir : l'essentiel par créneau, le reste au clic (détail dans la grille) ─── -->
                    <section v-if="data.worst?.length">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">Créneaux à revoir <span class="ml-1 font-normal text-gray-500">Les demi-heures avec le plus d'appels perdus en attente d'un agent ou décrochés après une longue attente · cliquer pour le détail</span></h2>
                        <div>
                            <div class="mini">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Demi-heure</th>
                                            <th>Appels</th>
                                            <th>Perdus en attente d'un agent</th>
                                            <th>Longue attente</th>
                                            <th class="gl">Présents</th>
                                            <th>En appel</th>
                                            <th v-if="worstPlanned">Écart de planning</th><th v-if="worstGap">Écart réel</th>
                                            <th v-if="worstCauses" class="gl">Attente avec agents libres</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="w in data.worst" :key="w.date + w.slot" class="clickable" @click="selectWorst(w)">
                                            <td class="underline decoration-gray-300 underline-offset-2">{{ dayLabel(w.date) }} {{ w.slot }}–{{ endOf(w.slot) }}</td>
                                            <td>{{ fmt(w.calls) }}</td>
                                            <td :title="`Notre serveur ${fmt(w.lostQueueServer)} · Voxnode ${fmt(w.lostQueueVoxnode)}`">
                                                <span class="red">{{ fmt(w.lostQueue) }}</span>
                                                <span v-if="(w.lostUnknown ?? 0) > 0" class="pct">+ {{ fmt(w.lostUnknown) }} non mesurés</span>
                                            </td>
                                            <td>{{ w.realWaitMeasured === 0 ? '–' : fmt(w.late) }}</td>
                                            <td class="gl">{{ fmt1(w.agents?.present) }}</td>
                                            <td>{{ fmt1(w.agents?.busy) }}</td>
                                            <td v-if="worstPlanned"><span :class="w.plannedGap != null && Math.round(w.plannedGap) < 0 && 'red'">{{ gapTxt(w.plannedGap) }}</span></td>
                                            <td v-if="worstGap"><span :class="w.gap != null && Math.round(w.gap) < 0 && 'red'">{{ gapTxt(w.gap) }}</span></td>
                                            <td v-if="worstCauses" class="gl">
                                                <template v-if="w.causes">{{ fmt(w.causes.freeSame + w.causes.freeOther) }} min <span class="pct">sur {{ fmt(w.causes.waiting) }}</span></template>
                                                <span v-else class="pct">non mesuré</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="legend">
                                Présents : feuille de présence.
                                <template v-if="worstPlanned"> Écart de planning : présents − nécessaires.</template>
                                <template v-if="worstGap"> Écart réel : joignables (téléphone connecté) − nécessaires.</template>
                                <template v-if="worstCauses"> Attente avec agents libres : minutes où un appelant attendait un agent depuis 30 s ou plus alors que des agents étaient libres.</template>
                            </p>
                            <p class="legend">{{ LONG_WAIT_TEXT }}</p>
                        </div>
                    </section>

                    <!-- ─── 6. Jour par jour, ou semaine par semaine au-delà de 14 jours ──── -->
                    <section v-if="periodRows.length">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">{{ byWeek ? 'Semaine par semaine' : 'Jour par jour' }}</h2>
                        <div class="space-y-4">
                            <div class="mini">
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="title">Appels</th>
                                            <th>Total</th><th>Décrochés</th><th>Perdus</th><th>dont accueil</th><th>dont attente agent</th>
                                            <th v-if="daysUnknown">dont non mesurés</th>
                                            <th>Longue attente</th><th class="gl">Attente moy.</th><th>méd.</th>
                                            <th v-if="daysOther" class="gl">Autres</th><th :class="!daysOther && 'gl'">Hors horaires</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="d in periodRows" :key="d.key" :class="d.closed && 'closedrow'">
                                            <td>{{ d.label }}<span v-if="byWeek && d.openDays" class="pct">{{ d.openDays }} j</span></td>
                                            <td v-if="d.closed" colspan="99" class="text-left">fermé · {{ fmt(d.outsideHours) }} appels hors horaires</td>
                                            <template v-else>
                                                <td>{{ fmt(d.calls) }}</td>
                                                <td>{{ fmt(d.answered) }} <span class="pct">{{ pct(d.answered, d.calls) }}</span></td>
                                                <td>{{ fmt(d.lost) }}</td>
                                                <td><span v-if="stageUnmeasured(d)" class="pct">non mesuré</span><template v-else>{{ fmt(d.lostForced) }}</template></td>
                                                <td>
                                                    <span v-if="stageUnmeasured(d)" class="pct">non mesuré</span>
                                                    <template v-else><span class="red">{{ fmt(d.lostQueue) }}</span></template>
                                                </td>
                                                <td v-if="daysUnknown" class="text-gray-500">{{ fmt(d.lostUnknown ?? 0) }}</td>
                                                <td>
                                                    <span v-if="lateUnmeasured(d)" class="pct">non mesuré</span>
                                                    <template v-else>{{ fmt(d.late) }}</template>
                                                </td>
                                                <td class="gl">{{ mmss(d.answerWaitAvgSec) }}</td>
                                                <td>{{ mmss(d.answerWaitMedianSec) }}</td>
                                                <td v-if="daysOther" class="gl">{{ fmt(d.other ?? 0) }}</td>
                                                <td class="text-gray-500" :class="!daysOther && 'gl'">{{ fmt(d.outsideHours ?? 0) }}</td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mini">
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="title">Agents<span v-if="data.team !== 'all'" class="pct">{{ teamName }}</span></th>
                                            <th>Présence (h)</th><th>Décrochés</th><th>par heure</th><th>Occupation</th>
                                            <th v-if="periodPlanned" class="gl">Écart de planning (h)</th><th v-if="periodReal">Écart réel (h)</th>
                                            <th class="gl">Pause et déjeuner (h)</th><th>Sonnés sans réponse</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="d in periodRows" :key="d.key" :class="d.closed && 'closedrow'">
                                            <td>{{ d.label }}</td>
                                            <td v-if="d.closed" colspan="99" class="text-left">fermé</td>
                                            <template v-else>
                                                <td>{{ fmt(d.agentHours) }}</td>
                                                <td>{{ fmt(d.agentsAnswered) }}</td>
                                                <td>{{ fmt1(d.answeredPerHour) }}</td>
                                                <td>{{ d.occupancyPct == null ? '–' : `${fmt(d.occupancyPct)} %` }}</td>
                                                <template v-if="periodPlanned || periodReal">
                                                    <td v-if="periodPlanned" class="gl">
                                                        <template v-if="d.plannedOverHours == null || d.plannedShortHours == null">–</template>
                                                        <template v-else>+{{ fmt(d.plannedOverHours) }} / <span :class="d.plannedShortHours > 0 && 'red'">−{{ fmt(d.plannedShortHours) }}</span></template>
                                                    </td>
                                                    <td v-if="periodReal">
                                                        <template v-if="d.overHours == null || d.shortHours == null">–</template>
                                                        <template v-else>+{{ fmt(d.overHours) }} / <span :class="d.shortHours > 0 && 'red'">−{{ fmt(d.shortHours) }}</span></template>
                                                    </td>
                                                </template>
                                                <td class="gl">{{ fmt(d.breakHours) }}</td>
                                                <td>{{ fmt(d.missed) }}</td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="legend">
                                Occupation : temps en appel / temps de présence. Autres : ni décrochés ni perdus (sans durée ou en cours).
                                <template v-if="periodPlanned || periodReal"> Écarts en heures : en trop / manquantes (planning : feuille de présence ; réel : téléphones relevés).</template>
                            </p>
                            <p class="legend">{{ LONG_WAIT_TEXT }}</p>
                        </div>
                    </section>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Repris de la maquette validée (thème clair du panel) */
.pilotage {
    --fg: #111827;
    --muted: #6b7280;
    --line: #f3f4f6;
    --soft: #f9fafb;
    --cell: #f3f4f6;
    --red: #dc2626;
    --red-bg: #fee2e2;
    --red-mid: #f59e9e;
    --green: #16a34a;
    --green-bg: #ecfdf3;
    --blue-bg: #eef4ff;
    --grey-bar: #b4b4bb;
    --dark-bar: #52525b;
    --hatch: #dcdce0;
}
.scroll {
    overflow-x: auto;
}
.tbl {
    border-collapse: collapse;
    width: auto;
    font-variant-numeric: tabular-nums;
}
.tbl th,
.tbl td,
.detail th,
.detail td {
    padding: 5px 8px;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--soft);
}
.tbl th:first-child,
.tbl td:first-child,
.detail td:first-child {
    text-align: left;
    padding-right: 24px;
}
.tbl th + th,
.tbl td + td {
    padding-left: 16px;
}
.tbl thead th,
.detail thead th {
    font-weight: 500;
    color: var(--muted);
    font-size: 12px;
}
/* En-têtes longs sur deux lignes : les colonnes restent étroites */
.tbl thead th {
    white-space: normal;
    max-width: 110px;
    vertical-align: bottom;
    line-height: 1.25;
}
.tbl tbody tr:hover td {
    background: #fafafa;
}
tr.lvl1 td:first-child {
    padding-left: 22px;
    color: var(--muted);
}
tr.grp td {
    font-weight: 600;
}
tr.sep td {
    border-top: 1px solid #e5e7eb;
}
.red {
    color: var(--red);
    font-weight: 600;
}
.pct {
    color: var(--muted);
    font-size: 11px;
    margin-left: 0;
    font-weight: 400;
}
.link {
    color: inherit;
    text-decoration: underline;
    cursor: pointer;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.sep-l {
    margin-left: 8px;
    padding-left: 14px;
    border-left: 1px solid #e5e7eb;
}
.chip {
    height: 28px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #4b5563;
    border-radius: 6px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
}
.chip:hover {
    background: #f3f4f6;
}
.chip[aria-pressed='true'] {
    background: var(--fg);
    color: #fff;
    border-color: var(--fg);
}
.chip:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 16px;
    color: var(--muted);
    font-size: 12px;
    margin-top: 10px;
}
.sw {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    margin-right: 5px;
    vertical-align: -1px;
}
.forced {
    background: var(--grey-bar);
}
.queue {
    background: var(--red);
}
.answered {
    background: var(--dark-bar);
}
.late {
    background: var(--red-mid);
}
.unknown {
    background: repeating-linear-gradient(135deg, var(--grey-bar) 0 3px, var(--hatch) 3px 6px);
}
.busy {
    background: var(--dark-bar);
}
.free {
    background: var(--green);
}
.brk {
    background: var(--grey-bar);
}
.over {
    background: var(--blue-bg);
}
.warn-sw {
    background: color-mix(in srgb, var(--red-bg) 45%, #fff);
}
/* Petits tableaux : titre dans la première case, valeurs près des libellés */
/* Tableaux : un cadre chacun, toute la largeur de leur colonne (comme WaitLoss) */
.mini {
    max-width: 100%;
    overflow-x: auto;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 14px;
    color: var(--fg);
}
.mini table {
    border-collapse: collapse;
    width: 100%;
    font-variant-numeric: tabular-nums;
}

.mini th,
.mini td {
    padding: 6px 12px;
    text-align: right;
    white-space: nowrap;
}
.mini th:first-child,
.mini td:first-child {
    text-align: left;
}
.mini thead th {
    font-size: 12px;
    font-weight: 400;
    color: var(--muted);
    border-bottom: 1px solid var(--line);
}
.mini thead th.title {
    color: var(--fg);
    font-size: 14px;
    font-weight: 600;
}
.mini thead th .note {
    display: block;
    white-space: normal;
    font-size: 11px;
    font-weight: 400;
    color: var(--muted);
}
.mini thead tr.group th {
    border-bottom: 0;
    padding-bottom: 0;
}
.mini .gh {
    text-align: center;
    color: var(--fg);
}
.mini .gl {
    border-left: 1px solid var(--line);
}
.mini tbody tr {
    border-top: 1px solid var(--soft);
}
.mini tr.clickable {
    cursor: pointer;
}
.mini tbody tr:hover {
    background: var(--soft);
}
.mini tr.strong td {
    font-weight: 600;
}
.mini tr.subrow td:first-child {
    padding-left: 24px;
    color: var(--muted);
}
.mini tr.sep {
    border-top: 1px solid #e5e7eb;
}
.mini .total {
    background: #fafafa;
}
/* Barre d'outils sous l'en-tête d'une section */
/* Choix d'une section, sous son titre */
.toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.toolbar .tl {
    font-size: 12px;
    color: var(--muted);
}
.toolbar:not(.rows) .tl:not(:first-child) {
    margin-left: 16px;
}
/* Une ligne par groupe de boutons, libellés alignés */
.toolbar.rows {
    display: grid;
    grid-template-columns: auto 1fr;
}
.mini th.wrap {
    white-space: normal;
    max-width: 110px;
    line-height: 1.2;
}
.mini td.barcell {
    width: 40%;
    min-width: 90px;
}
.mini tr.closedrow td {
    color: var(--muted);
    background: var(--soft);
}
.mini tr.selrow td {
    background: #f3f4f6;
}
.mini tr.heading td {
    padding-top: 10px;
    padding-bottom: 2px;
    font-size: 12px;
    color: var(--muted);
    background: transparent;
}
.mini tr.heading + tr {
    border-top: 0;
}
.mini tr.ingroup td:first-child {
    padding-left: 20px;
}
.mode-select {
    height: 30px;
    min-width: 16rem;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 0 8px;
    font-size: 13px;
    background: #fff;
    color: var(--fg);
}
/* Raccrochés */
.hist {
    display: grid;
    grid-template-columns: 96px repeat(3, 300px);
    gap: 5px 24px;
    align-items: center;
    font-variant-numeric: tabular-nums;
    width: max-content;
}
.hist .h {
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
}
.bar {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.track {
    flex: 1;
    height: 14px;
    display: flex;
    min-width: 0;
}
.track > div {
    height: 100%;
}
.val {
    width: 130px;
    text-align: right;
    font-size: 12px;
    line-height: 1.25;
}
.mark4 {
    grid-column: 1 / -1;
    border-top: 1px dashed var(--muted);
    color: var(--muted);
    font-size: 11px;
    padding-top: 2px;
}
/* Grille (« grid » est une classe Tailwind) */
.hgrid {
    border-collapse: separate;
    border-spacing: 2px;
    width: auto;
    font-variant-numeric: tabular-nums;
}
.hgrid th {
    font-size: 11px;
    color: var(--muted);
    font-weight: 500;
    padding: 2px;
    text-align: center;
    border: 0;
}
.hgrid th.day {
    text-align: left;
    padding-right: 10px;
    color: var(--fg);
    font-size: 12px;
    white-space: nowrap;
}
.hgrid td {
    padding: 0;
    border: 0;
}
.cell {
    display: block;
    width: 38px;
    height: 28px;
    border: 0;
    border-radius: 4px;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    background: var(--cell);
    color: var(--fg);
}
.cell.short {
    background: var(--red-bg);
    color: var(--red);
    font-weight: 600;
}
.cell.warn {
    background: color-mix(in srgb, var(--red-bg) 45%, #fff);
}
.cell.ok {
    background: var(--green-bg);
}
.cell.over {
    background: var(--blue-bg);
}
.cell.none {
    background: #fff;
    box-shadow: inset 0 0 0 1px var(--cell);
}
.cell.empty {
    background: #fafafa;
    cursor: default;
}
.cell.closed {
    background: repeating-linear-gradient(135deg, var(--cell) 0 4px, #fff 4px 8px);
    cursor: default;
}
.cell.sel {
    outline: 2px solid var(--fg);
}
.cell.dot {
    position: relative;
}
.cell.dot::after {
    content: '';
    position: absolute;
    top: 3px;
    right: 3px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--red);
}
.within {
    font-size: 12px;
    color: var(--fg);
    background: transparent;
    border: 0;
    border-bottom: 1px dashed var(--muted);
    padding: 0 2px;
    cursor: pointer;
}
/* Vue d'ensemble : graphique et chiffres sur la même grille, une colonne par demi-heure */
.ov {
    display: grid;
    grid-template-columns: max-content repeat(var(--n), 36px);
    font-variant-numeric: tabular-nums;
    width: max-content;
}
.ov > div {
    border-bottom: 1px solid var(--soft);
    padding: 4px 2px;
    text-align: right;
    font-size: 12px;
    white-space: nowrap;
}
.ov > .lab {
    text-align: left;
    color: var(--fg);
    padding-left: 0;
    padding-right: 16px;
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 1;
}
.ov > .lab.muted {
    color: var(--muted);
}
.ov > .lab.l1 {
    padding-left: 14px;
    color: var(--muted);
}
.ov > .hd {
    color: var(--muted);
    font-size: 11px;
    text-align: center;
    font-weight: 500;
}
.ov > .grp {
    grid-column: 1 / -1;
    text-align: left;
    font-weight: 600;
    padding-top: 12px;
    border-bottom: 1px solid #e5e7eb;
    position: sticky;
    left: 0;
}
.ov > .chart {
    position: relative;
    height: var(--h);
    display: flex;
    flex-direction: column-reverse;
    align-items: center;
    padding: 0 0 2px;
    border-bottom: 1px solid #e5e7eb;
}
.ov > .chart > div {
    width: 24px;
    flex: none;
}
.ov > .chart.lab {
    flex-direction: column;
    justify-content: space-between;
    align-items: flex-start;
    color: var(--muted);
    font-size: 11px;
}
.ov .stat {
    height: 8px;
    width: 24px;
    border-radius: 2px;
    margin: 0 auto;
}
.ov > .bad {
    color: var(--red);
    font-weight: 600;
    background: var(--red-bg);
}
.ov > .zero {
    color: var(--muted);
}
.needmark {
    position: absolute;
    left: 4px;
    right: 4px;
    height: 0;
    border-top: 2px solid var(--fg);
    width: auto !important;
}
/* Détail d'une demi-heure */
.detail {
    margin-top: 12px;
    border-top: 1px solid var(--line);
    padding-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px 48px;
    align-items: flex-start;
    overflow-x: auto;
}
.detail table {
    border-collapse: collapse;
    width: auto;
    font-variant-numeric: tabular-nums;
}
.detail h3 {
    flex-basis: 100%;
    margin: 0;
    font-size: 13px;
    font-weight: 600;
}
.detail td {
    padding: 4px 0;
}
.detail td + td,
.detail th + th {
    padding-left: 16px;
}
</style>
