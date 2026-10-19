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
const loading = ref(true)
const error = ref(null)

async function load() {
    try {
        const res = await fetch('/data/sip/status', { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
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

const gb = (bytes) => (bytes || bytes === 0 ? `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(bytes / 1e9)} Go` : '—')

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

// ─── Agents ───────────────────────────────────────────────────────────────────
const agents = computed(() => data.value?.agents?.data?.agents ?? [])
const registeredAgents = computed(() => agents.value.filter((a) => a.registered).length)

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
                        label="Disque enregistrements"
                        :value="gb(health?.data?.disk_data_free)"
                        :sub="`Système : ${gb(health?.data?.disk_root_free)} libres`"
                        :loading="loading"
                    />
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
                    <div class="rounded-lg border border-gray-100 bg-white">
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
                    <p class="mt-2 text-xs text-gray-500">Agents, routage et basculement des numéros se gèrent dans voxnode118.</p>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
