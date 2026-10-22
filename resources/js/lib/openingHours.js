/**
 * Horaires d'ouverture (heure de Paris), réglés dans l'API de voxnode118 (settings.opening_hours)
 * et recopiés par le serveur SIP (GET /opening-hours). Chaque jour : null (fermé) ou { open, close } en HH:MM.
 */
export const DAYS = [
    ['monday', 'Lundi'],
    ['tuesday', 'Mardi'],
    ['wednesday', 'Mercredi'],
    ['thursday', 'Jeudi'],
    ['friday', 'Vendredi'],
    ['saturday', 'Samedi'],
    ['sunday', 'Dimanche'],
]

// Le serveur SIP recopie les horaires moins de 2 min après un changement : on attend avant d'alerter
const APPLY_DELAY = 3 * 60 * 1000
const STALE_AFTER = 10 * 60 * 1000

// Partagé entre les pages (même onglet) : heure du dernier enregistrement des horaires
let savedAt = 0
export const markOpeningHoursSaved = () => (savedAt = Date.now())

// Jour et heure à Paris, quel que soit le fuseau du navigateur
export function parisNow(date = new Date()) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Paris', weekday: 'long', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(date)
            .map((p) => [p.type, p.value]),
    )
    return { day: parts.weekday.toLowerCase(), time: `${parts.hour}:${parts.minute}` }
}
export const todayKey = () => parisNow().day

// Ouvert maintenant : de l'ouverture incluse à la fermeture exclue
export function isOpenNow(week) {
    const { day, time } = parisNow()
    const hours = week?.[day]
    return !!hours && time >= hours.open && time < hours.close
}

export const formatDay = (hours) => (hours ? `${hours.open} – ${hours.close}` : 'Fermé')

export function sameWeek(a, b) {
    if (!a || !b) return false
    return DAYS.every(([day]) => {
        const x = a[day] ?? null
        const y = b[day] ?? null
        return x === y || (!!x && !!y && x.open === y.open && x.close === y.close)
    })
}

/**
 * État de la copie sur le serveur SIP. `wanted` : settings.opening_hours (absent avant le déploiement),
 * `applied` : réponse de /opening-hours. Renvoie null quand il n'y a rien à signaler.
 * { level: 'pending' | 'error', text }
 */
export function openingHoursProblem(wanted, applied, now = Date.now()) {
    if (!wanted || !applied) return null
    const fetchedAt = applied.fetched_at ? applied.fetched_at * 1000 : null
    const recentSave = now - savedAt < APPLY_DELAY

    if (!sameWeek(wanted, applied.days)) {
        if (recentSave) return { level: 'pending', text: 'Nouveaux horaires en cours de transmission au serveur SIP (moins de 2 minutes).' }
        return {
            level: 'error',
            text: applied.days
                ? "Le serveur SIP n'applique pas les horaires enregistrés : il ne reçoit plus les changements."
                : "Le serveur SIP n'a encore reçu aucun horaire : il accepte tous les appels, à toute heure.",
        }
    }
    if (!fetchedAt || now - fetchedAt > STALE_AFTER) {
        return { level: 'error', text: "Le serveur SIP n'a pas relu les horaires depuis plus de 10 minutes : un changement ne lui parviendrait pas." }
    }
    return null
}
