<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { ArrowDown, ArrowUp, GripVertical, ListPlus, Pause, Play, Trash2, Upload, X } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { xsrfToken } from '@/lib/csrf'

/**
 * Serveur SIP (Asterisk sip.118.ae).
 * Géré ici, à la main : IP des providers, sons et playlist du service interne.
 * Affiché en lecture : état, appels, agents (pilotés par voxnode118 : routage, basculement, agents).
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
    stopSound()
})

// ─── État du serveur ──────────────────────────────────────────────────────────
const health = computed(() => data.value?.health)
const asteriskUp = computed(() => health.value?.ok && health.value.data?.asterisk === 'up')
const version = computed(() => health.value?.data?.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? '—')

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

// ─── Agents ───────────────────────────────────────────────────────────────────
const agents = computed(() => data.value?.agents?.data?.agents ?? [])
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

// ─── Sons du service interne ──────────────────────────────────────────────────
const sounds = computed(() => data.value?.sounds?.data?.sounds ?? [])
const playlist = computed(() => data.value?.sounds?.data?.playlist ?? [])
const playlistPosition = (name) => playlist.value.indexOf(name) + 1

const audio = typeof Audio !== 'undefined' ? new Audio() : null
const playing = ref(null)
if (audio) audio.addEventListener('ended', () => (playing.value = null))
function toggleSound(name) {
    if (!audio) return
    if (playing.value === name) return stopSound()
    audio.src = `/data/sip/sounds/${encodeURIComponent(name)}`
    audio.play().then(() => (playing.value = name)).catch(() => (playing.value = null))
}
function stopSound() {
    audio?.pause()
    playing.value = null
}

const trustedIps = computed(() => data.value?.['trusted-ips']?.data?.ips ?? [])

// ─── Écritures (via le panel, qui garde le token) ─────────────────────────────
const notice = ref(null) // { type: 'ok' | 'error', text }
let noticeTimer = null
function notify(type, text) {
    notice.value = { type, text }
    clearTimeout(noticeTimer)
    noticeTimer = setTimeout(() => (notice.value = null), 6000)
}

async function send(method, url, body) {
    const isForm = body instanceof FormData
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(json.message || json.errors?.[Object.keys(json.errors)[0]]?.[0] || `Erreur HTTP ${res.status}`)
    return json
}

const busy = ref(null)
async function run(key, action, success) {
    busy.value = key
    try {
        await action()
        notify('ok', success)
        await load()
    } catch (e) {
        notify('error', e.message)
    } finally {
        busy.value = null
    }
}

// IP des providers
const VOXNODE_IP = '13.36.113.246'
const newIp = ref('')
const newIpIsVoxnode = computed(() => newIp.value.trim().split('/')[0] === VOXNODE_IP)

function addIp() {
    const ip = newIp.value.trim()
    if (!ip || newIpIsVoxnode.value) return
    run('ip-add', () => send('POST', '/data/sip/trusted-ips', { ip }), `${ip} ajoutée aux providers autorisés`).then(() => {
        if (notice.value?.type === 'ok') newIp.value = ''
    })
}

function removeIp(ip) {
    if (!confirm(`Retirer ${ip} des IP autorisées ?\n\nLes appels de ce provider seront refusés immédiatement.`)) return
    run(`ip-${ip}`, () => send('DELETE', `/data/sip/trusted-ips/${encodeURIComponent(ip)}`), `${ip} retirée`)
}

// Sons
const soundFile = ref(null)
const soundName = ref('')
const fileInput = ref(null)

function onFile(e) {
    soundFile.value = e.target.files[0] ?? null
    // Nom proposé à partir du fichier (le serveur fait pareil s'il est vide)
    if (soundFile.value && !soundName.value) {
        soundName.value = soundFile.value.name.replace(/\.[^.]+$/, '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '')
    }
}

function uploadSound() {
    if (!soundFile.value) return
    const form = new FormData()
    form.append('file', soundFile.value)
    if (soundName.value) form.append('name', soundName.value)
    run('upload', () => send('POST', '/data/sip/sounds', form), 'Son ajouté (converti en wav 8 kHz mono)').then(() => {
        if (notice.value?.type === 'ok') {
            soundFile.value = null
            soundName.value = ''
            if (fileInput.value) fileInput.value.value = ''
        }
    })
}

function deleteSound(name) {
    if (!confirm(`Supprimer le son « ${name} » ?`)) return
    run(`sound-${name}`, () => send('DELETE', `/data/sip/sounds/${encodeURIComponent(name)}`), `Son « ${name} » supprimé`)
}

// Playlist : brouillon modifiable, enregistré d'un coup (PUT de l'ordre complet)
// Tant qu'on ne l'a pas modifiée ici, elle suit la playlist du serveur (rafraîchie toutes les 5 s)
const draft = ref([])
const edited = ref(false)
const draftDirty = computed(() => edited.value && JSON.stringify(draft.value) !== JSON.stringify(playlist.value))
watch(playlist, (list) => {
    if (!edited.value) draft.value = [...list]
}, { immediate: true })

function editDraft(list) {
    draft.value = list
    edited.value = true
}
function addToPlaylist(name) {
    editDraft([...draft.value, name])
}
function removeFromPlaylist(index) {
    editDraft(draft.value.filter((_, i) => i !== index))
}
function moveInPlaylist(from, to) {
    if (to < 0 || to >= draft.value.length) return
    const list = [...draft.value]
    const [item] = list.splice(from, 1)
    list.splice(to, 0, item)
    editDraft(list)
}

// Glisser-déposer
const dragIndex = ref(null)
function onDrop(index) {
    if (dragIndex.value !== null) moveInPlaylist(dragIndex.value, index)
    dragIndex.value = null
}

function savePlaylist() {
    const sounds = [...draft.value]
    run('playlist', () => send('PUT', '/data/sip/playlist', { sounds }), sounds.length ? 'Playlist enregistrée' : 'Playlist vidée : musique d\'attente par défaut').then(() => {
        if (notice.value?.type === 'ok') edited.value = false
    })
}
function resetPlaylist() {
    edited.value = false
    draft.value = [...playlist.value]
}

const updatedAt = computed(() =>
    data.value?.fetched_at ? new Date(data.value.fetched_at).toLocaleTimeString('fr-FR') : null,
)
</script>

<template>
    <Head title="Serveur SIP" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Serveur SIP' }]" />

            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm text-gray-500">
                    IP des providers, sons et playlist se gèrent ici. Agents, routage et basculement des numéros : voxnode118.
                </p>
                <p v-if="updatedAt" class="text-xs text-gray-400">Mis à jour à {{ updatedAt }} · toutes les 5 s</p>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>
            <!-- Résultat de la dernière action -->
            <div
                v-if="notice"
                class="fixed left-1/2 top-16 z-50 -translate-x-1/2 rounded-md px-4 py-2 text-sm shadow-lg"
                :class="notice.type === 'ok' ? 'bg-gray-900 text-white' : 'bg-red-600 text-white'"
            >
                {{ notice.text }}
            </div>
            <div v-if="loading" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="i in 4" :key="i" class="h-28 animate-pulse rounded-lg border border-gray-100 bg-white" />
            </div>
            <div
                v-else-if="data && !data.configured"
                class="rounded-lg border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-500"
            >
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <template v-else-if="data">
                <!-- ─── État du serveur ────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-lg border bg-white p-4" :class="asteriskUp ? 'border-gray-100' : 'border-red-200 bg-red-50'">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Asterisk</div>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full" :class="asteriskUp ? 'bg-green-500' : 'bg-red-500'" />
                            <span class="text-xl font-bold" :class="asteriskUp ? 'text-gray-900' : 'text-red-700'">
                                {{ asteriskUp ? 'En service' : health?.status === 503 ? 'Injoignable' : 'Erreur' }}
                            </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            <template v-if="asteriskUp">Version {{ version }} · démarré depuis {{ uptime(health.data.uptime_seconds) }}</template>
                            <template v-else>{{ health?.error }}</template>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-100 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Lignes SIP</div>
                        <div class="mt-2 space-y-1">
                            <div v-for="(state, line) in health?.data?.registrations ?? {}" :key="line" class="flex items-center justify-between text-sm">
                                <span class="font-mono text-gray-700">{{ line }}</span>
                                <span class="flex items-center gap-1.5 text-xs font-medium" :class="state === 'Registered' ? 'text-green-700' : 'text-red-700'">
                                    <span class="h-2 w-2 rounded-full" :class="state === 'Registered' ? 'bg-green-500' : 'bg-red-500'" />
                                    {{ state === 'Registered' ? 'Enregistrée' : state }}
                                </span>
                            </div>
                            <div v-if="!Object.keys(health?.data?.registrations ?? {}).length" class="text-sm text-gray-400">—</div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-100 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Appels en cours</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ calls.length }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ health?.data?.active_channels ?? 0 }} canal(aux) actif(s)</div>
                    </div>

                    <div class="rounded-lg border border-gray-100 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Disque libre</div>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500">Enregistrements (/data)</dt><dd class="font-medium tabular-nums text-gray-900">{{ gb(health?.data?.disk_data_free) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Système</dt><dd class="font-medium tabular-nums text-gray-900">{{ gb(health?.data?.disk_root_free) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <!-- ─── Appels en cours ────────────────────────────────────── -->
                <div class="rounded-lg border border-gray-100 bg-white">
                    <div class="px-5 py-4">
                        <h3 class="font-semibold text-gray-900">Appels en cours</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-100 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <th class="px-5 py-2">Type</th>
                                    <th class="px-3 py-2">Appelant</th>
                                    <th class="px-3 py-2">Numéro appelé</th>
                                    <th class="px-3 py-2">Connecté à</th>
                                    <th class="px-3 py-2">État</th>
                                    <th class="px-3 py-2">Application</th>
                                    <th class="px-3 py-2 text-right">Canaux</th>
                                    <th class="px-5 py-2 text-right">Durée</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in calls" :key="c.id" class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-5 py-2 text-gray-900">{{ c.type }}</td>
                                    <td class="px-3 py-2 font-mono text-gray-700">{{ c.caller || '—' }}</td>
                                    <td class="px-3 py-2 font-mono text-gray-700">{{ c.exten || '—' }}</td>
                                    <td class="px-3 py-2 font-mono text-gray-600">{{ c.connected || '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ c.state }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ c.app || '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ c.channels }}</td>
                                    <td class="px-5 py-2 text-right font-mono tabular-nums text-gray-900">{{ c.duration }}</td>
                                </tr>
                                <tr v-if="!calls.length">
                                    <td colspan="8" class="px-5 py-6 text-center text-sm text-gray-400">
                                        {{ data.calls?.ok === false ? `Liste indisponible : ${data.calls.error}` : 'Aucun appel en cours' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ─── Agents ─────────────────────────────────────────────── -->
                <div class="rounded-lg border border-gray-100 bg-white">
                    <div class="px-5 py-4">
                        <h3 class="font-semibold text-gray-900">Agents</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-100 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <th class="px-5 py-2">Agent</th>
                                    <th class="px-3 py-2">Connecté</th>
                                    <th class="px-3 py-2">En appel</th>
                                    <th class="px-3 py-2">Pause</th>
                                    <th class="px-3 py-2 text-right">Appels pris</th>
                                    <th class="px-5 py-2 text-right">Dernier appel</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in agents" :key="a.username" class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-5 py-2 font-medium text-gray-900">{{ a.username }}</td>
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-1.5 text-xs" :class="a.registered ? 'text-green-700' : 'text-gray-400'">
                                            <span class="h-2 w-2 rounded-full" :class="a.registered ? 'bg-green-500' : 'bg-gray-300'" />
                                            {{ a.registered ? 'Connecté' : 'Déconnecté' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-xs" :class="a.in_call ? 'font-medium text-gray-900' : 'text-gray-400'">{{ a.in_call ? 'En appel' : '—' }}</td>
                                    <td class="px-3 py-2 text-xs" :class="a.paused ? 'text-amber-700' : 'text-gray-400'">
                                        {{ a.paused ? `En pause${a.paused_reason ? ` · ${a.paused_reason}` : ''}` : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ a.calls_taken }}</td>
                                    <td class="px-5 py-2 text-right text-gray-600">{{ formatDateTime(a.last_call) }}</td>
                                </tr>
                                <tr v-if="!agents.length">
                                    <td colspan="6" class="px-5 py-6 text-center text-sm text-gray-400">
                                        {{ data.agents?.ok === false ? `Liste indisponible : ${data.agents.error}` : 'Aucun agent' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- ─── Sons du service interne ────────────────────────── -->
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <div class="px-5 py-4">
                            <h3 class="font-semibold text-gray-900">Sons du service interne</h3>
                            <p class="text-xs text-gray-500">mp3, wav, ogg… (50 Mo max), convertis automatiquement en wav 8 kHz mono.</p>
                        </div>

                        <!-- Ajout d'un son -->
                        <form class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-3" @submit.prevent="uploadSound">
                            <input ref="fileInput" type="file" accept="audio/*" class="max-w-56 text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-gray-100 file:px-2 file:py-1 file:text-xs" @change="onFile" />
                            <Input v-model="soundName" type="text" placeholder="nom (facultatif)" class="h-8 w-40 font-mono text-xs" />
                            <Button type="submit" size="sm" :disabled="!soundFile || busy === 'upload'">
                                <Upload class="mr-1.5 h-3.5 w-3.5" />
                                {{ busy === 'upload' ? 'Envoi…' : 'Ajouter' }}
                            </Button>
                        </form>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-100 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <th class="w-12 px-5 py-2" />
                                    <th class="px-3 py-2">Son</th>
                                    <th class="px-3 py-2 text-right">Durée</th>
                                    <th class="px-3 py-2 text-right">Modifié</th>
                                    <th class="px-5 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in sounds" :key="s.name" class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-5 py-2">
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 text-gray-600 hover:bg-gray-100"
                                            :title="playing === s.name ? 'Arrêter' : 'Écouter'"
                                            @click="toggleSound(s.name)"
                                        >
                                            <Pause v-if="playing === s.name" class="h-3.5 w-3.5" />
                                            <Play v-else class="h-3.5 w-3.5" />
                                        </button>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="font-medium text-gray-900">{{ s.name }}</span>
                                        <span v-if="playlistPosition(s.name)" class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600">dans la playlist</span>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ s.duration != null ? `${Number(s.duration).toLocaleString('fr-FR')} s` : '—' }}</td>
                                    <td class="px-3 py-2 text-right text-gray-600">{{ formatDateTime(s.modified) }}</td>
                                    <td class="px-5 py-2">
                                        <div class="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                                title="Ajouter à la playlist"
                                                @click="addToPlaylist(s.name)"
                                            >
                                                <ListPlus class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30"
                                                :disabled="!!playlistPosition(s.name) || busy === `sound-${s.name}`"
                                                :title="playlistPosition(s.name) ? 'Retirer d\'abord ce son de la playlist enregistrée' : 'Supprimer'"
                                                @click="deleteSound(s.name)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!sounds.length">
                                    <td colspan="5" class="px-5 py-6 text-center text-sm text-gray-400">
                                        {{ data.sounds?.ok === false ? `Liste indisponible : ${data.sounds.error}` : 'Aucun son' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ─── Playlist ───────────────────────────────────────── -->
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <div class="flex items-start justify-between gap-3 px-5 py-4">
                            <div>
                                <h3 class="font-semibold text-gray-900">Playlist du service interne</h3>
                                <p class="text-xs text-gray-500">Lue en boucle, dans cet ordre, pendant un transfert vers le service interne. Vide = musique d'attente par défaut.</p>
                            </div>
                            <div v-if="draftDirty" class="flex shrink-0 gap-2">
                                <Button variant="outline" size="sm" :disabled="busy === 'playlist'" @click="resetPlaylist">Annuler</Button>
                                <Button size="sm" :disabled="busy === 'playlist'" @click="savePlaylist">{{ busy === 'playlist' ? 'Enregistrement…' : 'Enregistrer' }}</Button>
                            </div>
                        </div>
                        <p v-if="draftDirty" class="mx-5 mb-2 rounded bg-amber-50 px-3 py-1.5 text-xs text-amber-800">Modifications non enregistrées : la playlist en service est toujours l'ancienne.</p>
                        <ol class="border-t border-gray-100">
                            <li
                                v-for="(name, i) in draft"
                                :key="`${name}-${i}`"
                                draggable="true"
                                class="flex items-center gap-3 border-b border-gray-50 px-5 py-2 text-sm hover:bg-gray-50"
                                :class="{ 'opacity-40': dragIndex === i }"
                                @dragstart="dragIndex = i"
                                @dragend="dragIndex = null"
                                @dragover.prevent
                                @drop="onDrop(i)"
                            >
                                <GripVertical class="h-4 w-4 shrink-0 cursor-grab text-gray-300" />
                                <span class="w-6 tabular-nums text-gray-400">{{ i + 1 }}.</span>
                                <span class="flex-1 font-medium text-gray-900">{{ name }}</span>
                                <span v-if="!sounds.some((s) => s.name === name)" class="text-xs text-red-600">son introuvable</span>
                                <div class="flex gap-0.5">
                                    <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-gray-400 hover:bg-gray-100 disabled:opacity-30" :disabled="i === 0" title="Monter" @click="moveInPlaylist(i, i - 1)"><ArrowUp class="h-3.5 w-3.5" /></button>
                                    <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-gray-400 hover:bg-gray-100 disabled:opacity-30" :disabled="i === draft.length - 1" title="Descendre" @click="moveInPlaylist(i, i + 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
                                    <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-gray-400 hover:bg-red-50 hover:text-red-600" title="Retirer de la playlist" @click="removeFromPlaylist(i)"><X class="h-3.5 w-3.5" /></button>
                                </div>
                            </li>
                            <li v-if="!draft.length" class="px-5 py-6 text-center text-sm text-gray-400">Playlist vide : musique d'attente par défaut</li>
                        </ol>
                        <p class="px-5 py-3 text-xs text-gray-400">Ajouter un son avec le bouton <ListPlus class="inline h-3.5 w-3.5" /> de la liste des sons. Glisser les lignes pour changer l'ordre.</p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- ─── IP des providers ───────────────────────────────── -->
                    <div class="rounded-lg border border-gray-100 bg-white p-5">
                        <div class="mb-3 flex items-baseline justify-between">
                            <h3 class="font-semibold text-gray-900">IP des providers autorisées en SIP</h3>
                            <span class="text-sm text-gray-400">{{ trustedIps.length }}</span>
                        </div>
                        <form class="mb-1 flex gap-2" @submit.prevent="addIp">
                            <Input v-model="newIp" type="text" placeholder="1.2.3.4 ou 1.2.3.0/24" class="h-8 font-mono text-sm" />
                            <Button type="submit" size="sm" :disabled="!newIp.trim() || newIpIsVoxnode || busy === 'ip-add'">
                                {{ busy === 'ip-add' ? 'Ajout…' : 'Ajouter' }}
                            </Button>
                        </form>
                        <p v-if="newIpIsVoxnode" class="mb-2 text-xs text-red-600">C'est l'IP de Voxnode : elle est exclue volontairement, elle ne doit pas être ajoutée.</p>
                        <p v-else class="mb-3 text-xs text-gray-400">Appliqué à Asterisk et au pare-feu en environ 1 seconde.</p>
                        <div v-if="trustedIps.length" class="flex flex-wrap gap-1.5">
                            <span v-for="ip in trustedIps" :key="ip" class="group flex items-center gap-1 rounded border border-gray-200 bg-gray-50 py-0.5 pl-2 pr-1 font-mono text-xs text-gray-700">
                                {{ ip }}
                                <button
                                    type="button"
                                    class="flex h-4 w-4 items-center justify-center rounded text-gray-300 hover:bg-red-100 hover:text-red-600 disabled:opacity-30"
                                    :disabled="busy === `ip-${ip}`"
                                    :title="`Retirer ${ip}`"
                                    @click="removeIp(ip)"
                                >
                                    <X class="h-3 w-3" />
                                </button>
                            </span>
                        </div>
                        <p v-else class="text-sm text-gray-400">
                            {{ data['trusted-ips']?.ok === false ? `Liste indisponible : ${data['trusted-ips'].error}` : 'Aucune IP' }}
                        </p>
                    </div>

                    <!-- ─── Infos du serveur ───────────────────────────────── -->
                    <div class="rounded-lg border border-gray-100 bg-white p-5">
                        <h3 class="mb-3 font-semibold text-gray-900">Serveur</h3>
                        <dl class="space-y-1.5 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-gray-500">IP publique SIP / RTP</dt><dd class="font-mono text-gray-900">54.36.250.193</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-gray-500">WebRTC (WSS)</dt><dd class="font-mono text-gray-900">wss://sip.118.ae/ws</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-gray-500">Machine</dt><dd class="text-gray-900">OVH RISE-2 · Gravelines</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-gray-500">Système</dt><dd class="text-gray-900">Ubuntu 26.04 · Asterisk {{ version }}</dd></div>
                        </dl>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
