<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { AlertTriangle, Settings } from 'lucide-vue-next'
import { hoursHeadline, openingHoursProblem } from '@/lib/openingHours'

/**
 * Serveur SIP : supervision au quotidien.
 * « Notre serveur » = le serveur Asterisk sip.118.ae ; « Voxnode » = l'autre plateforme.
 * La répartition, les agents et le régulateur viennent de l'API de voxnode118 ;
 * l'état technique et les appels viennent de l'API de contrôle de notre serveur.
 * Les réglages sont sur une page à part, /sip-server/settings.
 */
const REFRESH_MS = 5000

const data = ref(null)
const telephony = ref(null) // API voxnode118 : répartition, agents, régulateur
const loading = ref(true)
const error = ref(null)

async function load() {
    try {
        const [res, tel] = await Promise.all([
            fetch('/data/sip/status', { headers: { Accept: 'application/json' } }),
            fetch('/data/telephony', { headers: { Accept: 'application/json' } }),
        ])
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
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
const updatedAt = computed(() => (data.value?.fetched_at ? new Date(data.value.fetched_at).toLocaleTimeString('fr-FR') : null))
const formatTime = (v) => (v ? new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—')
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')
const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`

// ─── API voxnode118 ───────────────────────────────────────────────────────────
const tel = computed(() => (telephony.value?.ok ? telephony.value.data : null))
const telSettings = computed(() => tel.value?.settings ?? {})
const telHealth = computed(() => tel.value?.health ?? null)
const healthEvents = computed(() => [...(telHealth.value?.events ?? [])].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')).slice(0, 6))

// ─── État technique de notre serveur ──────────────────────────────────────────
const health = computed(() => data.value?.health)
const serverUp = computed(() => !!(health.value?.ok && health.value.data?.asterisk === 'up'))
const version = computed(() => health.value?.data?.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? null)
const registrations = computed(() => Object.entries(health.value?.data?.registrations ?? {}))
const lineDown = computed(() => registrations.value.length > 0 && registrations.value.some(([, s]) => s !== 'Registered'))

function uptime(seconds) {
    if (!seconds && seconds !== 0) return '—'
    const d = Math.floor(seconds / 86400)
    const h = Math.floor((seconds % 86400) / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    return d ? `${d} j ${h} h` : h ? `${h} h ${m} min` : `${m} min`
}

// Stockage des enregistrements (/data) et disque système : alerte au-delà de 90 % ou sous ces seuils
const DATA_LOW_BYTES = 20e9
const ROOT_LOW_BYTES = 5e9
const disk = (prefix) => {
    const d = health.value?.data ?? {}
    const total = d[`${prefix}_total`]
    return { total, used: d[`${prefix}_used`], free: d[`${prefix}_free`], pct: total ? d[`${prefix}_used`] / total : null }
}
const dataDisk = computed(() => disk('disk_data'))
const rootDisk = computed(() => disk('disk_root'))
const isLow = (dk, min) => (dk.pct !== null && dk.pct > 0.9) || (dk.free != null && dk.free < min)
const storageProblem = computed(() => {
    if (isLow(rootDisk.value, ROOT_LOW_BYTES)) return `Disque système presque plein : ${size(rootDisk.value.free)} libres`
    if (isLow(dataDisk.value, DATA_LOW_BYTES)) return `Stockage des enregistrements presque plein : ${size(dataDisk.value.free)} libres`
    return null
})

function size(bytes) {
    if (bytes == null) return '—'
    const units = [['Go', 1e9], ['Mo', 1e6], ['Ko', 1e3]]
    const [unit, factor] = units.find(([, f]) => bytes >= f) ?? ['Ko', 1e3]
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: bytes >= 10 * factor ? 0 : 1 }).format(bytes / factor)} ${unit}`
}
const percent = (ratio) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: ratio < 0.1 ? 1 : 0 }).format(ratio * 100)} %`

// Quatre cases de même forme : libellé, valeur, détail
const technical = computed(() => {
    const h = health.value?.data ?? {}
    const lines = registrations.value
    return [
        {
            label: 'Serveur',
            value: serverUp.value ? 'En service' : health.value?.status === 503 ? 'Injoignable' : 'Erreur',
            sub: serverUp.value ? `Asterisk ${version.value ?? '—'} · depuis ${uptime(h.uptime_seconds)}` : health.value?.error,
            problem: !serverUp.value,
        },
        {
            label: 'Ligne OVH',
            value: !lines.length ? '—' : lineDown.value ? 'Non enregistrée' : 'Enregistrée',
            sub: lines.map(([line, state]) => (state === 'Registered' ? line : `${line} : ${state}`)).join(' · '),
            problem: lineDown.value,
        },
        {
            label: 'Santé (routage)',
            value: !telHealth.value ? '—' : telHealth.value.state === 'down' ? 'En panne' : 'OK',
            sub: telHealth.value
                ? `Vérifiée à ${new Date(telHealth.value.last_check_at).toLocaleTimeString('fr-FR')}${telHealth.value.failures ? ` · ${telHealth.value.failures} échec(s) d'affilée` : ''}`
                : '',
            problem: telHealth.value?.state === 'down',
        },
        {
            label: 'Stockage',
            value: dataDisk.value.pct !== null ? `${percent(dataDisk.value.pct)} utilisé` : '—',
            sub: storageProblem.value ?? (dataDisk.value.total ? `${size(dataDisk.value.used)} sur ${size(dataDisk.value.total)}` : ''),
            problem: !!storageProblem.value,
        },
    ]
})

// ─── Horaires d'ouverture (absents de l'API avant le déploiement de voxnode118) ─
const hoursWanted = computed(() => telSettings.value?.opening_hours ?? null)
const hoursProblem = computed(() => openingHoursProblem(hoursWanted.value, data.value?.['opening-hours']?.data ?? null))
const hoursNow = computed(() => (data.value ? hoursHeadline(hoursWanted.value) : null))

// ─── Répartition : mêmes lignes pour les deux plateformes ─────────────────────
const PLATFORMS = { selfhosted: 'Notre serveur', voxnode: 'Voxnode' }
const telAgents = computed(() => tel.value?.agents ?? null)
const onlineAgents = computed(() => (telAgents.value ?? []).filter((a) => a.online))
const onPlatform = (key) => onlineAgents.value.filter((a) => a.current_platform === key)

// Un agent sans plateforme pendant quelques secondes est en train de basculer :
// on ne le signale qu'au-delà de 30 s, pour que la page ne clignote pas.
const NO_PLATFORM_DELAY = 30 * 1000
const noPlatformSince = new Map()
const lostAgents = ref(0)
watch(onlineAgents, (list) => {
    const now = Date.now()
    const current = new Set()
    for (const a of list) {
        if (a.current_platform) continue
        current.add(a.id)
        if (!noPlatformSince.has(a.id)) noPlatformSince.set(a.id, now)
    }
    for (const id of noPlatformSince.keys()) if (!current.has(id)) noPlatformSince.delete(id)
    lostAgents.value = [...noPlatformSince.values()].filter((since) => now - since >= NO_PLATFORM_DELAY).length
})

function platformState(key) {
    const st = telSettings.value
    if (key === 'selfhosted') {
        if (!st.selfhosted_enabled) return { label: 'Désactivé', problem: false }
        if (st.selfhosted_manual_off) return { label: 'Coupé à la main', problem: true }
        if (!tel.value.available?.selfhosted) return { label: 'En panne', problem: true }
        return { label: 'Actif', problem: false }
    }
    if (st.voxnode_manual_off) return { label: 'Coupé à la main', problem: true }
    if (!tel.value.available?.voxnode) return { label: 'Indisponible', problem: true }
    return { label: 'Actif', problem: false }
}

const split = computed(() => {
    if (!tel.value) return null
    const share = telSettings.value.selfhosted_enabled && !telSettings.value.selfhosted_manual_off ? (telSettings.value.selfhosted_percent ?? 0) : 0
    const columns = ['selfhosted', 'voxnode'].map((key) => {
        const agents = onPlatform(key)
        return {
            key,
            name: PLATFORMS[key],
            state: platformState(key),
            share: key === 'selfhosted' ? share : 100 - share,
            online: agents.length,
            inCall: agents.filter((a) => a.in_call).length,
            // Agents en route vers cette plateforme
            incoming: onlineAgents.value.filter((a) => a.wanted_platform === key && a.current_platform && a.current_platform !== key).length,
        }
    })
    // Total = tous les agents en ligne, même ceux dont la plateforme n'est pas encore connue
    const placed = columns[0].online + columns[1].online
    return { columns, total: onlineAgents.value.length, oursPct: placed ? (columns[0].online / placed) * 100 : 0 }
})

// Explication quand une plateforme n'est pas dans son état normal
const splitNote = computed(() => {
    const st = telSettings.value
    if (!tel.value) return null
    if (!st.selfhosted_enabled) return 'Notre serveur est désactivé : il ne reçoit aucun trafic.'
    if (st.selfhosted_manual_off) return 'Notre serveur est coupé à la main : tout part chez Voxnode.'
    if (!tel.value.available?.selfhosted) return `Notre serveur est en panne : bascule automatique vers Voxnode, retour après ${st.pbx_recovery_minutes} min de bonne santé.`
    if (st.voxnode_manual_off) return 'Voxnode est coupé à la main : tout ce qui peut vient chez nous.'
    return null
})

// ─── Alertes : seulement quand il y a un problème ─────────────────────────────
const alerts = computed(() => {
    const list = []
    if (data.value && !serverUp.value) list.push('Notre serveur ne répond pas.')
    if (lineDown.value) list.push("La ligne OVH n'est pas enregistrée : les appels sortants du softphone ne passent pas.")
    if (storageProblem.value) list.push(`${storageProblem.value}.`)
    if (hoursProblem.value?.level === 'error') list.push(hoursProblem.value.text)
    return list
})

// ─── Régulateur (déplacements automatiques d'agents, toutes les 30 s) ─────────
const regulator = computed(() => tel.value?.regulator ?? null)
const lastRun = computed(() => regulator.value?.last_run ?? null)
// Pas de décision depuis 2 min : le régulateur ne tourne plus
const regulatorStale = computed(() => {
    if (!regulator.value) return false
    return !lastRun.value?.at || Date.now() - new Date(lastRun.value.at).getTime() > 2 * 60 * 1000
})
const regulatorEvents = computed(() => (regulator.value?.events ?? []).slice(0, 10))
const simulateMode = computed(() => telSettings.value.pbx_failover_mode === 'simulate')

// ─── Appels en cours sur notre serveur : un appel = plusieurs canaux, regroupés par linkedid ─
const CONTEXTS = {
    'from-providers': 'Entrant',
    'internal-service': 'Service interne',
    'panel-dial': 'Softphone panel',
    'panel-out': 'Softphone panel',
    agents: 'Test agent',
    transfer: 'Transfert',
}

const calls = computed(() => {
    const channels = data.value?.calls?.data?.calls ?? []
    const groups = {}
    for (const c of channels) (groups[c.linkedid || c.uniqueid] ??= []).push(c)
    return Object.values(groups)
        .map((chs) => {
            const first = chs.find((c) => c.uniqueid === c.linkedid) ?? chs[0]
            const longest = chs.reduce((a, b) => ((b.duration ?? '') > (a.duration ?? '') ? b : a))
            return {
                id: first.linkedid || first.uniqueid,
                type: CONTEXTS[first.context] ?? first.context,
                caller: first.caller,
                exten: first.exten,
                // « <unknown> » quand aucun interlocuteur n'est connecté
                connected: chs.map((c) => c.connected).find((v) => v && v !== '<unknown>' && v !== first.caller) ?? null,
                state: first.state,
                app: chs.map((c) => c.app).filter(Boolean).join(' → '),
                duration: longest.duration,
                channels: chs.length,
            }
        })
        .sort((a, b) => (b.duration ?? '').localeCompare(a.duration ?? ''))
})

const callColumns = [
    { key: 'type', label: 'Type' },
    { key: 'caller', label: 'Appelant' },
    { key: 'exten', label: 'Numéro appelé' },
    { key: 'connected', label: 'Connecté à' },
    { key: 'state', label: 'État' },
    { key: 'app', label: 'Application' },
    { key: 'channels', label: 'Canaux' },
    { key: 'duration', label: 'Durée' },
]

// ─── Agents : ceux de voxnode118 si l'API est là, sinon ceux connectés à notre serveur ─
const PIN_LABELS = { selfhosted: 'Toujours notre serveur', voxnode: 'Toujours Voxnode' }
const localAgents = computed(() => data.value?.agents?.data?.agents ?? [])
const localRegistered = computed(() => localAgents.value.filter((a) => a.registered).length)

// Par défaut, seulement les agents en ligne (en appel d'abord) ; les hors ligne sur demande
const showOffline = ref(false)
const offlineCount = computed(() => (telAgents.value ?? []).filter((a) => !a.online).length)
const agentRows = computed(() => {
    const rows = (telAgents.value ?? []).filter((a) => showOffline.value || a.online)
    const rank = (a) => (a.online ? (a.in_call ? 0 : 1) : 2)
    return [...rows].sort((a, b) => rank(a) - rank(b) || (a.username ?? '').localeCompare(b.username ?? ''))
})

const telAgentColumns = [
    { key: 'name', label: 'Agent' },
    { key: 'callcenter', label: 'Centre' },
    { key: 'online', label: 'En ligne' },
    { key: 'pin', label: 'Mode' },
    { key: 'platform', label: 'Plateforme' },
    { key: 'in_call', label: 'En appel' },
    { key: 'switch_failed', label: 'Bascule' },
]

const agentColumns = [
    { key: 'username', label: 'Agent' },
    { key: 'registered', label: 'Connexion' },
    { key: 'in_call', label: 'En appel' },
    { key: 'paused', label: 'Pause' },
    { key: 'calls_taken', label: 'Appels pris' },
    { key: 'last_call', label: 'Dernier appel' },
]
</script>

<template>
    <Head title="Serveur SIP" />

    <AppLayout>
        <div class="space-y-8 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Serveur SIP' }]">
                <template #actions>
                    <div class="flex items-center gap-4">
                        <span v-if="hoursNow" class="flex items-center gap-2 text-sm" :class="hoursNow.open ? 'text-gray-700' : 'text-gray-500'">
                            <span class="h-2 w-2 rounded-full" :class="hoursNow.open ? 'bg-gray-900' : 'bg-gray-300'" />
                            {{ hoursNow.text }}
                        </span>
                        <span v-if="updatedAt" class="hidden text-xs text-gray-400 md:inline">Mis à jour à {{ updatedAt }}</span>
                        <Button variant="ghost" size="sm" class="text-gray-500" as-child>
                            <Link href="/sip-server/settings">
                                <Settings class="mr-2 h-4 w-4" />
                                Configuration
                            </Link>
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>

            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <template v-else>
                <!-- ─── Alertes ───────────────────────────────────────────── -->
                <div v-if="alerts.length || hoursProblem?.level === 'pending'" class="space-y-2">
                    <p v-for="text in alerts" :key="text" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        {{ text }}
                    </p>
                    <p v-if="hoursProblem?.level === 'pending'" class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm text-gray-600">{{ hoursProblem.text }}</p>
                </div>

                <!-- ─── Répartition entre les deux plateformes ────────────── -->
                <section>
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Répartition</h2>

                    <div v-if="!split" class="rounded-lg border border-gray-100 bg-white p-5 text-sm text-gray-500">
                        <p v-if="telephony?.configured && telephony.ok === false" class="text-red-600">API téléphonie indisponible : {{ telephony.error }}</p>
                        <p v-else-if="!loading">Répartition indisponible : l'API téléphonie de voxnode118 n'est pas branchée (TELEPHONY_API_TOKEN).</p>
                        <p class="mt-2">{{ plural(localRegistered, 'agent') }} connecté{{ localRegistered > 1 ? 's' : '' }} à notre serveur.</p>
                    </div>

                    <div v-else class="rounded-lg border border-gray-100 bg-white">
                        <!-- Total + barre des agents en ligne -->
                        <div class="space-y-3 border-b border-gray-100 px-5 py-4">
                            <p class="text-sm text-gray-500">
                                <span class="text-2xl font-semibold tabular-nums text-gray-900">{{ split.total }}</span>
                                agent{{ split.total > 1 ? 's' : '' }} en ligne
                                <span v-if="lostAgents" class="text-amber-700"> · {{ lostAgents }} sans plateforme depuis plus de 30 s</span>
                            </p>
                            <div class="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-gray-100">
                                <div v-if="split.columns[0].online" class="rounded-full bg-gray-900 transition-all" :style="{ width: `${split.oursPct}%` }" />
                                <div v-if="split.columns[1].online" class="flex-1 rounded-full bg-gray-300" />
                            </div>
                        </div>

                        <!-- Comparaison : exactement les mêmes lignes pour les deux plateformes -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100">
                                        <th class="w-1/3 px-5 py-3" />
                                        <th v-for="c in split.columns" :key="c.key" class="px-5 py-3 text-left">
                                            <span class="flex items-center gap-2 font-semibold text-gray-900">
                                                <span class="h-2.5 w-2.5 rounded-full" :class="c.key === 'selfhosted' ? 'bg-gray-900' : 'bg-gray-300'" />
                                                {{ c.name }}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="tabular-nums">
                                    <tr class="border-b border-gray-50">
                                        <td class="px-5 py-2.5 text-gray-500">État</td>
                                        <td v-for="c in split.columns" :key="c.key" class="px-5 py-2.5" :class="c.state.problem ? 'font-medium text-red-600' : 'text-gray-900'">{{ c.state.label }}</td>
                                    </tr>
                                    <tr class="border-b border-gray-50">
                                        <td class="px-5 py-2.5 text-gray-500">Agents en ligne</td>
                                        <td v-for="c in split.columns" :key="c.key" class="px-5 py-2.5 text-lg font-semibold text-gray-900">{{ c.online }}</td>
                                    </tr>
                                    <tr class="border-b border-gray-50">
                                        <td class="px-5 py-2.5 text-gray-500">Agents en appel</td>
                                        <td v-for="c in split.columns" :key="c.key" class="px-5 py-2.5 text-gray-900">{{ c.inCall }}</td>
                                    </tr>
                                    <tr class="border-b border-gray-50">
                                        <td class="px-5 py-2.5 text-gray-500">Agents en route vers la plateforme</td>
                                        <td v-for="c in split.columns" :key="c.key" class="px-5 py-2.5" :class="c.incoming ? 'font-medium text-amber-700' : 'text-gray-400'">{{ c.incoming || '—' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-5 py-2.5 text-gray-500">Part des nouveaux numéros</td>
                                        <td v-for="c in split.columns" :key="c.key" class="px-5 py-2.5 text-gray-900">{{ c.share }} %</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="splitNote" class="border-t border-gray-100 px-5 py-3 text-sm text-gray-600">{{ splitNote }}</p>
                    </div>
                </section>

                <!-- ─── État technique de notre serveur ───────────────────── -->
                <section>
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Notre serveur</h2>
                    <div class="grid grid-cols-2 divide-gray-100 rounded-lg border border-gray-100 bg-white lg:grid-cols-4 lg:divide-x">
                        <div v-for="t in technical" :key="t.label" class="px-5 py-4">
                            <p class="text-xs text-gray-500">{{ t.label }}</p>
                            <div v-if="loading" class="mt-1 h-6 w-24 animate-pulse rounded bg-gray-100" />
                            <template v-else>
                                <p class="mt-1 text-base font-semibold" :class="t.problem ? 'text-red-600' : 'text-gray-900'">{{ t.value }}</p>
                                <p v-if="t.sub" class="mt-0.5 truncate text-xs text-gray-400" :title="t.sub">{{ t.sub }}</p>
                            </template>
                        </div>
                    </div>
                    <details v-if="healthEvents.length" class="mt-2 text-xs">
                        <summary class="cursor-pointer text-gray-500 hover:text-gray-700">Dernières pannes ({{ healthEvents.length }})</summary>
                        <ul class="mt-2 space-y-1 rounded-lg border border-gray-100 bg-white px-4 py-3">
                            <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-3">
                                <span class="tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                <span :class="e.state === 'down' ? 'text-red-600' : 'text-gray-700'">{{ e.state === 'down' ? 'Panne' : 'Rétabli' }}</span>
                                <span class="truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                                <span v-if="e.simulated" class="text-gray-400">(simulé)</span>
                            </li>
                        </ul>
                    </details>
                </section>

                <!-- ─── Appels en cours sur notre serveur ─────────────────── -->
                <section>
                    <h2 class="mb-3 text-base font-semibold text-gray-900">
                        Appels en cours sur notre serveur
                        <span class="ml-1 font-normal tabular-nums text-gray-400">{{ calls.length }}</span>
                    </h2>
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <DataTable
                            :columns="callColumns"
                            :rows="calls"
                            :loading="loading"
                            :empty-message="data?.calls?.ok === false ? `Liste indisponible : ${data.calls.error}` : 'Aucun appel en cours'"
                        >
                            <template #caller="{ value }"><span class="font-mono text-sm text-gray-700">{{ value || '—' }}</span></template>
                            <template #exten="{ value }"><span class="font-mono text-sm text-gray-700">{{ value || '—' }}</span></template>
                            <template #connected="{ value }"><span class="font-mono text-sm text-gray-600">{{ value || '—' }}</span></template>
                            <template #app="{ value }"><span class="text-sm text-gray-600">{{ value || '—' }}</span></template>
                            <template #duration="{ value }"><span class="font-mono text-sm tabular-nums text-gray-900">{{ value }}</span></template>
                        </DataTable>
                    </div>
                </section>

                <!-- ─── Régulateur (absent avant le déploiement de voxnode118) ─ -->
                <section v-if="regulator">
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Régulateur</h2>
                    <p v-if="regulatorStale" class="mb-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        Le régulateur ne tourne plus : dernière décision {{ lastRun?.at ? `à ${formatTime(lastRun.at)}` : 'inconnue' }}, alors qu'il décide toutes les 30 s.
                    </p>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="rounded-lg border border-gray-100 bg-white p-5">
                            <p class="text-xs text-gray-500">Dernière décision · {{ formatTime(lastRun?.at) }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900">{{ lastRun?.reason || '—' }}</p>
                            <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm">
                                <div class="flex justify-between"><dt class="text-gray-500">Agents automatiques en ligne</dt><dd class="tabular-nums text-gray-900">{{ lastRun?.active_agents ?? '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Chez nous / objectif</dt><dd class="tabular-nums text-gray-900">{{ lastRun?.on_selfhosted ?? '—' }} / {{ lastRun?.target ?? '—' }}</dd></div>
                                <template v-if="lastRun?.queue">
                                    <div class="flex justify-between"><dt class="text-gray-500">Clients en file</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.waiting }}<template v-if="lastRun.queue.waiting_long"> · {{ lastRun.queue.waiting_long }} depuis longtemps</template></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Attente la plus longue</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.max_wait }} s</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">En introduction / attente forcée</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.incoming }}</dd></div>
                                </template>
                            </dl>
                            <p v-if="simulateMode" class="mt-3 text-xs text-amber-700">Mode simulation : les bascules ne sont pas appliquées.</p>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-white lg:col-span-2">
                            <p class="px-5 pt-4 text-xs text-gray-500">Derniers déplacements d'agents</p>
                            <ul v-if="regulatorEvents.length" class="mt-2">
                                <li v-for="(e, i) in regulatorEvents" :key="i" class="flex gap-3 border-t border-gray-50 px-5 py-2 text-sm">
                                    <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="text-gray-700">{{ e.type === 'reset' ? 'Réinitialisation' : e.reason }}</span>
                                        <span v-if="e.to_selfhosted?.length" class="ml-2 text-gray-500">→ notre serveur : <span class="font-mono text-gray-800">{{ e.to_selfhosted.join(', ') }}</span></span>
                                        <span v-if="e.to_voxnode?.length" class="ml-2 text-gray-500">→ Voxnode : <span class="font-mono text-gray-800">{{ e.to_voxnode.join(', ') }}</span></span>
                                        <span v-if="e.type === 'reset' && e.reason" class="ml-2 text-gray-500">{{ e.reason }}</span>
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="px-5 py-6 text-center text-sm text-gray-400">Aucun déplacement récent</p>
                        </div>
                    </div>
                </section>

                <!-- ─── Agents ────────────────────────────────────────────── -->
                <section>
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-base font-semibold text-gray-900">
                            Agents {{ telAgents && !showOffline ? 'en ligne' : '' }}
                            <span class="ml-1 font-normal tabular-nums text-gray-400">{{ telAgents ? agentRows.length : localAgents.length }}</span>
                        </h2>
                        <button v-if="telAgents && offlineCount" type="button" class="text-xs text-gray-500 underline hover:text-gray-800" @click="showOffline = !showOffline">
                            {{ showOffline ? 'Masquer les agents hors ligne' : `Afficher aussi les ${offlineCount} hors ligne` }}
                        </button>
                    </div>
                    <div v-if="telAgents" class="rounded-lg border border-gray-100 bg-white">
                        <DataTable :columns="telAgentColumns" :rows="agentRows" :loading="loading" empty-message="Aucun agent en ligne">
                            <template #name="{ row }">
                                <span class="font-medium text-gray-900">{{ row.name || row.username }}</span>
                                <span v-if="row.name && row.username" class="ml-2 font-mono text-xs text-gray-400">{{ row.username }}</span>
                            </template>
                            <template #callcenter="{ value }"><span class="text-sm text-gray-600">{{ value || '—' }}</span></template>
                            <template #online="{ value }">
                                <span class="flex items-center gap-1.5 text-xs" :class="value ? 'text-gray-700' : 'text-gray-400'">
                                    <span class="h-2 w-2 rounded-full" :class="value ? 'bg-green-500' : 'bg-gray-300'" />
                                    {{ value ? 'En ligne' : 'Hors ligne' }}
                                </span>
                            </template>
                            <template #pin="{ value }"><span class="text-xs text-gray-600">{{ PIN_LABELS[value] ?? 'Automatique' }}</span></template>
                            <template #platform="{ row }">
                                <span class="text-xs" :class="row.current_platform && row.current_platform !== row.wanted_platform ? 'font-medium text-amber-700' : 'text-gray-700'">
                                    {{ PLATFORMS[row.current_platform] ?? '—' }}
                                    <template v-if="row.current_platform !== row.wanted_platform"> → {{ PLATFORMS[row.wanted_platform] ?? row.wanted_platform }}</template>
                                </span>
                                <span v-if="!row.registered" class="ml-1 text-xs text-gray-400">(non enregistré)</span>
                            </template>
                            <template #in_call="{ value }"><span class="text-xs" :class="value ? 'font-medium text-gray-900' : 'text-gray-400'">{{ value ? 'En appel' : '—' }}</span></template>
                            <template #switch_failed="{ row }">
                                <span v-if="row.switch_failed" class="text-xs text-red-600">Échec · {{ formatDateTime(row.switch_failed_at) }}</span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </template>
                        </DataTable>
                    </div>
                    <div v-else class="rounded-lg border border-gray-100 bg-white">
                        <DataTable
                            :columns="agentColumns"
                            :rows="localAgents"
                            :loading="loading"
                            :empty-message="data?.agents?.ok === false ? `Liste indisponible : ${data.agents.error}` : 'Aucun agent'"
                        >
                            <template #username="{ value }"><span class="font-medium text-gray-900">{{ value }}</span></template>
                            <template #registered="{ value }">
                                <span class="flex items-center gap-1.5 text-xs" :class="value ? 'text-gray-700' : 'text-gray-400'">
                                    <span class="h-2 w-2 rounded-full" :class="value ? 'bg-green-500' : 'bg-gray-300'" />
                                    {{ value ? 'Connecté' : 'Déconnecté' }}
                                </span>
                            </template>
                            <template #in_call="{ value }"><span class="text-xs" :class="value ? 'font-medium text-gray-900' : 'text-gray-400'">{{ value ? 'En appel' : '—' }}</span></template>
                            <template #paused="{ row }">
                                <span class="text-xs" :class="row.paused ? 'text-amber-700' : 'text-gray-400'">
                                    {{ row.paused ? `En pause${row.paused_reason ? ` · ${row.paused_reason}` : ''}` : '—' }}
                                </span>
                            </template>
                            <template #calls_taken="{ value }"><span class="tabular-nums text-gray-700">{{ value }}</span></template>
                            <template #last_call="{ value }"><span class="text-sm text-gray-600">{{ formatDateTime(value) }}</span></template>
                        </DataTable>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        Plateforme : où l'agent est réellement → où il doit aller (en orange pendant une bascule). Le mode et la répartition se règlent dans Configuration.
                    </p>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
