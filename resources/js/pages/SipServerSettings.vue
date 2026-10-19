<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Pause, Play, Plus, Trash2 } from 'lucide-vue-next'
import { xsrfToken } from '@/lib/csrf'

/**
 * Configuration du serveur SIP (rarement modifiée) : service interne, IP des providers, infos du serveur.
 * Page à part, sans entrée de menu : on y arrive depuis « Serveur SIP ».
 * Chaque modification s'applique immédiatement sur le serveur de production.
 */
const INTERNAL_SERVICE_NUMBER = '01 84 13 15 72'
const VOXNODE_IP = '13.36.113.246'

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
onMounted(load)
onUnmounted(() => stopSound())

const configured = computed(() => data.value?.configured !== false)
const version = computed(() => data.value?.health?.data?.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? '—')
const formatDate = (v) => (v ? new Date(v).toLocaleDateString('fr-FR') : '—')
const seconds = (v) => (v != null ? `${Number(v).toLocaleString('fr-FR')} s` : '—')

// ─── Écritures ────────────────────────────────────────────────────────────────
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

const busy = ref(false)
// Renvoie true si l'action a réussi
async function run(action, success) {
    busy.value = true
    try {
        await action()
        notify('ok', success)
        await load()
        return true
    } catch (e) {
        notify('error', e.message)
        return false
    } finally {
        busy.value = false
    }
}

// ─── Écoute ───────────────────────────────────────────────────────────────────
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

// ─── Service interne ──────────────────────────────────────────────────────────
// « Lecture » = la playlist du serveur : ce qu'entend l'appelant, dans l'ordre, en boucle.
const sounds = computed(() => data.value?.sounds?.data?.sounds ?? [])
const playlist = computed(() => data.value?.sounds?.data?.playlist ?? [])
const soundInfo = (name) => sounds.value.find((s) => s.name === name)
const unusedSounds = computed(() => sounds.value.filter((s) => !playlist.value.includes(s.name)))

const savePlaylist = (list, success) => run(() => send('PUT', '/data/sip/playlist', { sounds: list }), success)

function move(index, to) {
    const list = [...playlist.value]
    const [item] = list.splice(index, 1)
    list.splice(to, 0, item)
    savePlaylist(list, 'Ordre mis à jour')
}

function removeFromPlaylist(index) {
    const name = playlist.value[index]
    if (!confirm(`Ne plus jouer « ${name} » pendant les transferts ?\n\nLe fichier est conservé, il pourra être remis plus tard.`)) return
    savePlaylist(playlist.value.filter((_, i) => i !== index), `« ${name} » n'est plus joué`)
}

function deleteSound(name) {
    if (!confirm(`Supprimer définitivement le fichier « ${name} » ?`)) return
    run(() => send('DELETE', `/data/sip/sounds/${encodeURIComponent(name)}`), `Fichier « ${name} » supprimé`)
}

// Ajout : un nouveau fichier, ou un son déjà envoyé mais pas joué
const showSoundDialog = ref(false)
const soundSource = ref('upload') // 'upload' | 'existing'
const soundFile = ref(null)
const soundName = ref('')
const existingName = ref('')

function openSoundDialog() {
    soundSource.value = 'upload'
    soundFile.value = null
    soundName.value = ''
    existingName.value = unusedSounds.value[0]?.name ?? ''
    showSoundDialog.value = true
}

function onFile(e) {
    soundFile.value = e.target.files[0] ?? null
    // Nom proposé à partir du fichier (le serveur fait pareil s'il est vide)
    if (soundFile.value && !soundName.value) {
        soundName.value = soundFile.value.name.replace(/\.[^.]+$/, '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '')
    }
}

const canAddSound = computed(() => (soundSource.value === 'upload' ? !!soundFile.value : !!existingName.value))

async function addSound() {
    let name = existingName.value
    const ok = await run(async () => {
        if (soundSource.value === 'upload') {
            const form = new FormData()
            form.append('file', soundFile.value)
            if (soundName.value) form.append('name', soundName.value)
            name = (await send('POST', '/data/sip/sounds', form)).sound.name
        }
        await send('PUT', '/data/sip/playlist', { sounds: [...playlist.value, name] })
    }, 'Son ajouté à la fin de la lecture')
    if (ok) showSoundDialog.value = false
}

// ─── IP des providers ─────────────────────────────────────────────────────────
const trustedIps = computed(() => (data.value?.['trusted-ips']?.data?.ips ?? []).map((ip) => ({ ip })))
const ipColumns = [
    { key: 'ip', label: 'IP / plage' },
    { key: 'actions', label: '' },
]

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
    if (await run(() => send('POST', '/data/sip/trusted-ips', { ip }), `${ip} autorisée`)) showIpDialog.value = false
}

function removeIp(ip) {
    if (!confirm(`Retirer ${ip} des IP autorisées ?\n\nLes appels de ce provider seront refusés immédiatement.`)) return
    run(() => send('DELETE', `/data/sip/trusted-ips/${encodeURIComponent(ip)}`), `${ip} retirée`)
}
</script>

<template>
    <Head title="Configuration du serveur SIP" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Serveur SIP', href: '/sip-server' }, { title: 'Configuration' }]">
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/sip-server">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
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

            <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <p>Réglages du serveur en production, rarement modifiés. Chaque changement s'applique immédiatement aux appels en cours et à venir.</p>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>
            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <template v-else>
                <!-- ─── Service interne ───────────────────────────────────── -->
                <div>
                    <div class="mb-3 flex items-end justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">Service interne</h2>
                            <p class="mt-0.5 text-sm text-gray-500">
                                Quand un appel est transféré vers le service interne ({{ INTERNAL_SERVICE_NUMBER }}), l'appelant entend ces sons l'un après l'autre, en boucle.
                            </p>
                        </div>
                        <Button size="sm" variant="secondary" class="shrink-0" :disabled="busy || loading" @click="openSoundDialog">
                            <Plus class="mr-1.5 h-4 w-4" />
                            Ajouter un son
                        </Button>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <div v-if="loading" class="h-14 animate-pulse" />
                        <div v-else-if="!playlist.length" class="px-4 py-8 text-center text-sm text-gray-500">
                            Aucun son : l'appelant entend la musique d'attente par défaut.
                        </div>
                        <ol v-else>
                            <li
                                v-for="(name, i) in playlist"
                                :key="`${name}-${i}`"
                                class="flex items-center gap-3 border-b border-gray-50 px-4 py-2.5 text-sm last:border-b-0"
                            >
                                <span class="w-5 tabular-nums text-gray-400">{{ i + 1 }}.</span>
                                <Button variant="ghost" size="sm" class="h-7 w-7 p-0" :title="playing === name ? 'Arrêter' : 'Écouter'" @click="toggleSound(name)">
                                    <Pause v-if="playing === name" class="size-3.5" />
                                    <Play v-else class="size-3.5" />
                                </Button>
                                <span class="flex-1 font-medium text-gray-900">{{ name }}</span>
                                <span class="tabular-nums text-gray-500">{{ seconds(soundInfo(name)?.duration) }}</span>
                                <div class="flex items-center gap-0.5">
                                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="busy || i === 0" title="Jouer plus tôt" @click="move(i, i - 1)"><ArrowUp class="size-3.5" /></Button>
                                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="busy || i === playlist.length - 1" title="Jouer plus tard" @click="move(i, i + 1)"><ArrowDown class="size-3.5" /></Button>
                                    <Button variant="secondary" size="sm" class="ml-1 h-7 px-2 text-xs" :disabled="busy" @click="removeFromPlaylist(i)">Retirer</Button>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- Fichiers envoyés mais plus joués -->
                    <details v-if="unusedSounds.length" class="mt-3 text-sm">
                        <summary class="cursor-pointer text-gray-500 hover:text-gray-700">
                            {{ unusedSounds.length }} fichier{{ unusedSounds.length > 1 ? 's' : '' }} envoyé{{ unusedSounds.length > 1 ? 's' : '' }} mais non joué{{ unusedSounds.length > 1 ? 's' : '' }}
                        </summary>
                        <div class="mt-2 rounded-lg border border-gray-100 bg-white">
                            <div v-for="s in unusedSounds" :key="s.name" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 last:border-b-0">
                                <Button variant="ghost" size="sm" class="h-7 w-7 p-0" :title="playing === s.name ? 'Arrêter' : 'Écouter'" @click="toggleSound(s.name)">
                                    <Pause v-if="playing === s.name" class="size-3.5" />
                                    <Play v-else class="size-3.5" />
                                </Button>
                                <span class="flex-1 text-gray-700">{{ s.name }}</span>
                                <span class="tabular-nums text-gray-500">{{ seconds(s.duration) }} · {{ formatDate(s.modified) }}</span>
                                <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400 hover:text-red-600" :disabled="busy" title="Supprimer le fichier" @click="deleteSound(s.name)">
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </details>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- ─── IP des providers ──────────────────────────────── -->
                    <div>
                        <div class="mb-3 flex items-end justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">IP des providers autorisées <span class="font-normal text-gray-400">· {{ trustedIps.length }}</span></h2>
                                <p class="mt-0.5 text-sm text-gray-500">Seuls ces serveurs peuvent nous envoyer des appels.</p>
                            </div>
                            <Button size="sm" variant="secondary" class="shrink-0" :disabled="busy || loading" @click="openIpDialog">
                                <Plus class="mr-1.5 h-4 w-4" />
                                Ajouter une IP
                            </Button>
                        </div>
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
                                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400 hover:text-red-600" :disabled="busy" :title="`Retirer ${row.ip}`" @click="removeIp(row.ip)">
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
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">IP publique SIP / RTP (à donner aux providers)</dt><dd class="font-mono text-gray-900">54.36.250.193</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">WebRTC (WSS)</dt><dd class="font-mono text-gray-900">wss://sip.118.ae/ws</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Machine</dt><dd class="text-gray-900">OVH RISE-2 · Gravelines</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Système</dt><dd class="text-gray-900">Ubuntu 26.04 · Asterisk {{ version }}</dd></div>
                            </dl>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- ─── Fenêtre : ajouter un son à la lecture ─────────────────────── -->
        <Dialog v-model:open="showSoundDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ajouter un son au service interne</DialogTitle>
                    <DialogDescription>Il sera joué en dernier. L'ordre se change ensuite avec les flèches.</DialogDescription>
                </DialogHeader>
                <form id="sound-form" class="space-y-4" @submit.prevent="addSound">
                    <div class="flex gap-4 text-sm">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="soundSource" type="radio" value="upload" />
                            Nouveau fichier
                        </label>
                        <label class="flex items-center gap-2" :class="unusedSounds.length ? 'cursor-pointer' : 'cursor-not-allowed text-gray-400'">
                            <input v-model="soundSource" type="radio" value="existing" :disabled="!unusedSounds.length" />
                            Fichier déjà envoyé
                        </label>
                    </div>

                    <template v-if="soundSource === 'upload'">
                        <div class="space-y-2">
                            <Label for="sound-file">Fichier audio</Label>
                            <Input id="sound-file" type="file" accept="audio/*" @change="onFile" />
                            <p class="text-xs text-gray-500">mp3, wav, ogg… (50 Mo maximum), converti automatiquement au format téléphone.</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="sound-name">Nom</Label>
                            <Input id="sound-name" v-model="soundName" type="text" placeholder="accueil" class="font-mono" />
                            <p class="text-xs text-gray-500">Lettres minuscules, chiffres, - et _. Proposé à partir du nom du fichier.</p>
                        </div>
                    </template>
                    <div v-else class="space-y-2">
                        <Label for="existing-sound">Fichier</Label>
                        <select id="existing-sound" v-model="existingName" class="h-9 w-full rounded-md border border-gray-200 bg-white px-3 text-sm">
                            <option v-for="s in unusedSounds" :key="s.name" :value="s.name">{{ s.name }} ({{ seconds(s.duration) }})</option>
                        </select>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="showSoundDialog = false">Annuler</Button>
                    <Button type="submit" form="sound-form" :disabled="!canAddSound || busy">{{ busy ? 'Ajout…' : 'Ajouter' }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- ─── Fenêtre : ajouter une IP ──────────────────────────────────── -->
        <Dialog v-model:open="showIpDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Autoriser une IP de provider</DialogTitle>
                    <DialogDescription>Le provider pourra nous envoyer des appels depuis cette IP, en environ 1 seconde.</DialogDescription>
                </DialogHeader>
                <form id="ip-form" class="space-y-2" @submit.prevent="addIp">
                    <Label for="new-ip">IP ou plage</Label>
                    <Input id="new-ip" v-model="newIp" type="text" placeholder="1.2.3.4 ou 1.2.3.0/24" class="font-mono" />
                    <p v-if="newIpIsVoxnode" class="text-sm text-red-600">C'est l'IP de Voxnode : elle est exclue volontairement, elle ne doit pas être ajoutée.</p>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="showIpDialog = false">Annuler</Button>
                    <Button type="submit" form="ip-form" :disabled="!newIp.trim() || newIpIsVoxnode || busy">{{ busy ? 'Ajout…' : 'Autoriser' }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
