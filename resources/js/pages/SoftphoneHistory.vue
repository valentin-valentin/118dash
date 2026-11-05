<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { ChevronRight, ExternalLink, LoaderCircle, RefreshCw } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'

/**
 * Historique des appels du softphone du panel, pour déboguer les appels de test.
 * Une fiche par appel (serveur SIP) : enregistrement complet dès la composition, journal Asterisk de l'appel,
 * journal du navigateur (traces SIP, WebRTC, statistiques), reliés par l'identifiant X-Panel-Call-Id.
 */
const PERIODS = [
    { days: 1, label: '24 h' },
    { days: 7, label: '7 jours' },
    { days: 30, label: '30 jours' },
    { days: 90, label: '90 jours' },
]
const days = ref(7)
const calls = ref([])
const loading = ref(false)
const error = ref(null)
const openId = ref(null)

// Journaux affichés : { [`${id}:${kind}`]: { loading, text, error } }
const logs = ref({})

let requestId = 0
async function load() {
    const id = ++requestId
    loading.value = true
    error.value = null
    try {
        const res = await fetch(`/data/softphone/calls?days=${days.value}`, { headers: { Accept: 'application/json' } })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(data.message ?? `Erreur HTTP ${res.status}`)
        if (id === requestId) calls.value = data.calls
    } catch (e) {
        if (id === requestId) error.value = e.message
    } finally {
        if (id === requestId) loading.value = false
    }
}
watch(days, load, { immediate: true })

// Fichiers encore sur le serveur : on rafraîchit jusqu'à ce qu'ils soient envoyés
const hasPending = computed(() => calls.value.some((c) => c.pending?.length))
let refreshTimer = null
watch(hasPending, (pending) => {
    clearInterval(refreshTimer)
    if (pending) refreshTimer = setInterval(load, 15000)
})
onUnmounted(() => clearInterval(refreshTimer))

function toggle(id) {
    openId.value = openId.value === id ? null : id
}

async function showLog(call, kind) {
    const key = `${call.id}:${kind}`
    if (logs.value[key]?.text) {
        logs.value = { ...logs.value, [key]: { ...logs.value[key], hidden: !logs.value[key].hidden } }
        return
    }
    logs.value = { ...logs.value, [key]: { loading: true } }
    try {
        const res = await fetch(`/data/softphone/calls/${encodeURIComponent(call.id)}/log/${kind}`, { headers: { Accept: 'application/json' } })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(data.message ?? `Erreur HTTP ${res.status}`)
        logs.value = { ...logs.value, [key]: { text: data.text } }
    } catch (e) {
        logs.value = { ...logs.value, [key]: { error: e.message } }
    }
}

const STATUS = {
    ANSWER: { label: 'Répondu', tone: 'bg-green-50 text-green-700' },
    NOANSWER: { label: 'Pas de réponse', tone: 'bg-gray-100 text-gray-600' },
    BUSY: { label: 'Occupé', tone: 'bg-amber-50 text-amber-700' },
    CONGESTION: { label: 'Échec opérateur', tone: 'bg-red-50 text-red-700' },
    CHANUNAVAIL: { label: 'Ligne indisponible', tone: 'bg-red-50 text-red-700' },
    CANCEL: { label: 'Annulé', tone: 'bg-gray-100 text-gray-600' },
    NOT_REACHED: { label: "N'a pas atteint le serveur", tone: 'bg-red-50 text-red-700' },
}

function status(call) {
    // dial_status vide : test d'écho (600), ou appel refusé avant numérotation (plus de 5 appels simultanés)
    if (call.dial_status === '') {
        if (String(call.number ?? '').trim() === '600') return { label: 'Test audio (écho)', tone: 'bg-blue-50 text-blue-700' }
        return { label: "Refusé : trop d'appels en même temps (5 au maximum)", tone: 'bg-red-50 text-red-700' }
    }
    return STATUS[call.dial_status] ?? { label: call.dial_status ?? '—', tone: 'bg-gray-100 text-gray-600' }
}

// Causes de raccroché Q.850 les plus courantes
const Q850 = {
    1: 'numéro non attribué',
    16: 'fin normale',
    17: 'occupé',
    18: "pas de réponse de l'appelé",
    19: 'pas de décroché',
    21: 'appel refusé',
    27: 'destination hors service',
    28: 'numéro mal formé',
    31: 'échec normal, non précisé',
    34: 'aucun circuit disponible',
    38: 'réseau hors service',
    41: 'échec temporaire',
    42: 'équipement saturé',
    127: 'erreur entre opérateurs, non précisée',
}

function hangupCause(code) {
    if (code === null || code === undefined || code === '') return '—'
    const label = Q850[Number(code)]
    return label ? `${code} (${label})` : String(code)
}

const PENDING = { recording: 'enregistrement', server_log: 'journal serveur', browser_log: 'journal navigateur' }

function pendingLabel(call) {
    return (call.pending ?? []).map((p) => PENDING[p] ?? p).join(', ')
}

function formatDate(epoch) {
    if (!epoch) return '—'
    return new Date(epoch * 1000).toLocaleString('fr-FR', {
        timeZone: 'Europe/Paris',
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
}

function formatSeconds(s) {
    if (s === null || s === undefined) return '—'
    const n = Math.round(Number(s))
    return n < 60 ? `${n} s` : `${Math.floor(n / 60)} min ${String(n % 60).padStart(2, '0')} s`
}

const LOGS = [
    { kind: 'server_log', label: 'Journal serveur' },
    { kind: 'browser_log', label: 'Journal navigateur' },
]
</script>

<template>
    <Head title="Historique du softphone" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Historique du softphone' }]" />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="p in PERIODS"
                        :key="p.days"
                        type="button"
                        class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                        :class="days === p.days ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="days = p.days"
                    >
                        {{ p.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="flex h-8 items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 text-xs text-gray-600 hover:bg-gray-100 disabled:opacity-50"
                    :disabled="loading"
                    @click="load"
                >
                    <RefreshCw class="size-3.5" :class="loading ? 'animate-spin' : ''" /> Actualiser
                </button>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs text-gray-500">
                            <tr>
                                <th class="w-8 px-3 py-2" />
                                <th class="px-3 py-2 font-medium whitespace-nowrap">Date</th>
                                <th class="px-3 py-2 font-medium">Numéro</th>
                                <th class="px-3 py-2 font-medium">Résultat</th>
                                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">Durée totale</th>
                                <th class="px-3 py-2 text-right font-medium">Conversation</th>
                                <th class="px-3 py-2 font-medium">Fichiers</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading && !calls.length">
                                <td colspan="7" class="px-3 py-8 text-center text-gray-400"><LoaderCircle class="mx-auto size-5 animate-spin" /></td>
                            </tr>
                            <tr v-else-if="!calls.length">
                                <td colspan="7" class="px-3 py-8 text-center text-gray-400">Aucun appel sur la période</td>
                            </tr>
                            <template v-for="call in calls" :key="call.id">
                                <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50" :class="openId === call.id ? 'bg-gray-50' : ''" @click="toggle(call.id)">
                                    <td class="px-3 py-2 text-gray-400">
                                        <ChevronRight class="size-4 transition-transform" :class="openId === call.id ? 'rotate-90' : ''" />
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-700 tabular-nums">{{ formatDate(call.started_at) }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-gray-800">{{ call.number || '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium whitespace-nowrap" :class="status(call).tone">{{ status(call).label }}</span>
                                        <span v-if="call.operator_cause" class="ml-2 text-xs text-gray-500">{{ call.operator_cause }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap text-gray-700 tabular-nums">{{ formatSeconds(call.duration) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap text-gray-700 tabular-nums">{{ call.answered_seconds ? formatSeconds(call.answered_seconds) : '—' }}</td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap text-gray-500">
                                        <span v-if="call.pending?.length" class="text-amber-700">En cours d'envoi : {{ pendingLabel(call) }}</span>
                                        <span v-else>{{ [call.recording_url && 'audio', call.server_log_url && 'serveur', call.browser_log_url && 'navigateur'].filter(Boolean).join(' · ') || '—' }}</span>
                                    </td>
                                </tr>

                                <tr v-if="openId === call.id" class="bg-gray-50">
                                    <td />
                                    <td colspan="6" class="space-y-4 px-3 pt-1 pb-4">
                                        <!-- Enregistrement -->
                                        <div>
                                            <div class="mb-1 text-xs font-medium text-gray-500">Enregistrement (dès la composition)</div>
                                            <audio v-if="call.recording_url" :src="call.recording_url" controls preload="none" class="h-9 w-full max-w-xl" />
                                            <p v-else-if="call.pending?.includes('recording')" class="text-xs text-amber-700">En cours d'envoi</p>
                                            <p v-else class="text-xs text-gray-500">Pas d'audio</p>
                                        </div>

                                        <!-- Détail de la fiche -->
                                        <dl class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                            <div><dt class="text-gray-500">Fin</dt><dd class="text-gray-800 tabular-nums">{{ formatDate(call.ended_at) }}</dd></div>
                                            <div><dt class="text-gray-500">Sonnerie + conversation</dt><dd class="text-gray-800 tabular-nums">{{ formatSeconds(call.dial_seconds) }}</dd></div>
                                            <div><dt class="text-gray-500">Cause du raccroché</dt><dd class="text-gray-800">{{ hangupCause(call.hangup_cause) }}</dd></div>
                                            <div><dt class="text-gray-500">Raccroché par</dt><dd class="break-all text-gray-800">{{ call.hangup_source || '—' }}</dd></div>
                                            <div><dt class="text-gray-500">Réponse de l'opérateur</dt><dd class="text-gray-800">{{ call.operator_cause || '—' }}</dd></div>
                                            <div><dt class="text-gray-500">Identifiant</dt><dd class="font-mono break-all text-gray-800">{{ call.id }}</dd></div>
                                            <div><dt class="text-gray-500">Uniqueid Asterisk</dt><dd class="font-mono break-all text-gray-800">{{ call.uniqueid || '—' }}</dd></div>
                                            <div><dt class="text-gray-500">Call-ID SIP</dt><dd class="font-mono break-all text-gray-800">{{ call.callid || '—' }}</dd></div>
                                        </dl>

                                        <!-- Journaux -->
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <template v-for="l in LOGS" :key="l.kind">
                                                    <template v-if="call[`${l.kind}_url`]">
                                                        <button
                                                            type="button"
                                                            class="h-7 rounded-md border px-2.5 text-xs"
                                                            :class="logs[`${call.id}:${l.kind}`]?.text && !logs[`${call.id}:${l.kind}`]?.hidden ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-100'"
                                                            @click.stop="showLog(call, l.kind)"
                                                        >
                                                            {{ l.label }}
                                                        </button>
                                                        <a :href="call[`${l.kind}_url`]" target="_blank" rel="noopener" class="mr-2 text-gray-400 hover:text-gray-700" :title="`Ouvrir le ${l.label.toLowerCase()}`">
                                                            <ExternalLink class="size-3.5" />
                                                        </a>
                                                    </template>
                                                    <span v-else class="mr-2 text-xs text-gray-400">
                                                        {{ l.label }} : {{ call.pending?.includes(l.kind) ? "en cours d'envoi" : '—' }}
                                                    </span>
                                                </template>
                                            </div>
                                            <template v-for="l in LOGS" :key="`log-${l.kind}`">
                                                <template v-if="logs[`${call.id}:${l.kind}`] && !logs[`${call.id}:${l.kind}`].hidden">
                                                    <div class="text-xs font-medium text-gray-500">{{ l.label }}</div>
                                                    <p v-if="logs[`${call.id}:${l.kind}`].loading" class="text-xs text-gray-400"><LoaderCircle class="inline size-3.5 animate-spin" /></p>
                                                    <p v-else-if="logs[`${call.id}:${l.kind}`].error" class="text-xs text-red-600">{{ logs[`${call.id}:${l.kind}`].error }}</p>
                                                    <pre v-else class="max-h-[32rem] overflow-auto rounded-md border border-gray-200 bg-white p-3 text-[0.6875rem] leading-relaxed whitespace-pre text-gray-700">{{ logs[`${call.id}:${l.kind}`].text }}</pre>
                                                </template>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
