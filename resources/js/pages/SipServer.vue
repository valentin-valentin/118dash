<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ArrowDown, ArrowUp, GripVertical, ListPlus, Pause, Play, Plus, Trash2, Upload, X } from 'lucide-vue-next'
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

const configured = computed(() => data.value?.configured !== false)
const updatedAt = computed(() => (data.value?.fetched_at ? new Date(data.value.fetched_at).toLocaleTimeString('fr-FR') : null))
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

// ─── État du serveur ──────────────────────────────────────────────────────────
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
// Renvoie true si l'action a réussi
async function run(key, action, success) {
    busy.value = key
    try {
        await action()
        notify('ok', success)
        await load()
        return true
    } catch (e) {
        notify('error', e.message)
        return false
    } finally {
        busy.value = null
    }
}

// ─── IP des providers ─────────────────────────────────────────────────────────
const trustedIps = computed(() => (data.value?.['trusted-ips']?.data?.ips ?? []).map((ip) => ({ ip })))
const ipColumns = [
    { key: 'ip', label: 'IP / plage' },
    { key: 'actions', label: '' },
]

const VOXNODE_IP = '13.36.113.246'
const showIpDialog = ref(false)
const newIp = ref('')
const newIpIsVoxnode = computed(() => newIp.value.trim().split('/')[0] === VOXNODE_IP)

function openIpDialog() {
    newIp.value = ''
    showIpDialog.value = true
}

async function addIp() {
    const ip = newIp.value.trim()
    if (!ip || newIpIsVoxnode.value) return
    if (await run('ip-add', () => send('POST', '/data/sip/trusted-ips', { ip }), `${ip} ajoutée aux providers autorisés`)) {
        showIpDialog.value = false
    }
}

function removeIp(ip) {
    if (!confirm(`Retirer ${ip} des IP autorisées ?\n\nLes appels de ce provider seront refusés immédiatement.`)) return
    run(`ip-${ip}`, () => send('DELETE', `/data/sip/trusted-ips/${encodeURIComponent(ip)}`), `${ip} retirée`)
}

// ─── Sons ─────────────────────────────────────────────────────────────────────
const sounds = computed(() => data.value?.sounds?.data?.sounds ?? [])
const playlist = computed(() => data.value?.sounds?.data?.playlist ?? [])
const inPlaylist = (name) => playlist.value.includes(name)

const soundColumns = [
    { key: 'play', label: '' },
    { key: 'name', label: 'Son' },
    { key: 'duration', label: 'Durée' },
    { key: 'modified', label: 'Modifié' },
    { key: 'actions', label: '' },
]

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

const showSoundDialog = ref(false)
const soundFile = ref(null)
const soundName = ref('')

function openSoundDialog() {
    soundFile.value = null
    soundName.value = ''
    showSoundDialog.value = true
}

function onFile(e) {
    soundFile.value = e.target.files[0] ?? null
    // Nom proposé à partir du fichier (le serveur fait pareil s'il est vide)
    if (soundFile.value && !soundName.value) {
        soundName.value = soundFile.value.name.replace(/\.[^.]+$/, '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '')
    }
}

async function uploadSound() {
    if (!soundFile.value) return
    const form = new FormData()
    form.append('file', soundFile.value)
    if (soundName.value) form.append('name', soundName.value)
    if (await run('upload', () => send('POST', '/data/sip/sounds', form), 'Son ajouté (converti en wav 8 kHz mono)')) {
        showSoundDialog.value = false
    }
}

function deleteSound(name) {
    if (!confirm(`Supprimer le son « ${name} » ?`)) return
    run(`sound-${name}`, () => send('DELETE', `/data/sip/sounds/${encodeURIComponent(name)}`), `Son « ${name} » supprimé`)
}

// ─── Playlist : brouillon modifiable, enregistré d'un coup (PUT de l'ordre complet) ─
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

async function savePlaylist() {
    const sounds = [...draft.value]
    if (await run('playlist', () => send('PUT', '/data/sip/playlist', { sounds }), sounds.length ? 'Playlist enregistrée' : 'Playlist vidée : musique d\'attente par défaut')) {
        edited.value = false
    }
}
function resetPlaylist() {
    edited.value = false
    draft.value = [...playlist.value]
}
</script>

<template>
    <Head title="Serveur SIP" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Serveur SIP' }]">
                <template v-if="configured" #actions>
                    <Button variant="outline" @click="openIpDialog">
                        <Plus class="mr-2 h-4 w-4" />
                        Ajouter une IP
                    </Button>
                    <Button @click="openSoundDialog">
                        <Upload class="mr-2 h-4 w-4" />
                        Ajouter un son
                    </Button>
                </template>
            </PageHeader>

            <!-- Résultat de la dernière action -->
            <div
                v-if="notice"
                class="fixed left-1/2 top-16 z-50 -translate-x-1/2 rounded-md px-4 py-2 text-sm shadow-lg"
                :class="notice.type === 'ok' ? 'bg-gray-900 text-white' : 'bg-red-600 text-white'"
            >
                {{ notice.text }}
            </div>

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
                        :variant="asteriskUp ? 'success' : 'danger'"
                    />
                    <StatCard
                        label="Ligne OVH"
                        :value="allRegistered ? 'Enregistrée' : registrations.length ? 'Non enregistrée' : '—'"
                        :sub="registrations.map(([line, state]) => (state === 'Registered' ? line : `${line} : ${state}`)).join(' · ')"
                        :loading="loading"
                        :variant="allRegistered ? 'success' : 'danger'"
                    />
                    <StatCard
                        label="Appels en cours"
                        :value="calls.length"
                        :sub="`${health?.data?.active_channels ?? 0} canal(aux) actif(s)`"
                        :loading="loading"
                    />
                    <StatCard
                        label="Agents connectés"
                        :value="`${registeredAgents} / ${agents.length}`"
                        :loading="loading"
                    />
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
                                <span class="flex items-center gap-1.5 text-xs" :class="value ? 'text-green-700' : 'text-gray-400'">
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
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- ─── Sons du service interne ───────────────────────── -->
                    <div>
                        <h2 class="mb-3 text-base font-semibold text-gray-900">Sons du service interne</h2>
                        <div class="rounded-lg border border-gray-100 bg-white">
                            <DataTable
                                :columns="soundColumns"
                                :rows="sounds"
                                :loading="loading"
                                :empty-message="data?.sounds?.ok === false ? `Liste indisponible : ${data.sounds.error}` : 'Aucun son'"
                            >
                                <template #play="{ row }">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 w-7 p-0"
                                        :title="playing === row.name ? 'Arrêter' : 'Écouter'"
                                        @click="toggleSound(row.name)"
                                    >
                                        <Pause v-if="playing === row.name" class="size-3.5" />
                                        <Play v-else class="size-3.5" />
                                    </Button>
                                </template>
                                <template #name="{ row }">
                                    <span class="font-medium text-gray-900">{{ row.name }}</span>
                                    <span v-if="inPlaylist(row.name)" class="ml-2 text-xs text-gray-400">dans la playlist</span>
                                </template>
                                <template #duration="{ value }"><span class="tabular-nums text-gray-700">{{ value != null ? `${Number(value).toLocaleString('fr-FR')} s` : '—' }}</span></template>
                                <template #modified="{ value }"><span class="text-sm text-gray-600">{{ formatDateTime(value) }}</span></template>
                                <template #actions="{ row }">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button variant="secondary" size="sm" class="h-7 gap-1 px-2 text-xs" @click="addToPlaylist(row.name)">
                                            <ListPlus class="size-3" />
                                            Playlist
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 w-7 p-0 text-gray-400 hover:text-red-600"
                                            :disabled="inPlaylist(row.name) || busy === `sound-${row.name}`"
                                            :title="inPlaylist(row.name) ? 'Retirer d\'abord ce son de la playlist enregistrée' : 'Supprimer'"
                                            @click="deleteSound(row.name)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </template>
                            </DataTable>
                        </div>
                    </div>

                    <!-- ─── Playlist ──────────────────────────────────────── -->
                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-gray-900">Playlist du service interne</h2>
                            <div v-if="draftDirty" class="flex gap-2">
                                <Button variant="outline" size="sm" class="h-7 px-2 text-xs" :disabled="busy === 'playlist'" @click="resetPlaylist">Annuler</Button>
                                <Button size="sm" class="h-7 px-2 text-xs" :disabled="busy === 'playlist'" @click="savePlaylist">
                                    {{ busy === 'playlist' ? 'Enregistrement…' : 'Enregistrer' }}
                                </Button>
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-white">
                            <p v-if="draftDirty" class="border-b border-gray-100 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                                Modifications non enregistrées : la playlist en service est toujours l'ancienne.
                            </p>
                            <ol>
                                <li
                                    v-for="(name, i) in draft"
                                    :key="`${name}-${i}`"
                                    draggable="true"
                                    class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 text-sm last:border-b-0 hover:bg-gray-50"
                                    :class="{ 'opacity-40': dragIndex === i }"
                                    @dragstart="dragIndex = i"
                                    @dragend="dragIndex = null"
                                    @dragover.prevent
                                    @drop="onDrop(i)"
                                >
                                    <GripVertical class="size-4 shrink-0 cursor-grab text-gray-300" />
                                    <span class="w-5 tabular-nums text-gray-400">{{ i + 1 }}.</span>
                                    <span class="flex-1 font-medium text-gray-900">{{ name }}</span>
                                    <span v-if="!sounds.some((s) => s.name === name)" class="text-xs text-red-600">son introuvable</span>
                                    <div class="flex gap-0.5">
                                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="i === 0" title="Monter" @click="moveInPlaylist(i, i - 1)"><ArrowUp class="size-3.5" /></Button>
                                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="i === draft.length - 1" title="Descendre" @click="moveInPlaylist(i, i + 1)"><ArrowDown class="size-3.5" /></Button>
                                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400 hover:text-red-600" title="Retirer de la playlist" @click="removeFromPlaylist(i)"><X class="size-3.5" /></Button>
                                    </div>
                                </li>
                                <li v-if="!draft.length" class="px-4 py-8 text-center text-sm text-gray-400">Playlist vide : musique d'attente par défaut</li>
                            </ol>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            Lue en boucle, dans cet ordre, pendant un transfert vers le service interne. Glisser les lignes pour changer l'ordre.
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- ─── IP des providers ──────────────────────────────── -->
                    <div>
                        <h2 class="mb-3 text-base font-semibold text-gray-900">
                            IP des providers autorisées
                            <span class="font-normal text-gray-400">· {{ trustedIps.length }}</span>
                        </h2>
                        <div class="rounded-lg border border-gray-100 bg-white">
                            <DataTable
                                :columns="ipColumns"
                                :rows="trustedIps"
                                :loading="loading"
                                :empty-message="data?.['trusted-ips']?.ok === false ? `Liste indisponible : ${data['trusted-ips'].error}` : 'Aucune IP'"
                            >
                                <template #ip="{ value }"><span class="font-mono text-sm text-gray-900">{{ value }}</span></template>
                                <template #actions="{ row }">
                                    <div class="flex justify-end">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 w-7 p-0 text-gray-400 hover:text-red-600"
                                            :disabled="busy === `ip-${row.ip}`"
                                            :title="`Retirer ${row.ip}`"
                                            @click="removeIp(row.ip)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </template>
                            </DataTable>
                        </div>
                    </div>

                    <!-- ─── Serveur ───────────────────────────────────────── -->
                    <div>
                        <h2 class="mb-3 text-base font-semibold text-gray-900">Serveur</h2>
                        <div class="rounded-lg border border-gray-100 bg-white p-4">
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">IP publique SIP / RTP</dt><dd class="font-mono text-gray-900">54.36.250.193</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">WebRTC (WSS)</dt><dd class="font-mono text-gray-900">wss://sip.118.ae/ws</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Machine</dt><dd class="text-gray-900">OVH RISE-2 · Gravelines</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Système</dt><dd class="text-gray-900">Ubuntu 26.04 · Asterisk {{ version }}</dd></div>
                            </dl>
                            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500">
                                Agents, routage et basculement des numéros se gèrent dans voxnode118.
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- ─── Fenêtre : ajouter une IP ──────────────────────────────────── -->
        <Dialog v-model:open="showIpDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ajouter une IP de provider</DialogTitle>
                    <DialogDescription>Appliqué à Asterisk et au pare-feu en environ 1 seconde.</DialogDescription>
                </DialogHeader>
                <form id="ip-form" class="space-y-2" @submit.prevent="addIp">
                    <Label for="new-ip">IP ou plage</Label>
                    <Input id="new-ip" v-model="newIp" type="text" placeholder="1.2.3.4 ou 1.2.3.0/24" class="font-mono" />
                    <p v-if="newIpIsVoxnode" class="text-sm text-red-600">C'est l'IP de Voxnode : elle est exclue volontairement, elle ne doit pas être ajoutée.</p>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="showIpDialog = false">Annuler</Button>
                    <Button type="submit" form="ip-form" :disabled="!newIp.trim() || newIpIsVoxnode || busy === 'ip-add'">
                        {{ busy === 'ip-add' ? 'Ajout…' : 'Ajouter' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- ─── Fenêtre : ajouter un son ──────────────────────────────────── -->
        <Dialog v-model:open="showSoundDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ajouter un son</DialogTitle>
                    <DialogDescription>mp3, wav, ogg… (50 Mo maximum), converti automatiquement en wav 8 kHz mono.</DialogDescription>
                </DialogHeader>
                <form id="sound-form" class="space-y-4" @submit.prevent="uploadSound">
                    <div class="space-y-2">
                        <Label for="sound-file">Fichier</Label>
                        <Input id="sound-file" type="file" accept="audio/*" @change="onFile" />
                    </div>
                    <div class="space-y-2">
                        <Label for="sound-name">Nom</Label>
                        <Input id="sound-name" v-model="soundName" type="text" placeholder="accueil" class="font-mono" />
                        <p class="text-xs text-gray-500">Lettres minuscules, chiffres, - et _. Proposé à partir du nom du fichier.</p>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="showSoundDialog = false">Annuler</Button>
                    <Button type="submit" form="sound-form" :disabled="!soundFile || busy === 'upload'">
                        {{ busy === 'upload' ? 'Envoi…' : 'Ajouter' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
