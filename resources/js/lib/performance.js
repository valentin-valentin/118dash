// Calculs et formats de la page Performance des call centers.
// Les ratios sont toujours calculés à partir des sommes, jamais en moyennant des ratios.

const SUM_FIELDS = [
    'calls', 'payout', 'total_s', 'agent_s', 'transfer_s', 'wait_s',
    'talk_s', 'pause_s', 'phone_calls', 'anomalies', 'agents', 'agent_days', 'days',
]

const div = (a, b) => (b ? a / b : null)

// Additionne des lignes de sommes (ex : plusieurs centres, plusieurs jours)
export function addSums(rows) {
    const total = Object.fromEntries(SUM_FIELDS.map((f) => [f, 0]))
    let cost = 0
    let costKnown = rows.length > 0
    let costEstimated = false
    for (const r of rows) {
        for (const f of SUM_FIELDS) total[f] += Number(r[f] ?? 0)
        if (r.cost === null || r.cost === undefined) costKnown = false
        else cost += Number(r.cost)
        if (r.cost_estimated) costEstimated = true
    }
    return { ...total, cost: costKnown ? cost : null, cost_estimated: costEstimated }
}

// Indicateurs dérivés d'une ligne de sommes
export function metrics(s) {
    const n = (f) => Number(s[f] ?? 0)
    const payout = n('payout')
    const cost = s.cost === null || s.cost === undefined ? null : Number(s.cost)
    const margin = cost === null ? null : payout - cost

    return {
        calls: n('calls'),
        agents: n('agents'),
        agent_days: n('agent_days'),
        days: n('days'),
        payout,
        cost,
        cost_estimated: !!s.cost_estimated,
        margin,
        margin_rate: margin === null ? null : div(margin, payout),
        payout_over_cost: cost === null ? null : div(payout, cost),
        cost_per_call: cost === null ? null : div(cost, n('calls')),
        margin_per_call: margin === null ? null : div(margin, n('calls')),
        payout_per_call: div(payout, n('calls')),
        payout_per_agent_hour: div(payout, n('agent_s') / 3600),
        payout_per_agent_day: div(payout, n('agent_days')),
        calls_per_agent_day: div(n('calls'), n('agent_days')),
        agent_ratio: div(n('agent_s'), n('total_s')),
        transfer_ratio: div(n('transfer_s'), n('total_s')),
        wait_ratio: div(n('wait_s'), n('total_s')),
        avg_total: div(n('total_s'), n('calls')),
        avg_wait: div(n('wait_s'), n('calls')),
        avg_agent: div(n('agent_s'), n('calls')),
        avg_transfer: div(n('transfer_s'), n('calls')),
        // Conversation et pause ne sont renseignées que sur une partie des appels
        avg_talk: div(n('talk_s'), n('phone_calls')),
        avg_pause: div(n('pause_s'), n('phone_calls')),
        agent_hours: n('agent_s') / 3600,
        anomalies: n('anomalies'),
    }
}

// Variation relative, null si non comparable
export function variation(current, previous) {
    if (current === null || previous === null || previous === undefined || !previous) return null
    return (current - previous) / Math.abs(previous)
}

// ─── Formats ──────────────────────────────────────────────────────────────────
const formatters = {}
// Espace insécable normale pour les milliers (l'espace fine par défaut est peu lisible)
const nf = (digits) => {
    formatters[digits] ??= new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
    return { format: (v) => formatters[digits].format(v).replace(/\u202f/g, '\u00a0') }
}

export const fmt = {
    int: (v) => (v === null || v === undefined ? '—' : nf(0).format(v)),
    dec: (v, d = 1) => (v === null || v === undefined ? '—' : nf(d).format(v)),
    eur: (v, d = 0) => (v === null || v === undefined ? '—' : `${nf(d).format(v)} €`),
    pct: (v, d = 1) => (v === null || v === undefined ? '—' : `${nf(d).format(v * 100)} %`),
    mult: (v) => (v === null || v === undefined ? '—' : `${nf(2).format(v)}×`),
    hours: (v) => (v === null || v === undefined ? '—' : `${nf(v < 100 ? 1 : 0).format(v)} h`),
    // Durée courte : 58 s, 3 min 05
    dur: (s) => {
        if (s === null || s === undefined) return '—'
        const total = Math.round(s)
        const abs = Math.abs(total)
        const sign = total < 0 ? '-' : ''
        if (abs < 60) return `${sign}${abs}\u00a0s`
        return `${sign}${Math.floor(abs / 60)}\u00a0min\u00a0${String(abs % 60).padStart(2, '0')}`
    },
    variation: (v) => (v === null ? '' : `${v > 0 ? '+' : ''}${nf(1).format(v * 100)} %`),
}

// ─── Dates ────────────────────────────────────────────────────────────────────
export const iso = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

export const parseIso = (s) => {
    const [y, m, d] = s.split('-').map(Number)
    return new Date(y, m - 1, d)
}

export const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)

export const daysBetween = (a, b) => Math.round((parseIso(b) - parseIso(a)) / 86400000) + 1

// Lundi de la semaine
export const weekStart = (s) => {
    const d = parseIso(s)
    return iso(addDays(d, -((d.getDay() + 6) % 7)))
}

export function formatRange(from, to) {
    const opts = { day: 'numeric', month: 'short', year: 'numeric' }
    if (from === to) return parseIso(from).toLocaleDateString('fr-FR', opts)
    return `${parseIso(from).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} – ${parseIso(to).toLocaleDateString('fr-FR', opts)}`
}
