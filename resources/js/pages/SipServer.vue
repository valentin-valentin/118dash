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

async function load() {
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
const statusItems = computed(() => {
    if (!data.value) return []
    const st = telSettings.value
    let ours = { value: 'En service', problem: false }
    if (!serverUp.value) ours = { value: 'Ne répond pas', problem: true }
    else if (tel.value && !st.selfhosted_enabled) ours = { value: 'Désactivé', problem: false }
    else if (tel.value && st.selfhosted_manual_off) ours = { value: 'Coupé à la main', problem: true }
    else if (tel.value && !tel.value.available?.selfhosted) ours = { value: 'En panne', problem: true }

    const items = []
    if (hoursNow.value) items.push({ label: 'Horaires', value: hoursNow.value.text, problem: false })
    items.push({ label: 'Notre serveur', ...ours })
    if (tel.value) {
        items.push({
            label: 'Voxnode',
            value: st.voxnode_manual_off ? 'Coupé à la main' : !tel.value.available?.voxnode ? 'Indisponible' : 'Disponible',
            problem: !!st.voxnode_manual_off || !tel.value.available?.voxnode,
        })
    }
    items.push({
        label: 'Ligne OVH',
        value: !registrations.value.length ? '—' : lineDown.value ? 'Non enregistrée' : 'Enregistrée',
        problem: lineDown.value,
        title: registrations.value.map(([line, state]) => `${line} : ${state}`).join(' · '),
    })
    return items
})

// Les données sont relues toutes les 5 s : au-delà de 20 s, elles sont figées
const freshness = computed(() => {
    if (!loadedAt.value) return null
    const s = Math.max(0, Math.round((now.value - loadedAt.value) / 1000))
    return s > 20 ? { text: `Données figées depuis ${s} s`, problem: true } : { text: `Actualisé il y a ${s} s`, problem: false }
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

// Un agent sans plateforme quelques secondes est en train de basculer : signalé seulement au-delà de 30 s
const NO_PLATFORM_DELAY = 30 * 1000
const noPlatformSince = new Map()
const lostAgents = ref([])
watch(telAgents, (list) => {
    const now = Date.now()
    const current = new Set()
    for (const a of list ?? []) {
        if (!a.online || a.current_platform) continue
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
    if (tel.value && st.selfhosted_enabled && !st.selfhosted_manual_off && !tel.value.available?.selfhosted) {
        list.push(`Notre serveur est en panne : tout part chez Voxnode, retour après ${st.pbx_recovery_minutes} min de bonne santé.`)
    }
    if (lineDown.value) list.push("La ligne OVH n'est pas enregistrée : les appels sortants du softphone ne passent pas.")
    if (diskLow('disk_data', DATA_LOW_BYTES) || diskLow('disk_root', ROOT_LOW_BYTES)) list.push('Le disque de notre serveur est presque plein (détail dans Configuration).')
    if (hoursProblem.value?.level === 'error') list.push(hoursProblem.value.text)
    if (regulatorStale.value) list.push(`Le régulateur ne tourne plus : dernière décision ${lastRun.value?.at ? `à ${formatTime(lastRun.value.at)}` : 'inconnue'}.`)
    const failed = (telAgents.value ?? []).filter((a) => a.online && a.switch_failed)
    if (failed.length) list.push(`Bascule ratée : ${failed.map((a) => a.username).join(', ')}.`)
    if (lostAgents.value.length) list.push(`En ligne mais sur aucune plateforme depuis plus de 30 s : ${lostAgents.value.map((a) => a.username).join(', ')}.`)
    return list
})

// ─── 4a. Appels en cours sur notre serveur : un appel = plusieurs canaux, regroupés par linkedid ─
const agentNames = computed(() => new Set([...(telAgents.value ?? []), ...localAgents.value].map((a) => a.username)))

// Canal d'un agent : PJSIP/dedi014-0000002a
function channelAgent(channel) {
    const name = channel?.match(/^PJSIP\/(.+)-[0-9a-f]+$/)?.[1]
    return name && agentNames.value.has(name) ? name : null
}

function callStatus(first, agent) {
    if (first.context === 'internal-service' || first.context === 'transfer') return { key: 'transfer', label: 'Transfert service interne' }
    if (first.context === 'panel-dial' || first.context === 'panel-out') return { key: 'panel', label: 'Softphone du panel' }
    if (agent) return { key: 'talking', label: 'En conversation' }
    if (first.context === 'from-providers') return { key: 'waiting', label: first.app === 'Queue' ? 'En file' : 'Accueil / attente forcée' }
    return { key: 'other', label: first.context }
}

const calls = computed(() => {
    const channels = data.value?.calls?.data?.calls ?? []
    const groups = {}
    for (const c of channels) (groups[c.linkedid || c.uniqueid] ??= []).push(c)
    return Object.values(groups)
        .map((chs) => {
            const first = chs.find((c) => c.uniqueid === c.linkedid) ?? chs[0]
            const agent = chs.map((c) => channelAgent(c.channel)).find(Boolean) ?? null
            return {
                id: first.linkedid || first.uniqueid,
                caller: first.caller,
                // « s » = extension interne d'Asterisk, pas un numéro
                exten: first.exten === 's' ? null : first.exten,
                status: callStatus(first, agent),
                agent,
                seconds: Math.max(...chs.map((c) => toSeconds(c.duration))),
            }
        })
        .sort((a, b) => b.seconds - a.seconds)
})
const waitingCalls = computed(() => calls.value.filter((c) => c.status.key === 'waiting'))

const callColumns = [
    { key: 'caller', label: 'Appelant' },
    { key: 'exten', label: 'Numéro appelé' },
    { key: 'status', label: 'Statut' },
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
const freeAgents = computed(() => onlineAgents.value.filter((a) => !a.in_call).sort(byName))
// Durée de l'appel en cours, connue pour les agents en conversation sur notre serveur
const callSeconds = computed(() => Object.fromEntries(calls.value.filter((c) => c.agent).map((c) => [c.agent, c.seconds])))
const moving = (a) => !!a.current_platform && !!a.wanted_platform && a.current_platform !== a.wanted_platform
const countOn = (list, key) => list.filter((a) => a.current_platform === key).length

// ─── 3. En ce moment : appels, puis agents ────────────────────────────────────
// Répartition d'une liste d'agents entre les deux plateformes (null sans l'API voxnode118)
const split = (list) => (tel.value ? { ours: countOn(list, 'selfhosted'), theirs: countOn(list, 'voxnode') } : null)

const callFigures = computed(() => {
    const maxWait = waitingCalls.value.length ? Math.max(...waitingCalls.value.map((c) => c.seconds)) : 0
    return [
        { label: 'En conversation', value: busyAgents.value.length, split: split(busyAgents.value) },
        { label: 'En attente', value: waitingCalls.value.length, sub: 'accueil + file' },
        { label: 'Attente la plus longue', value: waitingCalls.value.length ? wait(maxWait) : '—' },
        { label: 'Transferts en cours', value: calls.value.filter((c) => c.status.key === 'transfer').length, sub: 'vers le service interne' },
    ]
})

// Part des nouveaux numéros envoyée chez nous : un pourcentage court, la raison en dessous
const shareFigure = computed(() => {
    const st = telSettings.value
    if (!tel.value) return { value: '—', sub: 'API voxnode118 non branchée' }
    if (!st.selfhosted_enabled) return { value: '0 %', sub: 'notre serveur désactivé' }
    if (st.selfhosted_manual_off) return { value: '0 %', sub: 'notre serveur coupé à la main', problem: true }
    if (!tel.value.available?.selfhosted) return { value: '0 %', sub: 'notre serveur en panne', problem: true }
    if (st.voxnode_manual_off) return { value: '100 %', sub: 'Voxnode coupé à la main', problem: true }
    return { value: `${st.selfhosted_percent} %`, sub: 'des nouveaux numéros' }
})

const agentFigures = computed(() => [
    { label: 'En ligne', value: onlineAgents.value.length, split: split(onlineAgents.value) },
    { label: 'Libres', value: freeAgents.value.length, split: split(freeAgents.value) },
    { label: 'Trafic chez nous', ...shareFigure.value },
])

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
        pinMessage.value = { ok: true, text: `${agent.username} : ${PIN_OPTIONS.find((o) => o.value === value)?.label}` }
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

// ─── 7. Dernières pannes de notre serveur (détectées par voxnode118) ─────────
const healthEvents = computed(() => [...(tel.value?.health?.events ?? [])].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')).slice(0, 10))

// ─── 5. Régulateur ────────────────────────────────────────────────────────────
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

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>
            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <template v-else>
                <!-- État : chaque élément dit son état en toutes lettres -->
                <div class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-lg border bg-white px-4 py-2.5 text-sm" :class="statusItems.some((i) => i.problem) ? 'border-red-200' : 'border-gray-100'">
                    <div v-if="loading" class="h-5 w-96 animate-pulse rounded bg-gray-100" />
                    <span v-for="item in statusItems" :key="item.label" class="flex items-center gap-2" :title="item.title">
                        <span class="text-gray-500">{{ item.label }}</span>
                        <span class="font-medium" :class="item.problem ? 'text-red-600' : 'text-gray-900'">{{ item.value }}</span>
                    </span>
                    <span v-if="freshness" class="ml-auto text-xs tabular-nums" :class="freshness.problem ? 'font-medium text-red-600' : 'text-gray-400'">{{ freshness.text }}</span>
                </div>

                <!-- Alertes : seulement en cas de problème -->
                <div v-if="alerts.length || hoursProblem?.level === 'pending' || telephony?.ok === false" class="space-y-2">
                    <p v-for="text in alerts" :key="text" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        {{ text }}
                    </p>
                    <p v-if="telephony?.ok === false" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        API téléphonie de voxnode118 indisponible ({{ telephony.error }}) : agents et répartition limités à notre serveur.
                    </p>
                    <p v-if="hoursProblem?.level === 'pending'" class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ hoursProblem.text }}</p>
                </div>

                <!-- En ce moment : deux groupes, appels et agents -->
                <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,4fr)_minmax(0,3fr)]">
                    <div v-for="group in [
                        { title: 'Appels', note: 'attente et transferts : notre serveur uniquement', figures: callFigures },
                        { title: 'Agents', note: '', figures: agentFigures },
                    ]" :key="group.title" class="rounded-lg border border-gray-100 bg-white">
                        <div class="flex items-baseline justify-between gap-3 px-4 pt-3">
                            <span class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ group.title }}</span>
                            <span v-if="group.note" class="truncate text-xs text-gray-400">{{ group.note }}</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-flow-col sm:auto-cols-fr sm:grid-cols-none">
                            <div v-for="f in group.figures" :key="f.label" class="px-4 pb-3 pt-1.5">
                                <p class="text-xs text-gray-500">{{ f.label }}</p>
                                <div v-if="loading" class="mt-1 h-7 w-16 animate-pulse rounded bg-gray-100" />
                                <p v-else class="truncate text-2xl font-semibold tabular-nums" :class="f.problem ? 'text-red-600' : 'text-gray-900'">{{ f.value }}</p>
                                <!-- Répartition nous / Voxnode, avec les mêmes repères que la liste des agents -->
                                <p v-if="f.split" class="flex items-center gap-3 whitespace-nowrap text-xs tabular-nums text-gray-500">
                                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-gray-900" />nous {{ f.split.ours }}</span>
                                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-gray-200" />Voxnode {{ f.split.theirs }}</span>
                                </p>
                                <p v-else-if="f.sub" class="truncate text-xs" :class="f.problem ? 'text-red-600' : 'text-gray-400'">{{ f.sub }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Appels et agents, côte à côte -->
                <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <section class="min-w-0">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">
                            Appels en cours sur notre serveur <span class="ml-1 font-normal tabular-nums text-gray-400">{{ calls.length }}</span>
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
                                <template #status="{ value }">
                                    <span class="text-sm" :class="value.key === 'waiting' ? 'font-medium text-amber-700' : 'text-gray-700'">{{ value.label }}</span>
                                </template>
                                <template #agent="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #seconds="{ value }"><span class="text-sm tabular-nums text-gray-900">{{ wait(value) }}</span></template>
                            </DataTable>
                        </div>
                    </section>

                    <section class="min-w-0">
                        <h2 class="mb-2 text-sm font-semibold text-gray-900">
                            Agents en ligne <span class="ml-1 font-normal tabular-nums text-gray-400">{{ onlineAgents.length }}</span>
                        </h2>
                        <div class="rounded-lg border border-gray-100 bg-white text-sm">
                            <div v-if="loading" class="h-24 animate-pulse" />
                            <p v-else-if="!onlineAgents.length" class="px-4 py-6 text-center text-gray-400">Aucun agent en ligne</p>
                            <template v-else>
                                <!-- En appel : une ligne par agent, avec sa plateforme -->
                                <div v-if="busyAgents.length" class="border-b border-gray-100 px-4 py-3">
                                    <p class="mb-1.5 text-xs text-gray-500">En appel · {{ busyAgents.length }}</p>
                                    <ul class="space-y-1">
                                        <li v-for="a in busyAgents" :key="a.username" class="flex items-center justify-between gap-2">
                                            <span class="truncate font-medium text-gray-900" :title="a.name">{{ a.username }}</span>
                                            <span class="flex shrink-0 items-center gap-1.5 text-xs text-gray-500">
                                                <ArrowRightLeft v-if="moving(a)" class="h-3 w-3 text-amber-600" />
                                                {{ PLATFORMS[a.current_platform] ?? '—' }}
                                                <span v-if="callSeconds[a.username] != null" class="w-14 text-right tabular-nums text-gray-900">{{ wait(callSeconds[a.username]) }}</span>
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                                <!-- Libres : étiquettes compactes, foncées chez nous, claires chez Voxnode -->
                                <div v-if="freeAgents.length" class="px-4 py-3">
                                    <p class="mb-1.5 text-xs text-gray-500">Libres · {{ freeAgents.length }}</p>
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="a in freeAgents"
                                            :key="a.username"
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs"
                                            :class="a.current_platform === 'selfhosted' ? 'bg-gray-900 text-white' : 'bg-gray-200 text-gray-800'"
                                            :title="[a.name, PLATFORMS[a.current_platform] ?? 'plateforme inconnue', moving(a) ? `bascule vers ${PLATFORMS[a.wanted_platform]}` : ''].filter(Boolean).join(' · ')"
                                        >
                                            {{ a.username }}
                                            <ArrowRightLeft v-if="moving(a)" class="h-3 w-3 text-amber-500" />
                                        </span>
                                    </div>
                                </div>
                                <p v-if="tel" class="flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 px-4 py-2 text-xs text-gray-400">
                                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-900" />Notre serveur</span>
                                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-200" />Voxnode</span>
                                    <span class="flex items-center gap-1"><ArrowRightLeft class="h-3 w-3 text-amber-600" />en bascule</span>
                                </p>
                            </template>
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
                    <div class="grid gap-6 border-t border-gray-100 px-4 py-3 lg:grid-cols-[18rem_1fr]">
                        <dl class="space-y-1">
                            <div class="flex justify-between"><dt class="text-gray-500">Agents automatiques en ligne</dt><dd class="tabular-nums">{{ lastRun?.active_agents ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Chez nous / objectif</dt><dd class="tabular-nums">{{ lastRun?.on_selfhosted ?? '—' }} / {{ lastRun?.target ?? '—' }}</dd></div>
                            <template v-if="lastRun?.queue">
                                <div class="flex justify-between"><dt class="text-gray-500">Clients en file</dt><dd class="tabular-nums">{{ lastRun.queue.waiting }}<template v-if="lastRun.queue.waiting_long"> · {{ lastRun.queue.waiting_long }} longs</template></dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Attente la plus longue</dt><dd class="tabular-nums">{{ lastRun.queue.max_wait }} s</dd></div>
                            </template>
                            <p v-if="telSettings.pbx_failover_mode === 'simulate'" class="pt-1 text-xs text-amber-700">Mode simulation : les bascules ne sont pas appliquées.</p>
                        </dl>
                        <ul v-if="regulatorEvents.length" class="space-y-1">
                            <li v-for="(e, i) in regulatorEvents" :key="i" class="flex gap-3">
                                <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                                <span class="min-w-0 flex-1 text-gray-700">
                                    {{ e.type === 'reset' ? 'Réinitialisation' : e.reason }}
                                    <span v-if="e.to_selfhosted?.length" class="ml-1 text-gray-500">→ nous : {{ e.to_selfhosted.join(', ') }}</span>
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
                        <span class="text-gray-600">{{ pinnedCount ? `${pinnedCount} forcé${pinnedCount > 1 ? 's' : ''} sur une plateforme` : 'tous automatiques' }}</span>
                    </summary>
                    <div class="border-t border-gray-100">
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 text-xs text-gray-500">
                            <input v-model="modeSearch" type="search" placeholder="Agent, nom ou centre…" class="h-8 w-56 rounded-md border border-gray-200 px-2 text-sm text-gray-900" />
                            Automatique : l'agent suit la répartition et les bascules. Forcé : il reste sur la même plateforme, même en cas de panne.
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
                                <span v-if="row.switch_failed" class="ml-2 text-xs text-red-600">bascule ratée le {{ formatDateTime(row.switch_failed_at) }}</span>
                            </template>
                            <template #pin="{ row }">
                                <select :value="row.pin ?? ''" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm" :disabled="pinBusy" @change="setPin(row, $event.target.value)">
                                    <option v-for="o in PIN_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                            </template>
                        </DataTable>
                    </div>
                </details>

                <!-- Dernières pannes : historique, replié -->
                <details v-if="healthEvents.length" class="rounded-lg border border-gray-100 bg-white text-sm">
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                        <span class="font-semibold text-gray-900">Dernières pannes de notre serveur</span>
                        <span class="text-gray-600">{{ healthEvents.length }} événement{{ healthEvents.length > 1 ? 's' : '' }} · dernier le {{ formatDateTime(healthEvents[0].at) }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-gray-100 px-4 py-3">
                        <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-3">
                            <span class="shrink-0 tabular-nums text-gray-400">{{ formatDateTime(e.at) }}</span>
                            <span :class="e.state === 'down' ? 'text-red-600' : 'text-gray-700'">{{ e.state === 'down' ? 'Panne' : 'Rétabli' }}</span>
                            <span class="min-w-0 truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                            <span v-if="e.simulated" class="text-gray-400">(simulé)</span>
                        </li>
                    </ul>
                </details>
            </template>
        </div>
    </AppLayout>
</template>
