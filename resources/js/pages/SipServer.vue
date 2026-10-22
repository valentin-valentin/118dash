<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { AlertTriangle, Settings } from 'lucide-vue-next'
import { formatDay, isOpenNow, openingHoursProblem, todayKey } from '@/lib/openingHours'

/**
 * Serveur SIP (Asterisk sip.118.ae) : supervision au quotidien.
 * La configuration (service interne, IP des providers) est sur une page à part, /sip-server/settings.
 * Agents, routage et basculement des numéros sont pilotés par voxnode118.
 */
const REFRESH_MS = 5000

const data = ref(null)
const telephony = ref(null) // API voxnode118 : plateformes, santé, agents
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
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

// ─── État du serveur : neutre quand tout va bien, rouge en cas de problème ────
const health = computed(() => data.value?.health)
const asteriskUp = computed(() => !!(health.value?.ok && health.value.data?.asterisk === 'up'))
const version = computed(() => health.value?.data?.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? '—')
const registrations = computed(() => Object.entries(health.value?.data?.registrations ?? {}))
const allRegistered = computed(() => registrations.value.length > 0 && registrations.value.every(([, s]) => s === 'Registered'))

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
    const used = d[`${prefix}_used`]
    const free = d[`${prefix}_free`]
    return { total, used, free, pct: total ? used / total : null }
}
const dataDisk = computed(() => disk('disk_data'))
const rootDisk = computed(() => disk('disk_root'))
const isLow = (dk, min) => (dk.pct !== null && dk.pct > 0.9) || (dk.free != null && dk.free < min)
const storageLow = computed(() => isLow(dataDisk.value, DATA_LOW_BYTES) || isLow(rootDisk.value, ROOT_LOW_BYTES))
const storageValue = computed(() => (dataDisk.value.pct !== null ? `${percent(dataDisk.value.pct)} utilisé` : '—'))
const storageSub = computed(() => {
    if (isLow(rootDisk.value, ROOT_LOW_BYTES)) return `Disque système presque plein : ${size(rootDisk.value.free)} libres`
    if (isLow(dataDisk.value, DATA_LOW_BYTES)) return `Presque plein : ${size(dataDisk.value.free)} libres`
    return dataDisk.value.total ? `${size(dataDisk.value.used)} sur ${size(dataDisk.value.total)} · enregistrements d'appels` : ''
})

// Tailles lisibles : Ko, Mo, Go
function size(bytes) {
    if (bytes == null) return '—'
    const units = [['Go', 1e9], ['Mo', 1e6], ['Ko', 1e3]]
    const [unit, factor] = units.find(([, f]) => bytes >= f) ?? ['Ko', 1e3]
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: bytes >= 10 * factor ? 0 : 1 }).format(bytes / factor)} ${unit}`
}
const percent = (ratio) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: ratio < 0.1 ? 1 : 0 }).format(ratio * 100)} %`


// ─── Appels en cours : un appel = plusieurs canaux, regroupés par linkedid ────
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
                // Asterisk renvoie « <unknown> » quand aucun interlocuteur n'est connecté
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

// ─── Plateformes (voxnode118) ─────────────────────────────────────────────────
const tel = computed(() => (telephony.value?.ok ? telephony.value.data : null))
const telSettings = computed(() => tel.value?.settings ?? {})
const telHealth = computed(() => tel.value?.health ?? null)
const healthEvents = computed(() => [...(telHealth.value?.events ?? [])].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')).slice(0, 6))

// État de notre serveur côté routage : neutre quand c'est normal, rouge quand il est coupé ou en panne
const selfhostedState = computed(() => {
    const st = telSettings.value
    if (!tel.value) return null
    if (!st.selfhosted_enabled) return { label: 'Désactivé', note: 'Ne reçoit aucun trafic (interrupteur général).', variant: 'default' }
    if (st.selfhosted_manual_off) return { label: 'Coupé', note: 'Coupé manuellement : tout part chez Voxnode.', variant: 'danger' }
    if (!tel.value.available?.selfhosted) return { label: 'En panne', note: `Bascule automatique vers Voxnode, retour après ${st.pbx_recovery_minutes} min de bonne santé.`, variant: 'danger' }
    return { label: 'Actif', note: '', variant: 'default' }
})
const voxnodeState = computed(() => {
    if (!tel.value) return null
    if (telSettings.value.voxnode_manual_off) return { label: 'Coupé', note: 'Coupé manuellement : tout ce qui peut vient chez nous.', variant: 'danger' }
    if (!tel.value.available?.voxnode) return { label: 'Indisponible', note: '', variant: 'danger' }
    return { label: 'Disponible', note: '', variant: 'default' }
})

// ─── Régulateur (déplacements automatiques d'agents, toutes les 30 s) ─────────
// Absent de l'API avant le déploiement de voxnode118 (22/10 au soir) : la section reste masquée.
const regulator = computed(() => tel.value?.regulator ?? null)
const lastRun = computed(() => regulator.value?.last_run ?? null)
// Pas de décision depuis 2 min : le régulateur ne tourne plus
const regulatorStale = computed(() => {
    if (!regulator.value) return false
    return !lastRun.value?.at || Date.now() - new Date(lastRun.value.at).getTime() > 2 * 60 * 1000
})
const regulatorEvents = computed(() => (regulator.value?.events ?? []).slice(0, 10))
const simulateMode = computed(() => telSettings.value.pbx_failover_mode === 'simulate')

// ─── Horaires d'ouverture : réglés dans l'API téléphonie, recopiés par le serveur SIP ─
// Absents avant le déploiement de voxnode118 : rien n'est affiché.
const hoursWanted = computed(() => telSettings.value?.opening_hours ?? null)
const hoursApplied = computed(() => data.value?.['opening-hours']?.data ?? null)
const hoursProblem = computed(() => openingHoursProblem(hoursWanted.value, hoursApplied.value))
const todayHours = computed(() => (hoursWanted.value ? formatDay(hoursWanted.value[todayKey()]) : null))
const openNow = computed(() => (hoursWanted.value && data.value ? isOpenNow(hoursWanted.value) : false))

// ─── Ligne d'état du serveur ──────────────────────────────────────────────────
const statusItems = computed(() => {
    const items = [
        {
            label: 'Asterisk',
            value: asteriskUp.value ? 'En service' : health.value?.status === 503 ? 'Injoignable' : 'Erreur',
            sub: asteriskUp.value ? `${version.value} · depuis ${uptime(health.value.data.uptime_seconds)}` : health.value?.error,
            problem: !asteriskUp.value,
        },
        {
            label: 'Ligne OVH',
            value: allRegistered.value ? 'Enregistrée' : registrations.value.length ? 'Non enregistrée' : '—',
            title: registrations.value.map(([line, state]) => `${line} : ${state}`).join(' · '),
            problem: registrations.value.length > 0 && !allRegistered.value,
        },
    ]
    if (hoursWanted.value) {
        items.push({ label: "Horaires aujourd'hui", value: todayHours.value, sub: openNow.value ? 'ouvert' : 'fermé', problem: hoursProblem.value?.level === 'error' })
    }
    items.push({ label: 'Stockage', value: storageValue.value, sub: storageLow.value ? storageSub.value : '', title: storageSub.value, problem: storageLow.value })
    return items
})

// ─── Répartition : agents réellement en ligne par plateforme, et part du trafic visée ─
const onlineAgents = computed(() => (telAgents.value ?? []).filter((a) => a.online))
const onPlatform = (key) => onlineAgents.value.filter((a) => a.current_platform === key)
const moving = computed(() => onlineAgents.value.filter((a) => a.current_platform && a.current_platform !== a.wanted_platform))
const plural = (n, word) => `${word}${n > 1 ? 's' : ''}`

const keyFigures = computed(() => {
    const inCall = onlineAgents.value.filter((a) => a.in_call).length
    const figures = [
        { value: onlineAgents.value.length, label: `${plural(onlineAgents.value.length, 'agent')} en ligne` },
        { value: inCall, label: 'en appel' },
        { value: calls.value.length, label: 'appels en cours sur notre serveur' },
    ]
    if (moving.value.length) figures.push({ value: moving.value.length, label: 'en cours de bascule', alert: true })
    return figures
})

const splitBars = computed(() => {
    const ours = onPlatform('selfhosted').length
    const theirs = onPlatform('voxnode').length
    const percent = telSettings.value.selfhosted_enabled ? (telSettings.value.selfhosted_percent ?? 0) : 0
    return [
        {
            label: 'Agents en ligne',
            ours,
            theirs,
            oursPct: ours + theirs ? (ours / (ours + theirs)) * 100 : 0,
            oursText: `${ours} chez nous`,
            theirsText: `${theirs} chez Voxnode`,
            empty: 'Aucun agent en ligne',
            // En ligne sans plateforme remontée par le softphone
            note: onlineAgents.value.length - ours - theirs > 0 ? `+ ${onlineAgents.value.length - ours - theirs} sans plateforme connue` : '',
        },
        {
            label: 'Trafic visé',
            ours: percent,
            theirs: 100 - percent,
            oursPct: percent,
            oursText: `${percent} %`,
            theirsText: `${100 - percent} %`,
            empty: '',
        },
    ]
})

const platforms = computed(() => {
    const ours = onPlatform('selfhosted')
    const theirs = onPlatform('voxnode')
    const h = telHealth.value
    return [
        {
            key: 'selfhosted',
            name: 'Notre serveur',
            state: selfhostedState.value?.label,
            note: selfhostedState.value?.note,
            problem: selfhostedState.value?.variant === 'danger',
            rows: [
                ['Agents en ligne', `${ours.length} (dont ${ours.filter((a) => a.in_call).length} en appel)`],
                ['Appels en cours', calls.value.length],
                ['Santé', h ? `${h.state === 'down' ? 'En panne' : 'OK'} · vérifiée à ${formatTime(h.last_check_at)}` : '—'],
            ],
        },
        {
            key: 'voxnode',
            name: 'Voxnode',
            state: voxnodeState.value?.label,
            note: voxnodeState.value?.note,
            problem: voxnodeState.value?.variant === 'danger',
            rows: [['Agents en ligne', `${theirs.length} (dont ${theirs.filter((a) => a.in_call).length} en appel)`]],
        },
    ]
})
const platformProblem = computed(() => platforms.value.some((p) => p.problem))

const formatTime = (v) => (v ? new Date(v).toLocaleTimeString('fr-FR') : '—')

// ─── Agents : ceux de voxnode118 (plateforme voulue / réelle) si l'API est là, sinon ceux d'Asterisk ─
const PLATFORMS = { selfhosted: 'Notre serveur', voxnode: 'Voxnode' }
const PIN_LABELS = { selfhosted: 'Toujours notre serveur', voxnode: 'Toujours Voxnode' }
const telAgents = computed(() => tel.value?.agents ?? null)
const agents = computed(() => telAgents.value ?? data.value?.agents?.data?.agents ?? [])
const registeredAgents = computed(() => agents.value.filter((a) => a.registered).length)

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
        <div class="space-y-6 p-6">
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

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>

            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <template v-else>
                <!-- ─── État du serveur, en une ligne : rouge seulement en cas de problème ─ -->
                <div class="space-y-2">
                    <p v-if="hoursProblem" class="flex items-start gap-2 rounded-lg border p-3 text-sm" :class="hoursProblem.level === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-gray-100 bg-gray-50 text-gray-600'">
                        <AlertTriangle v-if="hoursProblem.level === 'error'" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ hoursProblem.text }}</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border bg-white px-4 py-3 text-sm" :class="statusItems.some((i) => i.problem) ? 'border-red-100' : 'border-gray-100'">
                        <div v-for="item in statusItems" :key="item.label" class="flex items-baseline gap-2">
                            <span class="text-gray-500">{{ item.label }}</span>
                            <span v-if="loading" class="inline-block h-4 w-20 animate-pulse rounded bg-gray-100" />
                            <span v-else class="font-medium" :class="item.problem ? 'text-red-600' : 'text-gray-900'" :title="item.title">{{ item.value }}</span>
                            <span v-if="item.sub && !loading" class="text-xs text-gray-400">{{ item.sub }}</span>
                        </div>
                        <span v-if="updatedAt" class="ml-auto text-xs text-gray-400">Mis à jour à {{ updatedAt }}</span>
                    </div>
                </div>

                <!-- ─── Répartition entre notre serveur et Voxnode (voxnode118) ─ -->
                <div>
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-base font-semibold text-gray-900">Répartition</h2>
                        <Link v-if="tel" href="/sip-server/settings" class="text-xs text-gray-500 underline hover:text-gray-800">Régler</Link>
                    </div>

                    <div v-if="!tel" class="rounded-lg border border-gray-100 bg-white p-4 text-sm text-gray-500">
                        <p v-if="telephony?.configured && telephony.ok === false" class="text-red-600">API téléphonie indisponible : {{ telephony.error }}</p>
                        <p v-else-if="!loading">
                            Répartition indisponible : l'API téléphonie de voxnode118 n'est pas encore branchée (TELEPHONY_API_TOKEN).
                        </p>
                        <p class="mt-1">
                            <span class="text-2xl font-semibold tabular-nums text-gray-900">{{ registeredAgents }}</span>
                            agent{{ registeredAgents > 1 ? 's' : '' }} connecté{{ registeredAgents > 1 ? 's' : '' }} à notre serveur
                        </p>
                    </div>

                    <div v-else class="rounded-lg border bg-white" :class="platformProblem ? 'border-red-100' : 'border-gray-100'">
                        <!-- Chiffres clés -->
                        <div class="flex flex-wrap items-baseline gap-x-10 gap-y-2 border-b border-gray-50 px-5 py-4">
                            <div v-for="f in keyFigures" :key="f.label" class="flex items-baseline gap-2">
                                <span class="text-3xl font-semibold tabular-nums" :class="f.alert ? 'text-amber-700' : 'text-gray-900'">{{ f.value }}</span>
                                <span class="text-sm text-gray-500">{{ f.label }}</span>
                            </div>
                        </div>

                        <!-- Barres : agents réellement en ligne, et part du trafic visée -->
                        <div class="space-y-3 px-5 py-4">
                            <div v-for="bar in splitBars" :key="bar.label" class="grid items-center gap-x-4 gap-y-1 sm:grid-cols-[9rem_1fr]">
                                <span class="text-sm text-gray-500">{{ bar.label }}</span>
                                <div>
                                    <div class="flex h-7 gap-0.5 overflow-hidden rounded text-xs font-medium">
                                        <div v-if="bar.ours" class="flex min-w-fit items-center rounded-l bg-gray-900 px-2 text-white" :class="!bar.theirs && 'rounded-r'" :style="{ width: `${bar.oursPct}%` }">{{ bar.oursText }}</div>
                                        <div v-if="bar.theirs" class="flex min-w-fit items-center justify-end rounded-r bg-gray-200 px-2 text-gray-700" :class="!bar.ours && 'rounded-l'" :style="{ width: `${100 - bar.oursPct}%` }">{{ bar.theirsText }}</div>
                                        <div v-if="!bar.ours && !bar.theirs" class="flex w-full items-center rounded bg-gray-50 px-2 text-gray-400">{{ bar.empty }}</div>
                                    </div>
                                    <p v-if="bar.note" class="mt-1 text-xs text-gray-500">{{ bar.note }}</p>
                                </div>
                            </div>
                            <div class="flex gap-5 pl-0 text-xs text-gray-500 sm:pl-[10rem]">
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-900" />Notre serveur</span>
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-200" />Voxnode</span>
                            </div>
                        </div>

                        <!-- Détail par plateforme -->
                        <div class="grid border-t border-gray-50 sm:grid-cols-2">
                            <div v-for="p in platforms" :key="p.key" class="px-5 py-4 sm:[&:nth-child(2)]:border-l sm:[&:nth-child(2)]:border-gray-50">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-sm font-semibold text-gray-900">{{ p.name }}</span>
                                    <span class="text-sm font-medium" :class="p.problem ? 'text-red-600' : 'text-gray-700'">{{ p.state }}</span>
                                </div>
                                <p v-if="p.note" class="mt-0.5 text-xs" :class="p.problem ? 'text-red-600' : 'text-gray-500'">{{ p.note }}</p>
                                <dl class="mt-3 space-y-1 text-sm">
                                    <div v-for="row in p.rows" :key="row[0]" class="flex justify-between">
                                        <dt class="text-gray-500">{{ row[0] }}</dt>
                                        <dd class="tabular-nums text-gray-900">{{ row[1] }}</dd>
                                    </div>
                                </dl>
                                <details v-if="p.key === 'selfhosted' && healthEvents.length" class="mt-3 text-xs">
                                    <summary class="cursor-pointer text-gray-500 hover:text-gray-700">Dernières pannes ({{ healthEvents.length }})</summary>
                                    <ul class="mt-2 space-y-1">
                                        <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-2">
                                            <span class="tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                            <span :class="e.state === 'down' ? 'text-red-600' : 'text-gray-700'">{{ e.state === 'down' ? 'Panne' : 'Rétabli' }}</span>
                                            <span class="truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                                            <span v-if="e.simulated" class="text-gray-400">(simulé)</span>
                                        </li>
                                    </ul>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── Régulateur (voxnode118) ───────────────────────────── -->
                <div v-if="regulator">
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Régulateur</h2>
                    <p v-if="regulatorStale" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        Le régulateur ne tourne plus : dernière décision {{ lastRun?.at ? `à ${formatTime(lastRun.at)}` : 'inconnue' }}, alors qu'il décide toutes les 30 s.
                    </p>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="rounded-lg border border-gray-100 bg-white p-4 lg:col-span-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Dernière décision</p>
                            <p class="mt-1.5 text-sm text-gray-900">{{ lastRun?.reason || '—' }}</p>
                            <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                                <div class="flex justify-between"><dt class="text-gray-500">Agents automatiques en ligne</dt><dd class="tabular-nums text-gray-900">{{ lastRun?.active_agents ?? '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Chez nous / objectif</dt><dd class="tabular-nums text-gray-900">{{ lastRun?.on_selfhosted ?? '—' }} / {{ lastRun?.target ?? '—' }}</dd></div>
                                <template v-if="lastRun?.queue">
                                    <div class="flex justify-between"><dt class="text-gray-500">Clients en file</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.waiting }}<template v-if="lastRun.queue.waiting_long"> · {{ lastRun.queue.waiting_long }} depuis longtemps</template></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Attente la plus longue</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.max_wait }} s</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">En introduction / attente forcée</dt><dd class="tabular-nums text-gray-900">{{ lastRun.queue.incoming }}</dd></div>
                                </template>
                                <div class="flex justify-between"><dt class="text-gray-500">À</dt><dd class="tabular-nums text-gray-500">{{ formatTime(lastRun?.at) }} · toutes les 30 s</dd></div>
                            </dl>
                            <p v-if="simulateMode" class="mt-2 text-xs text-amber-700">Mode simulation : les bascules ne sont pas appliquées.</p>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-white lg:col-span-2">
                            <p class="px-4 pt-4 text-xs font-medium uppercase tracking-wide text-gray-400">Derniers déplacements d'agents</p>
                            <ul v-if="regulatorEvents.length" class="mt-2">
                                <li v-for="(e, i) in regulatorEvents" :key="i" class="flex gap-3 border-t border-gray-50 px-4 py-2 text-xs">
                                    <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="text-gray-700">{{ e.type === 'reset' ? 'Réinitialisation' : e.reason }}</span>
                                        <span v-if="e.to_selfhosted?.length" class="ml-2 text-gray-500">→ notre serveur : <span class="font-mono text-gray-800">{{ e.to_selfhosted.join(', ') }}</span></span>
                                        <span v-if="e.to_voxnode?.length" class="ml-2 text-gray-500">→ Voxnode : <span class="font-mono text-gray-800">{{ e.to_voxnode.join(', ') }}</span></span>
                                        <span v-if="e.type === 'reset' && e.reason" class="ml-2 text-gray-500">{{ e.reason }}</span>
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="px-4 py-6 text-center text-sm text-gray-400">Aucun déplacement récent</p>
                        </div>
                    </div>
                </div>

                <!-- ─── Appels en cours ───────────────────────────────────── -->
                <div>
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Appels en cours</h2>
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
                </div>

                <!-- ─── Agents ────────────────────────────────────────────── -->
                <div>
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Agents</h2>
                    <div v-if="telAgents" class="rounded-lg border border-gray-100 bg-white">
                        <DataTable :columns="telAgentColumns" :rows="telAgents" :loading="loading" empty-message="Aucun agent">
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
                            :rows="agents"
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
                        Plateforme : où l'agent est réellement → où il doit aller (en orange pendant une bascule). Le mode et la répartition du trafic se règlent dans Configuration.
                    </p>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
