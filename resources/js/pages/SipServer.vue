<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { AlertTriangle, ArrowRightLeft, Settings } from 'lucide-vue-next'
import { hoursHeadline, openingHoursProblem } from '@/lib/openingHours'
import { xsrfToken } from '@/lib/csrf'

/**
 * Serveur SIP : ce qui se passe en ce moment, lisible d'un coup d'œil.
 *   1. Ligne d'état : horaires, notre serveur, Voxnode, ligne OVH, fraîcheur des données
 *   2. Alertes : uniquement quand quelque chose ne va pas
 *   3. En ce moment : appels (conversation, attente, transferts) et agents (en ligne, libres, répartition)
 *   4. Appels en cours (notre serveur) et agents en ligne, côte à côte
 *   5. Replié en bas : régulateur, mode des agents (forcer une plateforme), dernières pannes
 * Le technique et le rare (version, stockage, pannes passées, mode des agents) est dans Configuration.
 *
 * « Notre serveur » = sip.118.ae ; « Voxnode » = l'autre plateforme. Agents, répartition et régulateur
 * viennent de l'API de voxnode118 ; l'état du serveur et ses appels de l'API de contrôle de notre serveur.
 */
const REFRESH_MS = 5000

const data = ref(null)
const telephony = ref(null)
const loading = ref(true)
const error = ref(null)
const loadedAt = ref(null)
// Horloge pour « actualisé il y a X s »
const now = ref(Date.now())
let clock = null
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)))
onUnmounted(() => clearInterval(clock))

// Fournisseurs routés chez nous (providers.config.selfhosted) : relus toutes les minutes au plus
const routedProviders = ref(null)
let providersLoadedAt = 0
async function loadProviders() {
    if (Date.now() - providersLoadedAt < 60000) return
    providersLoadedAt = Date.now()
    const res = await fetch('/data/providers/selfhosted', { headers: { Accept: 'application/json' } }).catch(() => null)
    if (res?.ok) routedProviders.value = (await res.json()).filter((p) => p.selfhosted)
}

async function load() {
    loadProviders()
    try {
        const [res, tel] = await Promise.all([
            fetch('/data/sip/status', { headers: { Accept: 'application/json' } }),
            fetch('/data/telephony', { headers: { Accept: 'application/json' } }),
        ])
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        loadedAt.value = Date.now()
        telephony.value = tel.ok ? await tel.json() : { configured: true, ok: false, error: `Erreur HTTP ${tel.status}` }
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

// Rafraîchissement régulier, suspendu quand l'onglet n'est pas visible
let timer = null
function onVisibility() {
    if (document.visibilityState === 'visible') load()
}
onMounted(() => {
    load()
    timer = setInterval(() => document.visibilityState === 'visible' && load(), REFRESH_MS)
    document.addEventListener('visibilitychange', onVisibility)
})
onUnmounted(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisibility)
})

const configured = computed(() => data.value?.configured !== false)
const formatTime = (v) => (v ? new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—')
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

// « 00:01:12 » → secondes ; secondes → « 1 min 12 »
const toSeconds = (hms) => (hms ? hms.split(':').reduce((acc, n) => acc * 60 + Number(n), 0) : 0)
function wait(seconds) {
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return m ? `${m} min ${String(s).padStart(2, '0')}` : `${s} s`
}

// ─── Sources ──────────────────────────────────────────────────────────────────
const tel = computed(() => (telephony.value?.ok ? telephony.value.data : null))
const telSettings = computed(() => tel.value?.settings ?? {})
const telAgents = computed(() => tel.value?.agents ?? null)
const localAgents = computed(() => data.value?.agents?.data?.agents ?? [])

// ─── 1. Ça tourne ? ───────────────────────────────────────────────────────────
const health = computed(() => data.value?.health)
const serverUp = computed(() => !!(health.value?.ok && health.value.data?.asterisk === 'up'))
const registrations = computed(() => Object.entries(health.value?.data?.registrations ?? {}))
const lineDown = computed(() => registrations.value.length > 0 && registrations.value.some(([, s]) => s !== 'Registered'))

// Chaque élément dit son état en toutes lettres ; rouge seulement en cas de problème
// Notre serveur en panne d'après la surveillance de voxnode118 (health.state), quel que soit le mode de bascule
const ourHealthDown = computed(() => tel.value?.health?.state === 'down')

// Voxnode injoignable : heure du premier échec de lecture de ses appels, effacée au premier succès.
// Signalé après 1 min d'échecs sans interruption.
const voxnodeFailingSince = ref(null)
const nowTick = ref(Date.now())
const nowTimer = setInterval(() => (nowTick.value = Date.now()), 15000)
onUnmounted(() => clearInterval(nowTimer))
watch(
    () => data.value?.voxnode,
    (v) => {
        if (!v) return
        if (v.ok === false) voxnodeFailingSince.value ??= Date.now()
        else voxnodeFailingSince.value = null
    },
)
// Depuis quand : la surveillance de voxnode118 (voxnode_health.down_since) si elle le dit, sinon la lecture des appels ici
const voxnodeDownMinutes = computed(() => {
    const vh = tel.value?.voxnode_health
    const since = vh?.state === 'down' && vh.down_since ? new Date(vh.down_since).getTime() : voxnodeFailingSince.value
    if (since === null || nowTick.value - since < 60 * 1000) return null
    return Math.max(1, Math.floor((nowTick.value - since) / 60000))
})

// Trunk vers les marques (transferts de notre serveur) : état vu par le serveur SIP
const trunkState = computed(() => health.value?.data?.trunks?.['voxnode-trunk'] ?? null)


const statusItems = computed(() => {
    if (!data.value) return []
    const st = telSettings.value
    let ours = { value: 'Actif', problem: false }
    if (!serverUp.value) ours = { value: 'Ne répond pas', problem: true }
    else if (tel.value && !st.selfhosted_enabled) ours = { value: 'Désactivé', problem: false }
    else if (tel.value && st.selfhosted_manual_off) ours = { value: 'Désactivé', problem: false }
    // Panne vue par la surveillance de voxnode118
    else if (ourHealthDown.value) ours = { value: 'En panne', problem: true }

    const items = []
    if (hoursNow.value) items.push({ label: 'Horaires (notre serveur)', value: hoursNow.value.text, problem: false })
    items.push({ label: 'Notre serveur', ...ours })
    if (tel.value && 'selfhosted_transfers_internal' in st) {
        items.push({
            label: 'Transferts (notre serveur)',
            value: st.selfhosted_transfers_internal ? 'Service interne' : 'Vers la marque',
            href: '/sip-server/settings#trafic',
            title: st.selfhosted_transfers_internal
                ? 'Sur notre serveur, tous les transferts vont au service interne. La marque choisie par l’agent reste notée sur l’appel.'
                : "Chaque transfert appelle la marque choisie par l'agent. Si elle ne décroche pas en 20 s, est occupée ou refuse, le client part au service interne.",
        })
    }
    if (routedProviders.value) {
        items.push({
            label: 'Fournisseurs autorisés chez nous',
            value: routedProviders.value.length ? routedProviders.value.map((p) => p.name).join(', ') : 'Aucun',
            href: '/providers',
            title: 'Seuls les numéros de ces fournisseurs peuvent arriver sur notre serveur. Ce choix se règle dans la fiche de chaque fournisseur.',
        })
    }
    if (tel.value) {
        items.push({
            label: 'Voxnode',
            value: st.voxnode_manual_off ? 'Désactivé' : voxnodeDownMinutes.value !== null ? 'Injoignable' : !tel.value.available?.voxnode ? 'Indisponible' : 'Actif',
            problem: !st.voxnode_manual_off && (voxnodeDownMinutes.value !== null || !tel.value.available?.voxnode),
        })
    }
    items.push({
        label: 'Trunk marques',
        value: trunkState.value === 'Avail' ? 'Joignable' : trunkState.value === 'Unavail' ? 'Injoignable' : '—',
        problem: trunkState.value === 'Unavail',
        title: 'Ligne par laquelle notre serveur appelle les marques lors d’un transfert',
    })
    items.push({
        label: 'Ligne OVH',
        value: !registrations.value.length ? '—' : lineDown.value ? 'Déconnectée' : 'Connectée',
        problem: lineDown.value,
        title: registrations.value.map(([line, state]) => `${line} : ${state}`).join(' · '),
    })
    return items
})

// Les données sont relues toutes les 5 s : au-delà de 20 s, elles sont figées
const freshness = computed(() => {
    if (!loadedAt.value) return null
    const s = Math.max(0, Math.round((now.value - loadedAt.value) / 1000))
    return s > 20 ? { text: `Pas de mise à jour depuis ${s} s`, problem: true } : { text: `Actualisé il y a ${s} s`, problem: false }
})

const hoursWanted = computed(() => telSettings.value?.opening_hours ?? null)
const hoursProblem = computed(() => openingHoursProblem(hoursWanted.value, data.value?.['opening-hours']?.data ?? null))
const hoursNow = computed(() => (data.value ? hoursHeadline(hoursWanted.value) : null))

// ─── 2. Alertes ───────────────────────────────────────────────────────────────
const DATA_LOW_BYTES = 20e9
const ROOT_LOW_BYTES = 5e9
function diskLow(prefix, min) {
    const d = health.value?.data ?? {}
    const total = d[`${prefix}_total`]
    return (total && d[`${prefix}_used`] / total > 0.9) || (d[`${prefix}_free`] != null && d[`${prefix}_free`] < min)
}

// Un agent sans plateforme quelques secondes est en train de basculer : signalé seulement au-delà de 30 s.
// En pause, le softphone se déconnecte volontairement du SIP : pas d'alerte (on_break absent avant le
// déploiement de voxnode118 : comportement d'avant).
const NO_PLATFORM_DELAY = 30 * 1000
const noPlatformSince = new Map()
const lostAgents = ref([])
watch(telAgents, (list) => {
    const now = Date.now()
    const current = new Set()
    for (const a of list ?? []) {
        if (!a.online || a.current_platform || a.on_break === true) continue
        current.add(a.id)
        if (!noPlatformSince.has(a.id)) noPlatformSince.set(a.id, now)
    }
    for (const id of [...noPlatformSince.keys()]) if (!current.has(id)) noPlatformSince.delete(id)
    lostAgents.value = (list ?? []).filter((a) => current.has(a.id) && now - noPlatformSince.get(a.id) >= NO_PLATFORM_DELAY)
})

const regulator = computed(() => tel.value?.regulator ?? null)
const lastRun = computed(() => regulator.value?.last_run ?? null)
const regulatorStale = computed(() => !!regulator.value && (!lastRun.value?.at || Date.now() - new Date(lastRun.value.at).getTime() > 2 * 60 * 1000))

const alerts = computed(() => {
    const list = []
    const st = telSettings.value
    if (data.value && !serverUp.value) list.push('Notre serveur ne répond pas.')
    if (tel.value && st.selfhosted_enabled && !st.selfhosted_manual_off && ourHealthDown.value) {
        list.push(`Notre serveur est en panne. Tout part chez Voxnode. Notre serveur recevra de nouveau des numéros après ${st.pbx_recovery_minutes} min sans problème.`)
    }
    // Redémarrage récent d'Asterisk : les appels en cours ont été coupés
    const restart = (tel.value?.health?.events ?? []).find((e) => e.state === 'restart' && Date.now() - new Date(e.at).getTime() < 30 * 60 * 1000)
    if (restart) list.push(`Notre serveur a redémarré à ${formatTime(restart.at)}. Les appels en cours à ce moment-là ont été coupés. ${restart.reason ?? ''}`.trim())
    // Lecture des réglages de téléphonie impossible : les alertes qui en dépendent ne peuvent plus s'afficher
    if (telephony.value?.ok === false) {
        list.push(`La page ne peut plus afficher les alertes de Voxnode, du trunk et du régulateur. Impossible de lire les réglages de téléphonie. Seuls les agents connectés à notre serveur sont affichés. Erreur : ${telephony.value.error}`)
    }
    // Voxnode désactivé automatiquement par voxnode118 parce qu'il ne répondait plus
    const vh = tel.value?.voxnode_health
    if (st?.voxnode_manual_off && vh?.auto_disabled_at) {
        const back =
            vh.state === 'up'
                ? `Il répond de nouveau depuis ${vh.up_since ? formatTime(vh.up_since) : '—'}.${st.voxnode_auto_on ? '' : ' Réactivez-le dans Configuration si tout est normal.'}`
                : 'Il ne répond toujours pas.'
        list.push(`Voxnode a été désactivé automatiquement à ${formatTime(vh.auto_disabled_at)} car il ne répondait plus. ${back}`)
    }
    if (trunkState.value === 'Unavail') list.push('Le trunk vers les marques ne répond plus : les transferts vont au service interne.')
    if (lineDown.value) list.push('La ligne OVH est déconnectée. Le softphone du panel ne peut plus appeler l’extérieur.')
    if (diskLow('disk_data', DATA_LOW_BYTES) || diskLow('disk_root', ROOT_LOW_BYTES)) list.push('Le disque de notre serveur est presque plein. Le détail est dans Configuration, onglet Serveur et stockage.')
    if (hoursProblem.value?.level === 'error') list.push(hoursProblem.value.text)
    if (regulatorStale.value) {
        list.push(`Le régulateur est arrêté : les agents ne sont plus déplacés entre notre serveur et Voxnode. ${lastRun.value?.at ? `Dernière décision à ${formatTime(lastRun.value.at)}.` : 'Aucune décision connue.'}`)
    }
    // switch_failed reste en base jusqu'à la bascule réussie suivante : seulement les échecs de moins de 15 min
    const failed = (telAgents.value ?? []).filter(
        (a) => a.online && a.switch_failed && a.switch_failed_at && Date.now() - new Date(a.switch_failed_at).getTime() < 15 * 60 * 1000,
    )
    // switch_failed : la plateforme visée par la bascule ratée (« selfhosted » ou « voxnode »)
    const failedTo = (platform) => failed.filter((a) => a.switch_failed === platform).map((a) => a.username).join(', ')
    const failedOurs = failedTo('selfhosted')
    const failedVoxnode = failedTo('voxnode')
    if (failedOurs) list.push(`Ces agents n’ont pas pu passer sur notre serveur : ${failedOurs}. Un nouvel essai aura lieu automatiquement dans quelques minutes.`)
    if (failedVoxnode) list.push(`Ces agents n’ont pas pu repasser chez Voxnode : ${failedVoxnode}. Nouvel essai dans 2 min.`)
    if (lostAgents.value.length) {
        list.push(`Ces agents sont connectés au panel mais leur téléphone n’est relié à aucune plateforme depuis plus de 30 s (téléphone pas allumé, déconnecté après un appel manqué, ou problème de connexion) : ${lostAgents.value.map((a) => a.username).join(', ')}.`)
    }
    // Voxnode ne répond plus (lecture de ses appels en échec depuis au moins 1 min)
    if (voxnodeDownMinutes.value !== null && !st?.voxnode_manual_off) {
        list.push(`Voxnode ne répond plus depuis ${voxnodeDownMinutes.value} min. Les transferts de ses agents et la connexion de ses agents ne marchent probablement plus. Si ça dure, désactivez Voxnode dans Configuration.`)
    }
    return list
})

// ─── 4a. Appels en cours sur notre serveur : un appel = plusieurs canaux, regroupés par linkedid ─
const agentNames = computed(() => new Set([...(telAgents.value ?? []), ...localAgents.value].map((a) => a.username)))

// Canal d'un agent : PJSIP/dedi014-0000002a
function channelAgent(channel) {
    const name = channel?.match(/^PJSIP\/(.+)-[0-9a-f]+$/)?.[1]
    return name && agentNames.value.has(name) ? name : null
}

// Étape d'un appel : mêmes libellés pour les deux plateformes. Plusieurs étapes partagent une clé
// (key) pour le tri et les chiffres du haut : les trois transferts, accueil et attente forcée à part.
const STAGES = {
    welcome: { key: 'welcome', label: 'Introduction' },
    forced: { key: 'forced', label: 'Attente forcée' },
    queue: { key: 'queue', label: "En attente d'un agent" },
    // Voxnode, ancien statut « queue » : accueil, attente forcée et file mélangés
    waiting: { key: 'waiting', label: 'En attente' },
    agent: { key: 'agent', label: 'Avec un agent' },
    ai: { key: 'ai', label: "Avec l'agent IA" },
    brand: { key: 'transfer', label: 'Transfert vers la marque' },
    internal: { key: 'transfer', label: 'Transféré au service interne' },
    // Chez nous : le client écoute le message avant transfert de la marque
    announcement: { key: 'transfer', label: 'Message avant transfert' },
    // Voxnode : transfert sans plus de précision
    transferred: { key: 'transfer', label: 'Transféré' },
    // Voxnode tant qu'il n'envoie pas le statut « agent » : « transferred » couvre les deux
    agent_or_transferred: { key: 'agent_or_transfer', label: 'Avec un agent ou transféré' },
    panel: { key: 'panel', label: 'Softphone du panel' },
}

// Chez nous, d'après le canal du client (contexte et application Asterisk en cours)
function callStatus(client, agentInCall) {
    switch (client.context) {
        case 'from-providers':
            if (client.app === 'Queue') return agentInCall ? STAGES.agent : STAGES.queue
            if (client.app === 'MusicOnHold') return STAGES.forced
            if (client.app === 'Dial') return STAGES.ai
            return STAGES.welcome
        case 'transfer-announce':
            return STAGES.announcement
        case 'internal-service':
            return STAGES.internal
        case 'transfer':
            return STAGES.brand
        case 'panel-dial':
        case 'panel-out':
            return STAGES.panel
        default:
            return { key: 'other', label: 'Transfert en cours' }
    }
}

const calls = computed(() => {
    const channels = data.value?.calls?.data?.calls ?? []
    const groups = {}
    for (const c of channels) (groups[c.linkedid || c.uniqueid] ??= []).push(c)
    return Object.values(groups)
        .map((chs) => {
            // Canal du client : celui qui a ouvert l'appel (uniqueid = linkedid)
            const first = chs.find((c) => c.uniqueid === c.linkedid) ?? chs[0]
            // Agent décroché : son canal est relié à l'appel par la file (AppQueue) et en ligne (Up)
            // (pendant la sonnerie, state = Ringing : le client est encore en attente et l'agent pas affiché)
            const agentChannel = chs.find((c) => channelAgent(c.channel) && c.app === 'AppQueue' && c.state === 'Up')
            const agent = agentChannel ? channelAgent(agentChannel.channel) : null
            return {
                id: first.linkedid || first.uniqueid,
                platform: 'selfhosted',
                caller: first.caller,
                // « s » = extension interne d'Asterisk, pas un numéro
                exten: first.exten === 's' ? null : first.exten,
                status: callStatus(first, !!agentChannel),
                agent,
                seconds: Math.max(...chs.map((c) => toSeconds(c.duration))),
            }
        })
        .sort((a, b) => b.seconds - a.seconds)
})

// Appels en cours chez Voxnode (relus par le panel toutes les 10 s)
const voxnode = computed(() => data.value?.voxnode ?? null)
const voxnodeCalls = computed(() =>
    (voxnode.value?.ok ? voxnode.value.calls : []).map((c) => ({
        id: `vx-${c.id}`,
        platform: 'voxnode',
        caller: c.caller,
        exten: c.called,
        status: STAGES[c.stage] ?? STAGES.waiting,
        agent: c.agent,
        seconds: c.started_at ? Math.max(0, Math.round(Date.now() / 1000 - c.started_at)) : 0,
    })),
)

// Les deux plateformes ensemble, du plus ancien au plus récent : une ligne ne change pas de place quand son étape change
const liveCalls = computed(() => [...calls.value, ...voxnodeCalls.value].sort((a, b) => b.seconds - a.seconds))

// Appels terminés : gardés 2 min dans le tableau, grisés, pour ne pas perdre de vue un appel qui vient de finir.
// Uniquement dans ce navigateur (rien n'est enregistré) : ils disparaissent si on recharge la page.
const ENDED_KEEP_MS = 2 * 60 * 1000
const endedCalls = ref([])
let seenCalls = new Map()
watch(liveCalls, (list) => {
    const now = Date.now()
    // Une plateforme dont la liste n'a pas pu être lue : ses appels ne sont pas considérés comme terminés
    const readable = { selfhosted: data.value?.calls?.ok !== false, voxnode: !!voxnode.value?.ok }
    const current = new Map(list.map((c) => [c.id, { ...c, startedAt: now - c.seconds * 1000 }]))
    const ended = endedCalls.value.filter((c) => now - c.endedAt < ENDED_KEEP_MS && !current.has(c.id))
    for (const [id, previous] of seenCalls) {
        if (current.has(id)) continue
        if (!readable[previous.platform]) {
            current.set(id, previous)
            continue
        }
        ended.push({ ...previous, lastStatus: previous.status, status: { key: 'ended', label: 'Terminé' }, endedAt: now })
    }
    seenCalls = current
    endedCalls.value = ended
})
const startedAt = (c) => c.startedAt ?? Date.now() - c.seconds * 1000
const tableCalls = computed(() => [...liveCalls.value, ...endedCalls.value].sort((a, b) => startedAt(a) - startedAt(b)))
const byStage = (key) => liveCalls.value.filter((c) => c.status.key === key)
const splitCalls = (list) => ({ ours: list.filter((c) => c.platform === 'selfhosted').length, theirs: list.filter((c) => c.platform === 'voxnode').length })

const callColumns = [
    { key: 'platform', label: 'Plateforme' },
    { key: 'caller', label: 'Client' },
    { key: 'exten', label: 'Numéro appelé' },
    { key: 'status', label: 'Étape' },
    { key: 'agent', label: 'Agent' },
    { key: 'seconds', label: 'Durée' },
]

// ─── 4b. Agents en ligne : en appel d'abord, puis libres ──────────────────────
const PLATFORMS = { selfhosted: 'Notre serveur', voxnode: 'Voxnode' }
const onlineAgents = computed(() => {
    if (telAgents.value) return telAgents.value.filter((a) => a.online)
    // Sans l'API voxnode118 : les agents connectés à notre serveur
    return localAgents.value.filter((a) => a.registered).map((a) => ({ ...a, current_platform: 'selfhosted', wanted_platform: 'selfhosted' }))
})
const byName = (a, b) => a.username.localeCompare(b.username)
const busyAgents = computed(() => onlineAgents.value.filter((a) => a.in_call).sort(byName))
const freeAgents = computed(() => onlineAgents.value.filter((a) => !a.in_call && a.on_break !== true).sort(byName))
const pausedAgents = computed(() => onlineAgents.value.filter((a) => !a.in_call && a.on_break === true).sort(byName))
const BREAK_TYPES = { pause: 'Pause', lunch: 'Déjeuner', manager: 'Manager' }
const breakLabel = (a) => (a.break_type ? (BREAK_TYPES[a.break_type] ?? a.break_type) : 'Pause')
const breakSince = (a) => (a.break_since ? new Date(a.break_since).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : null)
// Tableau des agents : état de chaque agent, filtre par état, ordre stable (par nom)
function agentState(a) {
    if (a.in_call) return { key: 'call', label: 'En appel', class: 'text-gray-900', since: callSeconds.value[a.username] != null ? wait(callSeconds.value[a.username]) : null }
    if (a.on_break === true) {
        const seconds = a.break_since ? Math.max(0, Math.floor((nowTick.value - Date.parse(a.break_since)) / 1000)) : null
        return { key: 'break', label: breakLabel(a), class: 'text-gray-500', since: seconds === null ? null : wait(seconds) }
    }
    if (!a.current_platform) return { key: 'nophone', label: 'Sans téléphone', class: 'text-red-600', since: null }
    return { key: 'free', label: 'Libre', class: 'text-green-700', since: null }
}
const agentFilter = ref('')
const agentFilters = computed(() => {
    const count = (key) => onlineAgents.value.filter((a) => agentState(a).key === key).length
    return [
        { key: '', label: 'Tous', count: onlineAgents.value.length },
        { key: 'call', label: 'En appel', count: count('call') },
        { key: 'free', label: 'Libres', count: count('free') },
        { key: 'break', label: 'En pause', count: count('break') },
        ...(count('nophone') ? [{ key: 'nophone', label: 'Sans téléphone', count: count('nophone') }] : []),
    ]
})
const agentRows = computed(() =>
    onlineAgents.value.filter((a) => !agentFilter.value || agentState(a).key === agentFilter.value).sort(byName),
)
// Durée de l'appel en cours, connue pour les agents en conversation sur notre serveur
const callSeconds = computed(() => Object.fromEntries(liveCalls.value.filter((c) => c.agent).map((c) => [c.agent, c.seconds])))
const moving = (a) => !!a.current_platform && !!a.wanted_platform && a.current_platform !== a.wanted_platform
const countOn = (list, key) => list.filter((a) => a.current_platform === key).length

// ─── Pauses : bon moment ou non pour qu'un agent parte en pause (indication seulement) ───
// GET /data/telephony/break-advice (API voxnode118), relu toutes les 15 s
const BREAK_ADVICE_MS = 15000
const breakAdvice = ref(null)
const breakAdviceAt = ref(0)
async function loadBreakAdvice() {
    const res = await fetch('/data/telephony/break-advice', { headers: { Accept: 'application/json' } }).catch(() => null)
    if (res?.status === 404) return (breakAdvice.value = null)
    breakAdvice.value = res?.ok ? await res.json() : { ok: false, error: res ? `Erreur HTTP ${res.status}` : 'Serveur injoignable' }
    breakAdviceAt.value = Date.now()
}
let breakTimer = null
onMounted(() => {
    loadBreakAdvice()
    breakTimer = setInterval(() => document.visibilityState === 'visible' && loadBreakAdvice(), BREAK_ADVICE_MS)
})
onUnmounted(() => clearInterval(breakTimer))

const BREAK_VERDICTS = {
    ok: { dot: 'bg-emerald-500', text: 'text-emerald-700', box: 'border-emerald-200 bg-emerald-50' },
    tight: { dot: 'bg-amber-500', text: 'text-amber-800', box: 'border-amber-200 bg-amber-50' },
    busy: { dot: 'bg-red-500', text: 'text-red-700', box: 'border-red-200 bg-red-50' },
    unknown: { dot: 'bg-gray-400', text: 'text-gray-600', box: 'border-gray-200 bg-gray-50' },
}
// Au-delà de ces durées, une pause est mise en évidence (sans doute un oubli)
const BREAK_LIMITS = { lunch: 60 * 60, pause: 20 * 60 }
// « 2 h 16 », « 9 min »
function breakDuration(seconds) {
    const m = Math.floor(seconds / 60)
    return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`
}
const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`
const parisHm = () => new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit' }).format(now.value)
const pauses = computed(() => {
    const d = breakAdvice.value?.ok ? breakAdvice.value.data : null
    if (!d) return null
    const live = d.live ?? {}
    const verdict = BREAK_VERDICTS[live.verdict] ? live.verdict : 'unknown'
    const callers = live.callers ?? {}
    const waiting = callers.waiting ?? {}
    const intro = callers.introOrForced ?? {}
    const agents = live.agents ?? {}
    const elapsed = Math.max(0, Math.floor((now.value - breakAdviceAt.value) / 1000))
    const requested = new Set(live.breaksRequested ?? [])
    const hm = parisHm()
    const side = (v) => (v === null || v === undefined ? '—' : v)
    return {
        verdict,
        style: BREAK_VERDICTS[verdict],
        message: live.message,
        // Agents libres face aux appelants qui auront besoin d'un agent maintenant ou sous 2-3 min
        callersLine:
            agents.free != null && waiting.total != null && intro.total != null
                ? `${plural(agents.free, 'agent libre', 'agents libres')} pour ${plural(waiting.total + intro.total, 'appelant', 'appelants')} en attente ou dans l'accueil`
                : null,
        callersTitle: `En attente d'un agent : ${side(waiting.server)} chez nous, ${side(waiting.voxnode)} chez Voxnode. Dans l'accueil ou l'attente forcée : ${side(intro.server)} chez nous, ${side(intro.voxnode)} chez Voxnode.`,
        okMargin: live.okMargin,
        breaksLine:
            agents.online != null
                ? `${agents.onBreak ?? 0} en pause sur ${agents.online} connectés${agents.lunch ? ` (dont ${agents.lunch} en déjeuner)` : ''}`
                : null,
        breaks: (live.breaks ?? []).map((b) => {
            const seconds = b.sinceSec + elapsed
            const limit = BREAK_LIMITS[b.type]
            return { ...b, kind: b.type, label: b.name ? `${b.id} · ${b.name}` : b.id, type: BREAK_TYPES[b.type] ?? b.type, since: breakDuration(seconds), long: !!limit && seconds > limit }
        }),
        requested: [...requested],
        usual: d.usual ?? null,
        quietest: d.usual?.quietest ?? [],
        slots: (d.usual?.slots ?? []).map((sl) => ({ ...sl, current: sl.slot <= hm && hm < sl.end })),
    }
})

// ─── 3. En ce moment : appels, puis agents ────────────────────────────────────
// Répartition d'une liste d'agents entre les deux plateformes (null sans l'API voxnode118)
const split = (list) => (tel.value ? { ours: countOn(list, 'selfhosted'), theirs: countOn(list, 'voxnode') } : null)

// Note du groupe Appels : Voxnode non branché, ou attentes sans détail d'étape
const callsNote = computed(() => {
    if (voxnode.value && !voxnode.value.ok) return voxnode.value.configured === false ? 'Appels Voxnode non disponibles ici' : `Impossible de lire les appels Voxnode. Erreur : ${voxnode.value.error}`
    const legacy = byStage('waiting').length
    return legacy ? `Plus ${legacy} en attente chez Voxnode, étape inconnue` : ''
})

// Part des nouveaux numéros envoyée chez nous : un pourcentage court, la raison en dessous
const shareFigure = computed(() => {
    const st = telSettings.value
    if (!tel.value) return { value: '—', sub: 'Réglages de téléphonie indisponibles' }
    if (!st.selfhosted_enabled) return { value: '0 %', sub: 'notre serveur désactivé' }
    if (st.selfhosted_manual_off) return { value: '0 %', sub: 'notre serveur désactivé' }
    if (!tel.value.available?.selfhosted) return { value: '0 %', sub: 'notre serveur en panne', problem: true }
    if (st.voxnode_manual_off) return { value: '100 %', sub: 'Voxnode désactivé' }
    return { value: `${st.selfhosted_percent} %`, sub: 'des nouveaux numéros' }
})

// Tableau du haut : notre serveur et Voxnode côte à côte, puis le total.
// Une valeur null s'affiche « — » (donnée indisponible, par exemple sans les réglages de téléphonie).
const longestWait = (platform) => {
    const list = byStage('queue').filter((c) => c.platform === platform)
    return list.length ? Math.max(...list.map((c) => c.seconds)) : null
}
const shareOurs = computed(() => {
    const m = shareFigure.value.value.match(/^(\d+) %$/)
    return m ? Number(m[1]) : null
})
// Ligne « Total » d'une section : somme des lignes chiffrées (hors durées), mise en gras
function withTotalRow(section, label) {
    const numeric = section.rows.filter((r) => !r.text)
    const sum = (side) => (numeric.some((r) => r[side] === null) ? null : numeric.reduce((acc, r) => acc + r[side], 0))
    const ours = sum('ours')
    const theirs = sum('theirs')
    return { ...section, rows: [...section.rows, { label, ours, theirs, total: ours === null || theirs === null ? null : ours + theirs, strong: true }] }
}
const overviewSections = computed(() => {
    const numbers = data.value?.numbers ?? null
    const agentsSplit = (list) => split(list) ?? { ours: list.filter((a) => a.current_platform === 'selfhosted').length, theirs: null }
    const welcome = [...byStage('welcome'), ...byStage('forced')]
    const queue = byStage('queue')
    const row = (label, ours, theirs, extra = {}) => ({ label, ours, theirs, total: ours === null || theirs === null ? null : ours + theirs, ...extra })
    const busy = agentsSplit(busyAgents.value)
    const online = agentsSplit(onlineAgents.value)
    const free = agentsSplit(freeAgents.value)
    const longOurs = longestWait('selfhosted')
    const longTheirs = longestWait('voxnode')
    return [
        {
            title: 'Numéros',
            rows: [
                row('Numéros actifs', numbers?.selfhosted ?? null, numbers?.voxnode ?? null, { title: 'Numéros routés en ce moment vers chaque plateforme' }),
                {
                    label: 'Part des nouveaux numéros',
                    ours: shareOurs.value === null ? shareFigure.value.value : `${shareOurs.value} %`,
                    theirs: shareOurs.value === null ? '—' : `${100 - shareOurs.value} %`,
                    total: null,
                    text: true,
                    note: shareFigure.value.sub !== 'des nouveaux numéros' ? shareFigure.value.sub : '',
                    problem: shareFigure.value.problem,
                },
            ],
        },
        withTotalRow({
            title: 'Appels',
            note: callsNote.value,
            // Dans l'ordre du parcours de l'appel
            rows: [
                row('Introduction et attente forcée', splitCalls(welcome).ours, splitCalls(welcome).theirs),
                row("En attente d'un agent", splitCalls(queue).ours, splitCalls(queue).theirs, { warn: true }),
                {
                    label: 'Attente la plus longue',
                    ours: longOurs === null ? '—' : wait(longOurs),
                    theirs: longTheirs === null ? '—' : wait(longTheirs),
                    // Total : le client qui attend depuis le plus longtemps, toutes plateformes confondues
                    total: longOurs === null && longTheirs === null ? '—' : wait(Math.max(longOurs ?? 0, longTheirs ?? 0)),
                    text: true,
                    oursProblem: longOurs !== null && longOurs > 120,
                    theirsProblem: longTheirs !== null && longTheirs > 120,
                    title: "Client qui attend un agent depuis le plus longtemps, compté depuis le début de son appel",
                },
                row('Avec un agent', busy.ours, busy.theirs),
                row('Transférés', splitCalls(byStage('transfer')).ours, splitCalls(byStage('transfer')).theirs, { title: 'Appels transférés vers une marque ou le service interne, encore en cours' }),
            ],
        }, 'Total en cours'),
        {
            title: 'Agents',
            rows: [row('En ligne', online.ours, online.theirs), row('Libres', free.ours, free.theirs)],
        },
    ]
})

// ─── 6. Mode des agents : forcer une plateforme (tous les agents, y compris hors ligne) ─
const PIN_OPTIONS = [
    { value: '', label: 'Automatique' },
    { value: 'selfhosted', label: 'Toujours notre serveur' },
    { value: 'voxnode', label: 'Toujours Voxnode' },
]
const modesOpen = ref(false)
const pinBusy = ref(false)
const pinMessage = ref(null) // { ok, text }
const modeSearch = ref('')
const allAgents = computed(() => {
    const q = modeSearch.value.trim().toLowerCase()
    return [...(telAgents.value ?? [])]
        .filter((a) => !q || [a.username, a.name, a.callcenter].some((v) => v?.toLowerCase().includes(q)))
        .sort(byName)
})
const pinnedCount = computed(() => (telAgents.value ?? []).filter((a) => a.pin).length)
const modeColumns = [
    { key: 'name', label: 'Agent' },
    { key: 'callcenter', label: 'Centre' },
    { key: 'online', label: 'En ligne' },
    { key: 'platform', label: 'Plateforme actuelle' },
    { key: 'pin', label: 'Mode' },
]
async function setPin(agent, value) {
    pinBusy.value = true
    try {
        const res = await fetch(`/data/telephony/agents/${agent.id}`, {
            method: 'PUT',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ pin: value || null }),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.message || `Erreur HTTP ${res.status}`)
        pinMessage.value = { ok: true, text: `${agent.username} est maintenant en mode « ${PIN_OPTIONS.find((o) => o.value === value)?.label} »` }
        await load()
    } catch (e) {
        pinMessage.value = { ok: false, text: e.message }
    } finally {
        pinBusy.value = false
    }
}
// Lien « Mode des agents » depuis la Configuration : ouvre la section
onMounted(() => {
    if (window.location.hash === '#modes') modesOpen.value = true
})

// ─── 8. Réseau des agents (agent_network_quality, tranches de 5 min) ─────────
// À surveiller : SIP moyen > 600 ms, OPTIONS sans réponse ou coupures répétés
const networkOpen = ref(false)
const networkHours = ref(24)
const network = ref(null)
const networkLoading = ref(false)
async function loadNetwork() {
    networkLoading.value = true
    try {
        const res = await fetch(`/data/telephony/network?hours=${networkHours.value}`, { headers: { Accept: 'application/json' } })
        network.value = res.ok ? await res.json() : null
    } finally {
        networkLoading.value = false
    }
}
watch(networkHours, () => networkOpen.value && loadNetwork())
watch(networkOpen, (open) => open && !network.value && loadNetwork())

const liveNetwork = computed(() => Object.fromEntries((telAgents.value ?? []).map((a) => [a.username, a])))
const networkFlag = (r) => (r.sip_avg !== null && r.sip_avg > 600) || r.sip_lost >= 3 || r.disconnects >= 2
const networkRows = computed(() =>
    [...(network.value?.agents ?? [])]
        .map((r) => ({ ...r, flagged: networkFlag(r), live: liveNetwork.value[r.username] ?? null }))
        .sort((a, b) => b.flagged - a.flagged || (b.sip_avg ?? 0) - (a.sip_avg ?? 0)),
)
const flaggedCount = computed(() => networkRows.value.filter((r) => r.flagged).length)
// Mesure en direct : ancienne au-delà de 10 min
function liveText(a) {
    if (!a?.net_sip_measured_at && !a?.net_measured_at) return { text: '—', old: true }
    const at = new Date(a.net_sip_measured_at ?? a.net_measured_at).getTime()
    const old = Date.now() - at > 10 * 60 * 1000
    return { text: `serveur ${a.net_sip_ms ?? '—'} ms · site ${a.net_http_ms ?? '—'} ms`, old, at }
}
const ms = (v) => (v === null || v === undefined ? '—' : `${new Intl.NumberFormat('fr-FR').format(v)} ms`)

// ─── 7. Dernières pannes de notre serveur (détectées par voxnode118) ─────────
// Types d'événements de santé : panne, retour, redémarrage d'Asterisk (appels en cours coupés)
const HEALTH_EVENTS = {
    down: { label: 'Panne', class: 'text-red-600' },
    up: { label: 'Rétabli', class: 'text-gray-700' },
    restart: { label: 'Redémarrage', class: 'text-amber-700' },
    trunk_down: { label: 'Trunk vers les marques injoignable', class: 'text-red-600' },
    trunk_up: { label: 'Trunk vers les marques de nouveau joignable', class: 'text-gray-700' },
}
const healthEvents = computed(() => [...(tel.value?.health?.events ?? [])].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')).slice(0, 10))

// ─── 5. Régulateur ────────────────────────────────────────────────────────────
// Décalage d'agents autour du pourcentage : +2 = 2 agents de plus chez nous
const offsetText = (v) => (v === null || v === undefined ? '—' : v === 0 ? 'aucun' : `${v > 0 ? '+' : '−'}${Math.abs(v)} agent${Math.abs(v) > 1 ? 's' : ''} chez nous`)
const regulatorEvents = computed(() => (regulator.value?.events ?? []).slice(0, 10))
const sinceLastRun = computed(() => {
    if (!lastRun.value?.at) return ''
    const s = Math.max(0, Math.round((Date.now() - new Date(lastRun.value.at).getTime()) / 1000))
    return s < 60 ? `il y a ${s} s` : `à ${formatTime(lastRun.value.at)}`
})
</script>

<template>
    <Head title="Serveur SIP" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Serveur SIP' }]">
                <template #actions>
                    <Button variant="ghost" size="sm" class="text-gray-500" as-child>
                        <Link href="/sip-server/settings">
                            <Settings class="mr-2 h-4 w-4" />
                            Configuration
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de charger les données. Erreur : {{ error }}</p>
            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                Le lien avec notre serveur n'est pas configuré. À faire par le développeur : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis lancer php artisan config:cache.
            </div>

            <template v-else>
                <!-- État : chaque élément dit son état en toutes lettres -->
                <div class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border bg-white px-4 py-2.5 text-sm" :class="statusItems.some((i) => i.problem) ? 'border-red-200' : 'border-gray-100'">
                    <div v-if="loading" class="h-5 w-96 animate-pulse rounded bg-gray-100" />
                    <component
                        :is="item.href ? Link : 'span'"
                        v-for="item in statusItems"
                        :key="item.label"
                        :href="item.href"
                        class="flex items-center gap-2"
                        :class="item.href && 'hover:underline'"
                        :title="item.title"
                    >
                        <span class="text-gray-500">{{ item.label }}</span>
                        <span class="font-medium" :class="item.problem ? 'text-red-600' : 'text-gray-900'">{{ item.value }}</span>
                    </component>
                    <span v-if="freshness" class="ml-auto text-xs tabular-nums" :class="freshness.problem ? 'font-medium text-red-600' : 'text-gray-400'">{{ freshness.text }}</span>
                </div>

                <!-- Alertes : seulement en cas de problème -->
                <div v-if="alerts.length || hoursProblem?.level === 'pending'" class="space-y-2">
                    <p v-for="text in alerts" :key="text" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        {{ text }}
                    </p>
                    <p v-if="hoursProblem?.level === 'pending'" class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ hoursProblem.text }}</p>
                </div>

                <!-- En ce moment : trois petits tableaux (numéros, appels, agents), notre serveur et Voxnode côte à côte -->
                <!-- Largeurs selon la longueur des libellés : chaque libellé tient sur une ligne -->
                <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.3fr)_minmax(0,0.9fr)]">
                    <div v-for="section in overviewSections" :key="section.title" class="overflow-hidden rounded-lg border border-gray-100 bg-white">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-xs text-gray-500">
                                    <th class="px-3 py-2 text-left font-semibold text-gray-900">{{ section.title }}</th>
                                    <th class="w-16 px-2 py-2 text-right font-medium whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-gray-900" />Nous</span>
                                    </th>
                                    <th class="w-20 px-2 py-2 text-right font-medium whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-gray-300" />Voxnode</span>
                                    </th>
                                    <th class="w-12 px-2 py-2 pr-3 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in section.rows" :key="r.label" class="hover:bg-gray-50" :class="r.strong ? 'border-t border-gray-200 bg-gray-50/60 font-semibold' : 'border-t border-gray-50'" :title="r.title">
                                    <td class="px-3 py-1.5 whitespace-nowrap text-gray-700">
                                        {{ r.label }}
                                        <span v-if="r.note" class="block text-xs" :class="r.problem ? 'text-red-600' : 'text-gray-400'">{{ r.note }}</span>
                                    </td>
                                    <template v-if="loading">
                                        <td v-for="i in 3" :key="i" class="px-2 py-1.5"><div class="ml-auto h-5 w-8 animate-pulse rounded bg-gray-100" /></td>
                                    </template>
                                    <template v-else>
                                        <td
                                            class="px-2 py-1.5 text-right font-semibold whitespace-nowrap tabular-nums"
                                            :class="r.oursProblem || (r.problem && r.text) ? 'text-red-600' : r.warn && r.ours > 0 ? 'text-amber-700' : 'text-gray-900'"
                                        >
                                            {{ r.ours ?? '—' }}
                                        </td>
                                        <td
                                            class="px-2 py-1.5 text-right font-semibold whitespace-nowrap tabular-nums"
                                            :class="r.theirsProblem ? 'text-red-600' : r.warn && r.theirs > 0 ? 'text-amber-700' : 'text-gray-900'"
                                        >
                                            {{ r.theirs ?? '—' }}
                                        </td>
                                        <td class="px-2 py-1.5 pr-3 text-right whitespace-nowrap tabular-nums" :class="r.strong ? 'text-gray-900' : 'text-gray-500'">{{ r.total ?? '' }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="section.note" class="border-t border-gray-50 px-3 py-1.5 text-xs text-gray-400">{{ section.note }}</p>
                    </div>
                </div>

                <!-- Pauses : bon moment ou non pour qu'un agent parte en pause -->
                <section v-if="breakAdvice" class="rounded-lg border border-gray-100 bg-white">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-100 px-3 py-2">
                        <h2 class="text-sm font-semibold text-gray-900">Pauses</h2>
                        <template v-if="pauses">
                            <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-sm font-medium" :class="[pauses.style.box, pauses.style.text]">
                                <span class="h-2 w-2 rounded-full" :class="pauses.style.dot" />
                                {{ pauses.message || '—' }}
                            </span>
                            <span v-if="pauses.callersLine" class="text-sm text-gray-600" :title="pauses.callersTitle">{{ pauses.callersLine }}</span>
                        </template>
                        <span v-else class="text-sm text-red-600">Impossible de lire le conseil de pause. Erreur : {{ breakAdvice.error }}</span>
                    </header>
                    <div v-if="pauses" class="grid grid-cols-1 items-start gap-x-6 gap-y-4 px-3 py-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <div class="min-w-0 space-y-3">
                            <table class="text-sm">
                                <tbody>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-1 pr-4 whitespace-nowrap text-gray-500">En pause</td>
                                        <td class="py-1 text-gray-900">{{ pauses.breaksLine ?? '—' }}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-1 pr-4 whitespace-nowrap text-gray-500">Pauses demandées</td>
                                        <td class="py-1 text-gray-900">
                                            <template v-if="pauses.requested.length">{{ pauses.requested.join(', ') }} <span class="text-gray-400">· commenceront à la fin de leur appel</span></template>
                                            <template v-else>—</template>
                                        </td>
                                    </tr>
                                    <tr v-if="pauses.okMargin != null" class="hover:bg-gray-50">
                                        <td class="py-1 pr-4 whitespace-nowrap text-gray-500">Bon moment si</td>
                                        <td class="py-1 text-gray-900">au moins {{ pauses.okMargin }} agent{{ pauses.okMargin > 1 ? 's' : '' }} libre{{ pauses.okMargin > 1 ? 's' : '' }} en plus des appelants</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Journée habituelle : forme des 4 derniers mêmes jours de semaine, sans nombre d'appels prévu -->
                            <div v-if="pauses.usual">
                                <p v-if="pauses.usual.closedToday" class="text-sm text-gray-600">Fermé aujourd'hui</p>
                                <template v-else>
                                    <p class="text-sm text-gray-600">
                                        <span class="text-gray-500">Habituellement calme :</span>&#32;<span class="text-gray-900">{{ pauses.quietest.length ? pauses.quietest.join(', ') : '—' }}</span>
                                    </p>
                                    <div v-if="pauses.slots.length" class="mt-2">
                                        <div class="flex h-12 items-end gap-0.5">
                                            <div
                                                v-for="sl in pauses.slots"
                                                :key="sl.slot"
                                                class="flex h-full min-w-0 flex-1 items-end rounded-sm"
                                                :class="sl.current ? 'bg-gray-100 ring-1 ring-gray-900' : ''"
                                                :title="`${sl.slot}–${sl.end} · ${String(sl.share).replace('.', ',')} % des appels de la journée · ${{ calm: 'calme', normal: 'normal', busy: 'chargé' }[sl.level] ?? sl.level}${sl.current ? ' · en cours' : ''}`"
                                            >
                                                <div
                                                    class="w-full rounded-t-sm"
                                                    :class="{ calm: 'bg-emerald-400', normal: 'bg-gray-300', busy: 'bg-amber-500' }[sl.level] ?? 'bg-gray-300'"
                                                    :style="{ height: `${Math.max(8, (sl.share / Math.max(...pauses.slots.map((x) => x.share), 1)) * 100)}%` }"
                                                />
                                            </div>
                                        </div>
                                        <div class="mt-1 flex justify-between text-xs tabular-nums text-gray-400">
                                            <span>{{ pauses.slots[0].slot }}</span>
                                            <span class="flex items-center gap-3">
                                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-emerald-400" />Calme</span>
                                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-gray-300" />Normal</span>
                                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-amber-500" />Chargé</span>
                                            </span>
                                            <span>{{ pauses.slots[pauses.slots.length - 1].end }}</span>
                                        </div>
                                    </div>
                                    <p v-if="pauses.usual.basedOn?.length" class="mt-1 text-xs text-gray-400">
                                        D'après les {{ pauses.usual.basedOn.length }} derniers {{ new Date(pauses.usual.basedOn[0]).toLocaleDateString('fr-FR', { weekday: 'long' }) }}s
                                    </p>
                                </template>
                            </div>
                        </div>

                        <!-- Agents en pause, du plus ancien au plus récent -->
                        <div class="min-w-0 overflow-hidden rounded-lg border border-gray-100">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100 text-xs text-gray-500">
                                        <th class="px-3 py-1.5 text-left font-medium">Agent en pause</th>
                                        <th class="px-2 py-1.5 text-left font-medium">Type</th>
                                        <th class="px-3 py-1.5 text-right font-medium">Depuis</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in pauses.breaks" :key="b.id" class="border-t border-gray-50 hover:bg-gray-50">
                                        <td class="px-3 py-1 whitespace-nowrap text-gray-900">{{ b.label }}</td>
                                        <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ b.type }}</td>
                                        <td
                                            class="px-3 py-1 text-right whitespace-nowrap tabular-nums"
                                            :class="b.long ? 'font-semibold text-red-600' : 'text-gray-900'"
                                            :title="b.long ? (b.kind === 'lunch' ? 'Déjeuner de plus de 60 min' : 'Pause de plus de 20 min') : undefined"
                                        >
                                            {{ b.since }}
                                        </td>
                                    </tr>
                                    <tr v-if="!pauses.breaks.length">
                                        <td colspan="3" class="px-3 py-2 text-gray-400">Aucun agent en pause</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p class="border-t border-gray-50 px-3 py-1.5 text-xs text-gray-400">Indication seulement : une pause n'est jamais bloquée.</p>
                </section>

                <!-- Appels et agents, côte à côte -->
                <div class="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_27rem]">
                    <section class="min-w-0">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">
                            Appels en cours <span class="ml-1 font-normal tabular-nums text-gray-400">{{ liveCalls.length }}</span>
                        </h2>
                        <div class="rounded-lg border border-gray-100 bg-white">
                            <DataTable
                                :columns="callColumns"
                                :rows="tableCalls"
                                :row-class="(row) => (row.status.key === 'ended' ? 'opacity-50' : '')"
                                :loading="loading"
                                :empty-message="data?.calls?.ok === false ? `Impossible de lire les appels de notre serveur. Erreur : ${data.calls.error}` : 'Aucun appel en cours'"
                            >
                                <template #platform="{ value }">
                                    <span class="flex items-center gap-1.5 whitespace-nowrap text-sm text-gray-600">
                                        <span class="h-2 w-2 rounded-sm" :class="value === 'selfhosted' ? 'bg-gray-900' : 'bg-gray-300'" />
                                        {{ PLATFORMS[value] }}
                                    </span>
                                </template>
                                <template #caller="{ value }"><span class="font-mono text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #exten="{ value }"><span class="font-mono text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #status="{ value, row }">
                                    <span v-if="value.key === 'ended'" class="whitespace-nowrap text-sm text-gray-500">Terminé <span class="text-gray-400">· {{ row.lastStatus.label }}</span></span>
                                    <span v-else class="whitespace-nowrap text-sm" :class="value.key === 'queue' ? 'font-medium text-amber-700' : 'text-gray-700'" :title="value.title">{{ value.label }}</span>
                                </template>
                                <template #agent="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #seconds="{ value }"><span class="whitespace-nowrap text-sm tabular-nums text-gray-900">{{ wait(value) }}</span></template>
                            </DataTable>
                        </div>
                    </section>

                    <!-- Sous les appels sur les écrans moyens (largeur limitée), à côté sur les grands -->
                    <section class="min-w-0 max-w-2xl 2xl:max-w-none">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">
                            Agents en ligne <span class="ml-1 font-normal tabular-nums text-gray-400">{{ onlineAgents.length }}</span>
                        </h2>
                        <!-- Filtre par état, avec le nombre d'agents de chaque état -->
                        <div class="mb-2 flex flex-wrap gap-1">
                            <button
                                v-for="f in agentFilters"
                                :key="f.key"
                                type="button"
                                class="h-7 rounded-md px-2.5 text-xs font-medium"
                                :class="agentFilter === f.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                                @click="agentFilter = f.key"
                            >
                                {{ f.label }} <span class="tabular-nums opacity-70">{{ f.count }}</span>
                            </button>
                        </div>
                        <!-- Une ligne par agent, triée par nom : chaque agent garde sa place quand son état change -->
                        <div class="overflow-hidden rounded-lg border border-gray-100 bg-white">
                            <div v-if="loading" class="h-24 animate-pulse" />
                            <p v-else-if="!agentRows.length" class="px-4 py-6 text-center text-sm text-gray-400">Aucun agent</p>
                            <table v-else class="w-full text-sm">
                                <thead class="border-b border-gray-100 text-left text-xs text-gray-400 uppercase">
                                    <tr>
                                        <th class="px-3 py-2 font-medium tracking-wide">Agent</th>
                                        <th class="px-2 py-2 font-medium tracking-wide">Plateforme</th>
                                        <th class="px-2 py-2 font-medium tracking-wide">État</th>
                                        <th class="px-3 py-2 text-right font-medium tracking-wide" title="Durée de l’appel en cours ou de la pause">Durée</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in agentRows" :key="a.username" class="border-t border-gray-50 hover:bg-gray-50" :title="a.name">
                                        <td class="px-3 py-1.5 font-medium whitespace-nowrap text-gray-900">{{ a.username }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-gray-600">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="h-2 w-2 rounded-sm" :class="a.current_platform === 'selfhosted' ? 'bg-gray-900' : a.current_platform ? 'bg-gray-300' : 'bg-red-500'" />
                                                {{ a.current_platform ? PLATFORMS[a.current_platform] : 'Aucune' }}
                                            </span>
                                            <span v-if="moving(a)" class="ml-1 inline-flex items-center gap-0.5 text-xs text-amber-700">
                                                <ArrowRightLeft class="h-3 w-3" />{{ PLATFORMS[a.wanted_platform] }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-1.5 whitespace-nowrap" :class="agentState(a).class">{{ agentState(a).label }}</td>
                                        <td class="px-3 py-1.5 text-right whitespace-nowrap tabular-nums text-gray-700">{{ agentState(a).since ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- Régulateur, replié : on ne l'ouvre que pour comprendre une décision -->
                <details v-if="regulator" class="rounded-lg border border-gray-100 bg-white text-sm">
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                        <span class="font-semibold text-gray-900">Régulateur</span>
                        <span class="min-w-0 flex-1 truncate text-gray-600">{{ lastRun?.reason || '—' }}</span>
                        <span class="shrink-0 text-xs text-gray-400">{{ sinceLastRun }}</span>
                    </summary>
                    <div class="grid gap-6 border-t border-gray-100 px-4 py-3 lg:grid-cols-[26rem_1fr]">
                        <dl class="space-y-1">
                            <div class="flex justify-between"><dt class="text-gray-500">Agents automatiques en ligne</dt><dd class="tabular-nums">{{ lastRun?.active_agents ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Agents chez nous / objectif</dt><dd class="tabular-nums">{{ lastRun?.on_selfhosted ?? '—' }} / {{ lastRun?.target ?? '—' }}</dd></div>
                            <div class="flex justify-between" title="Agents envoyés en renfort chez nous (+) ou chez Voxnode (−), selon l'attente des clients des deux côtés. Revient vers 0, d'un agent toutes les 5 min quand personne n'attend.">
                                <dt class="cursor-help text-gray-500">Décalage d'agents</dt>
                                <dd class="tabular-nums">{{ offsetText(lastRun?.offset) }}</dd>
                            </div>
                            <template v-if="lastRun?.queue">
                                <div class="flex justify-between"><dt class="text-gray-500">File chez nous</dt><dd class="tabular-nums">{{ lastRun.queue.waiting }}<template v-if="lastRun.queue.waiting_long"> · dont {{ lastRun.queue.waiting_long }} depuis plus de {{ telSettings.regulator_wait_seconds ?? 20 }} s</template></dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Attente la plus longue chez nous</dt><dd class="tabular-nums">{{ lastRun.queue.max_wait }} s</dd></div>
                            </template>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">File chez Voxnode</dt>
                                <dd class="tabular-nums" :class="!lastRun?.voxnode_queue && 'text-gray-400'">
                                    <template v-if="lastRun?.voxnode_queue">
                                        {{ lastRun.voxnode_queue.calls }} appels · {{ lastRun.voxnode_queue.natural }} attendent un agent après l'attente forcée<template v-if="lastRun.voxnode_queue.waiting_long"> · dont {{ lastRun.voxnode_queue.waiting_long }} depuis plus de 30 s</template>
                                    </template>
                                    <template v-else>non mesurée</template>
                                </dd>
                            </div>
                        </dl>
                        <ul v-if="regulatorEvents.length" class="space-y-1">
                            <li v-for="(e, i) in regulatorEvents" :key="i" class="flex gap-3">
                                <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                <span class="min-w-0 flex-1 text-gray-700">
                                    {{ e.type === 'reset' ? 'Le régulateur repart de zéro. Notre serveur était désactivé ou en panne, ou Voxnode était désactivé.' : e.reason }}
                                    <span v-if="e.to_selfhosted?.length" class="ml-1 text-gray-500">→ chez nous : {{ e.to_selfhosted.join(', ') }}</span>
                                    <span v-if="e.to_voxnode?.length" class="ml-1 text-gray-500">→ Voxnode : {{ e.to_voxnode.join(', ') }}</span>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-gray-400">Aucun déplacement récent</p>
                    </div>
                </details>

                <!-- Mode des agents : forcer une plateforme (tous les agents) -->
                <details v-if="telAgents" id="modes" :open="modesOpen" class="scroll-mt-6 rounded-lg border border-gray-100 bg-white text-sm" @toggle="modesOpen = $event.target.open">
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                        <span class="font-semibold text-gray-900">Mode des agents</span>
                        <span class="text-gray-600">{{ pinnedCount ? `${pinnedCount} agent${pinnedCount > 1 ? 's' : ''} forcé${pinnedCount > 1 ? 's' : ''} sur une plateforme` : 'Tous les agents en automatique' }}</span>
                    </summary>
                    <div class="border-t border-gray-100">
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 text-xs text-gray-500">
                            <input v-model="modeSearch" type="search" placeholder="Rechercher un agent, un nom ou un centre…" class="h-8 w-56 rounded-md border border-gray-200 px-2 text-sm text-gray-900" />
                            Automatique : le régulateur envoie l'agent là où sont les clients, et le déplace en cas de panne ou de désactivation. Forcé : l'agent reste sur la même plateforme, même en cas de panne.
                            <span v-if="pinMessage" :class="pinMessage.ok ? 'text-gray-900' : 'text-red-600'">{{ pinMessage.text }}</span>
                        </p>
                        <DataTable :columns="modeColumns" :rows="allAgents" :loading="loading" empty-message="Aucun agent">
                            <template #name="{ row }">
                                <span class="font-medium text-gray-900">{{ row.username }}</span>
                                <span v-if="row.name" class="ml-2 text-xs text-gray-400">{{ row.name }}</span>
                            </template>
                            <template #callcenter="{ value }"><span class="text-gray-600">{{ value || '—' }}</span></template>
                            <template #online="{ value }"><span :class="value ? 'text-gray-900' : 'text-gray-400'">{{ value ? 'En ligne' : 'Hors ligne' }}</span></template>
                            <template #platform="{ row }">
                                <span class="text-gray-600">{{ PLATFORMS[row.current_platform] ?? '—' }}</span>
                                <span v-if="moving(row)" class="ml-1 text-amber-700">→ {{ PLATFORMS[row.wanted_platform] }}</span>
                                <span v-if="row.switch_failed" class="ml-2 text-xs text-red-600">changement de plateforme raté le {{ formatDateTime(row.switch_failed_at) }}</span>
                            </template>
                            <template #pin="{ row }">
                                <select :value="row.pin ?? ''" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm" :disabled="pinBusy" @change="setPin(row, $event.target.value)">
                                    <option v-for="o in PIN_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                            </template>
                        </DataTable>
                    </div>
                </details>

                <!-- Réseau des agents : historique par agent, replié -->
                <details v-if="telAgents" class="rounded-lg border border-gray-100 bg-white text-sm" :open="networkOpen" @toggle="networkOpen = $event.target.open">
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                        <span class="font-semibold text-gray-900">Réseau des agents</span>
                        <span class="text-gray-600">
                            {{ network ? (flaggedCount ? `${flaggedCount} connexion${flaggedCount > 1 ? 's' : ''} à surveiller` : 'aucune connexion à surveiller') : 'qualité de la connexion de chaque agent' }}
                        </span>
                    </summary>
                    <div class="border-t border-gray-100">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-xs text-gray-500">
                            <div class="inline-flex rounded-md border border-gray-200 p-0.5">
                                <button
                                    v-for="(label, h) in { 24: '24 h', 168: '7 jours', 720: '30 jours' }"
                                    :key="h"
                                    type="button"
                                    class="rounded px-2.5 py-1"
                                    :class="networkHours === Number(h) ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                    @click="networkHours = Number(h)"
                                >
                                    {{ label }}
                                </button>
                            </div>
                            <span>Une connexion est à surveiller dans trois cas. Le serveur met plus de 600 ms à répondre en moyenne. Ou 3 messages ou plus restent sans réponse. Ou la connexion coupe 2 fois ou plus.</span>
                        </div>
                        <div class="overflow-x-auto" :class="networkLoading && 'opacity-60'">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-y border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                        <th class="px-4 py-2 font-medium">Agent</th>
                                        <th class="px-3 py-2 font-medium">Plateforme</th>
                                        <th class="px-3 py-2 font-medium" title="Dernière mesure envoyée par le téléphone de l'agent">En direct</th>
                                        <th class="px-3 py-2 text-right font-medium" title="Temps que met le serveur à répondre au téléphone de l'agent, identification comprise">Réponse du serveur (moyenne)</th>
                                        <th class="px-3 py-2 text-right font-medium">Réponse du serveur (max)</th>
                                        <th class="px-3 py-2 text-right font-medium" title="Messages de contrôle envoyés au serveur et restés sans réponse">Messages sans réponse</th>
                                        <th class="px-3 py-2 text-right font-medium" title="Nombre de fois où le téléphone de l'agent a perdu sa connexion au serveur">Coupures de connexion</th>
                                        <th class="px-3 py-2 text-right font-medium" title="Temps de réponse du site, mesuré depuis l'ordinateur de l'agent">Délai vers le site</th>
                                        <th class="px-4 py-2 font-medium">État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in networkRows" :key="`${r.agent_id}-${r.platform}`" class="border-b border-gray-50 last:border-b-0">
                                        <td class="whitespace-nowrap px-4 py-1.5">
                                            <span class="font-medium text-gray-900">{{ r.username || `#${r.agent_id}` }}</span>
                                            <span v-if="r.name" class="ml-2 text-xs text-gray-400">{{ r.name }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-1.5 text-gray-600">{{ PLATFORMS[r.platform] }}</td>
                                        <td class="whitespace-nowrap px-3 py-1.5 tabular-nums" :class="liveText(r.live).old ? 'text-gray-400' : (r.live?.net_sip_ms > 600 ? 'text-red-600' : 'text-gray-700')" :title="liveText(r.live).at ? `Mesuré le ${formatDateTime(new Date(liveText(r.live).at).toISOString())}` : ''">
                                            {{ liveText(r.live).text }}
                                        </td>
                                        <td class="px-3 py-1.5 text-right tabular-nums" :class="r.sip_avg > 600 ? 'font-medium text-red-600' : 'text-gray-900'">{{ ms(r.sip_avg) }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums text-gray-600">{{ ms(r.sip_max) }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums" :class="r.sip_lost >= 3 ? 'font-medium text-red-600' : r.sip_lost ? 'text-gray-900' : 'text-gray-300'">{{ r.sip_lost }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums" :class="r.disconnects >= 2 ? 'font-medium text-red-600' : r.disconnects ? 'text-gray-900' : 'text-gray-300'">{{ r.disconnects }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums text-gray-600">{{ ms(r.http_avg) }}</td>
                                        <td class="whitespace-nowrap px-4 py-1.5" :class="r.flagged ? 'font-medium text-red-600' : 'text-gray-500'">{{ r.flagged ? 'À surveiller' : 'Bon' }}</td>
                                    </tr>
                                    <tr v-if="!networkLoading && network && !networkRows.length">
                                        <td colspan="9" class="px-4 py-6 text-center text-gray-400">Aucune mesure sur la période</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>

                <!-- Dernières pannes : historique, replié -->
                <details v-if="healthEvents.length" class="rounded-lg border border-gray-100 bg-white text-sm">
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                        <span class="font-semibold text-gray-900">Pannes et redémarrages de notre serveur</span>
                        <span class="text-gray-600">{{ healthEvents.length }} événement{{ healthEvents.length > 1 ? 's' : '' }} · dernier le {{ formatDateTime(healthEvents[0].at) }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-gray-100 px-4 py-3">
                        <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-3">
                            <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                            <span :class="HEALTH_EVENTS[e.state]?.class ?? 'text-gray-700'">{{ HEALTH_EVENTS[e.state]?.label ?? e.state }}</span>
                            <span class="min-w-0 truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                        </li>
                    </ul>
                </details>
            </template>
        </div>
    </AppLayout>
</template>
