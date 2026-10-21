<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Button } from '@/components/ui/button'
import { Settings } from 'lucide-vue-next'

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
    if (!st.selfhosted_enabled) return { label: 'Désactivé', sub: "Ne reçoit pas de trafic (interrupteur général)", variant: 'default' }
    if (st.selfhosted_manual_off) return { label: 'Coupé', sub: 'Coupé manuellement : tout part chez Voxnode', variant: 'danger' }
    if (!tel.value.available?.selfhosted) return { label: 'En panne', sub: `Bascule automatique vers Voxnode · retour après ${st.pbx_recovery_minutes} min de bonne santé`, variant: 'danger' }
    return { label: `${st.selfhosted_percent} % du trafic`, sub: 'des nouvelles assignations', variant: 'default' }
})
const voxnodeState = computed(() => {
    if (!tel.value) return null
    if (telSettings.value.voxnode_manual_off) return { label: 'Coupé', sub: 'Coupé manuellement : tout ce qui peut part chez nous', variant: 'danger' }
    if (!tel.value.available?.voxnode) return { label: 'Indisponible', sub: '', variant: 'danger' }
    return { label: 'Disponible', sub: '', variant: 'default' }
})

// ─── Régulateur (déplacements automatiques d'agents, toutes les 30 s) ─────────
// Absent de l'API avant le déploiement de voxnode118 du 23/10 : la section reste masquée.
const regulator = computed(() => tel.value?.regulator ?? null)
const lastRun = computed(() => regulator.value?.last_run ?? null)
// Pas de décision depuis 2 min : le régulateur ne tourne plus
const regulatorStale = computed(() => {
    if (!regulator.value) return false
    return !lastRun.value?.at || Date.now() - new Date(lastRun.value.at).getTime() > 2 * 60 * 1000
})
const regulatorEvents = computed(() => (regulator.value?.events ?? []).slice(0, 10))
const simulateMode = computed(() => telSettings.value.pbx_failover_mode === 'simulate')

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
                <!-- KPI -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <StatCard
                        label="Asterisk"
                        :value="asteriskUp ? 'En service' : health?.status === 503 ? 'Injoignable' : 'Erreur'"
                        :sub="asteriskUp ? `${version} · depuis ${uptime(health.data.uptime_seconds)}` : health?.error"
                        :loading="loading"
                        :variant="asteriskUp ? 'default' : 'danger'"
                    />
                    <StatCard
                        label="Ligne OVH"
                        :value="allRegistered ? 'Enregistrée' : registrations.length ? 'Non enregistrée' : '—'"
                        :sub="registrations.map(([line, state]) => (state === 'Registered' ? line : `${line} : ${state}`)).join(' · ')"
                        :loading="loading"
                        :variant="allRegistered || !registrations.length ? 'default' : 'danger'"
                    />
                    <StatCard
                        label="Appels en cours"
                        :value="calls.length"
                        :sub="`${health?.data?.active_channels ?? 0} canal(aux) actif(s)`"
                        :loading="loading"
                    />
                    <StatCard label="Agents connectés" :value="`${registeredAgents} / ${agents.length}`" :loading="loading" />
                    <StatCard
                        label="Stockage"
                        :value="storageValue"
                        :sub="storageSub"
                        :loading="loading"
                        :variant="storageLow ? 'danger' : 'default'"
                    />
                </div>

                <!-- ─── Plateformes : notre serveur / Voxnode (voxnode118) ─── -->
                <div v-if="telephony?.configured">
                    <h2 class="mb-3 text-base font-semibold text-gray-900">Plateformes</h2>
                    <p v-if="telephony.ok === false" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        État des plateformes indisponible : {{ telephony.error }}
                    </p>
                    <div v-else class="grid gap-4 lg:grid-cols-3">
                        <StatCard label="Notre serveur" :value="selfhostedState?.label" :sub="selfhostedState?.sub" :variant="selfhostedState?.variant" :loading="loading" />
                        <StatCard label="Voxnode" :value="voxnodeState?.label" :sub="voxnodeState?.sub" :variant="voxnodeState?.variant" :loading="loading" />
                        <div class="rounded-lg border bg-white p-4" :class="telHealth?.state === 'down' ? 'border-red-100' : 'border-gray-100'">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Santé de notre serveur</p>
                            <p class="mt-1.5 text-2xl font-semibold" :class="telHealth?.state === 'down' ? 'text-red-600' : 'text-gray-900'">
                                {{ telHealth?.state === 'down' ? 'En panne' : telHealth?.state === 'up' ? 'OK' : '—' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-400">
                                Vérifiée à {{ formatTime(telHealth?.last_check_at) }}<template v-if="telHealth?.failures"> · {{ telHealth.failures }} échec(s) d'affilée</template>
                            </p>
                            <ul v-if="healthEvents.length" class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                                <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-2">
                                    <span class="tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                    <span :class="e.state === 'down' ? 'text-red-600' : 'text-gray-700'">{{ e.state === 'down' ? 'Panne' : 'Rétabli' }}</span>
                                    <span class="truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                                    <span v-if="e.simulated" class="text-gray-400">(simulé)</span>
                                </li>
                            </ul>
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
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-base font-semibold text-gray-900">Appels en cours</h2>
                        <span v-if="updatedAt" class="text-xs text-gray-400">Mis à jour à {{ updatedAt }} · toutes les 5 s</span>
                    </div>
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
