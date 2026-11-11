// Affichage d'un appel (table calls), partagé par la liste des appels et la fiche d'un appel.

const TZ = 'Europe/Paris'

export function formatDateTime(value, withSeconds = false) {
    if (!value) return '—'
    return new Date(value).toLocaleString('fr-FR', {
        timeZone: TZ,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        ...(withSeconds ? { second: '2-digit' } : {}),
    })
}

export function formatDay(value) {
    if (!value) return '—'
    return new Date(value).toLocaleDateString('fr-FR', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short' })
}

export function formatTime(value) {
    if (!value) return '—'
    return new Date(value).toLocaleTimeString('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

// 0:45, 3:07, 1:02:10
export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds === '') return '—'
    const s = Math.max(0, Math.round(Number(seconds)))
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    const r = String(s % 60).padStart(2, '0')
    return h ? `${h}:${String(m).padStart(2, '0')}:${r}` : `${m}:${r}`
}

export function formatEuros(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—'
    return `${Number(value).toLocaleString('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })} €`
}

export function formatInt(value) {
    return value === null || value === undefined ? '—' : Number(value).toLocaleString('fr-FR')
}

export function percent(part, total) {
    return total ? `${Math.round((part / total) * 100)} %` : '—'
}

// Découpage de la durée totale : attente avant l'agent (accueil compris), conversation agent, transfert
export function durationParts(call) {
    const total = Number(call.total_duration) || 0
    const agent = Number(call.duration_agent) || 0
    const transfer = Number(call.duration_transfer) || 0
    return { total, agent, transfer, wait: Math.max(0, total - agent - transfer) }
}

// Centre d'appels d'après le nom de l'agent (config/callcenters.php) : noms exacts d'abord, puis préfixe le plus long.
// Les centres optionnels (Madagascar Remote) restent dans leur centre d'origine.
export function centerOf(agentName, centers) {
    if (!agentName) return null
    const active = centers.filter((c) => !c.optional)
    const exact = active.find((c) => c.agents.includes(agentName))
    if (exact) return exact
    let best = null
    let length = 0
    for (const c of active) {
        for (const prefix of c.prefixes) {
            if (agentName.startsWith(prefix) && prefix.length > length) [best, length] = [c, prefix.length]
        }
    }
    return best
}

// Agent : « +33118500999@… » est le service interne 118 500
export function agentLabel(agentName) {
    if (!agentName) return null
    if (agentName === 'ai') return 'Agent IA'
    const internal = agentName.match(/^\+33(\d{6})\d*@/)
    return internal ? `Service ${internal[1]}` : agentName
}

const CARRIERS = {
    FRTE: 'Orange',
    SFR0: 'SFR',
    BOUY: 'Bouygues',
    FRMO: 'Free Mobile',
    FREE: 'Free',
    TRAT: 'Transatel',
    LYCA: 'Lycamobile',
    NRJ: 'NRJ Mobile',
    AFON: 'Afone',
    LPTL: 'La Poste',
    LPST: 'La Poste',
    CORI: 'Coriolis',
    COMP: 'Completel',
    SYMA: 'Syma',
    ORCA: 'Orange Caraïbe',
    OUTR: 'Outremer Telecom',
    ORRU: 'Orange Réunion',
    SRR: 'SFR Réunion',
}
export function carrierName(code) {
    return code ? (CARRIERS[code] ?? code) : null
}

export const WHO_HANGUP = { caller: "L'appelant", callee: "L'appelé", undefined: 'Inconnu', 'call rejected': 'Appel rejeté' }

export function platformLabel(platform) {
    return platform === 'selfhosted' ? 'Notre serveur' : 'Voxnode'
}

// Numéro français lisible : +33612345678 → 06 12 34 56 78 ; numéro court (+331044) → 1044 ; sinon tel quel
export function formatPhone(number) {
    if (!number) return '—'
    const full = String(number).match(/^\+33(\d{9})$/)
    if (full) return `0${full[1]}`.replace(/(\d{2})(?=\d)/g, '$1 ')
    const short = String(number).match(/^\+33(\d{1,8})$/)
    return short ? short[1] : number
}

// Numéro saisi ou collé au format international : « 01 70 81 46 02 » → +33170814602, « 0033 1… » → +331…,
// « +33 (0)1 70… » → +33170… ; laissé tel quel si ce n'est pas un numéro (lettres, vide)
export function toInternational(input) {
    const raw = String(input ?? '').trim()
    if (!raw || /[a-z]/i.test(raw)) return raw
    const d = raw.replace(/\(0\)/g, '').replace(/[^\d+]/g, '')
    if (d.startsWith('+')) return d
    if (d.startsWith('00')) return `+${d.slice(2)}`
    if (/^0\d{9}$/.test(d)) return `+33${d.slice(1)}`
    return d
}
