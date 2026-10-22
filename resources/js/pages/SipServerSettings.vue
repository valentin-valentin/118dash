<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import SipSoundSequence from '@/components/SipSoundSequence.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { AlertTriangle, ArrowLeft, Pause, Play, Plus, Trash2 } from 'lucide-vue-next'
import { Checkbox } from '@/components/ui/checkbox'
import { xsrfToken } from '@/lib/csrf'
import { DAYS, formatDay, markOpeningHoursSaved, openingHoursProblem, sameWeek, todayKey } from '@/lib/openingHours'

/**
 * Configuration du serveur SIP (rarement modifiée) : service interne, IP des providers, infos du serveur.
 * Page à part, sans entrée de menu : on y arrive depuis « Serveur SIP ».
 * Chaque modification s'applique immédiatement sur le serveur de production.
 */
const INTERNAL_SERVICE_NUMBER = '01 84 13 15 72'
const VOXNODE_IP = '13.36.113.246'

const data = ref(null)
const telephony = ref(null) // API voxnode118 : répartition du trafic, agents
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
onMounted(load)
onUnmounted(() => {
    stopSound()
    clearInterval(hoursTimer)
})

const configured = computed(() => data.value?.configured !== false)
const version = computed(() => data.value?.health?.data?.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? '—')
const health = computed(() => data.value?.health?.data ?? {})
const usage = computed(() => health.value.data_usage ?? null)

// Tailles lisibles : Ko, Mo, Go
function size(bytes) {
    if (bytes == null) return '—'
    const units = [['Go', 1e9], ['Mo', 1e6], ['Ko', 1e3]]
    const [unit, factor] = units.find(([, f]) => bytes >= f) ?? ['Ko', 1e3]
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: bytes >= 10 * factor ? 0 : 1 }).format(bytes / factor)} ${unit}`
}
const used = (prefix) => (health.value[`${prefix}_total`] ? `${size(health.value[`${prefix}_used`])} utilisés sur ${size(health.value[`${prefix}_total`])}` : '—')

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

// ─── Sons : trois listes ordonnées, appliquées immédiatement ─────────────────
// internal = playlist du service interne ; intro / hold = accueil des appels entrants
const sounds = computed(() => data.value?.sounds?.data?.sounds ?? [])
const welcome = computed(() => data.value?.welcome?.data ?? null)
const sequences = computed(() => ({
    internal: data.value?.sounds?.data?.playlist ?? [],
    intro: welcome.value?.intro ?? [],
    hold: welcome.value?.hold ?? [],
}))
// Un fichier peut être supprimé seulement s'il n'est joué nulle part
const usedNames = computed(() => new Set(Object.values(sequences.value).flat()))
const unusedSounds = computed(() => sounds.value.filter((s) => !usedNames.value.has(s.name)))

const SEQUENCE_LABELS = { internal: 'au service interne', intro: "à l'introduction", hold: "à l'audio d'attente" }

function sequenceRequest(target, list) {
    return target === 'internal'
        ? send('PUT', '/data/sip/playlist', { sounds: list })
        : send('PUT', '/data/sip/welcome', { [target]: list })
}
const saveSequence = (target, list, success) => run(() => sequenceRequest(target, list), success)

function deleteSound(name) {
    if (!confirm(`Supprimer définitivement le fichier « ${name} » ?`)) return
    run(() => send('DELETE', `/data/sip/sounds/${encodeURIComponent(name)}`), `Fichier « ${name} » supprimé`)
}

// Ajout dans une liste : un nouveau fichier, ou un fichier déjà envoyé
const showSoundDialog = ref(false)
const soundTarget = ref('internal')
const soundSource = ref('upload') // 'upload' | 'existing'
const soundFile = ref(null)
const soundName = ref('')
const existingName = ref('')
// Fichiers proposables : tous ceux qui ne sont pas déjà dans la liste visée
const addableSounds = computed(() => sounds.value.filter((s) => !sequences.value[soundTarget.value].includes(s.name)))

function openSoundDialog(target) {
    soundTarget.value = target
    soundSource.value = 'upload'
    soundFile.value = null
    soundName.value = ''
    existingName.value = addableSounds.value[0]?.name ?? ''
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
    const target = soundTarget.value
    let name = existingName.value
    const ok = await run(async () => {
        if (soundSource.value === 'upload') {
            const form = new FormData()
            form.append('file', soundFile.value)
            if (soundName.value) form.append('name', soundName.value)
            name = (await send('POST', '/data/sip/sounds', form)).sound.name
        }
        await sequenceRequest(target, [...sequences.value[target], name])
    }, `Son ajouté ${SEQUENCE_LABELS[target]}`)
    if (ok) showSoundDialog.value = false
}

// ─── Attente forcée (secondes, tirée au hasard entre min et max à chaque appel) ─
const waitMin = ref(null)
const waitMax = ref(null)
const waitEdited = ref(false)
const waitDirty = computed(() => waitEdited.value && !!welcome.value && (Number(waitMin.value) !== welcome.value.wait_min || Number(waitMax.value) !== welcome.value.wait_max))
// Tant qu'on ne les a pas modifiées ici, les valeurs suivent le serveur
watch(welcome, (w) => {
    if (w && !waitEdited.value) {
        waitMin.value = w.wait_min
        waitMax.value = w.wait_max
    }
}, { immediate: true })
watch([waitMin, waitMax], () => {
    if (welcome.value && (Number(waitMin.value) !== welcome.value.wait_min || Number(waitMax.value) !== welcome.value.wait_max)) waitEdited.value = true
})
const waitError = computed(() => {
    const min = Number(waitMin.value)
    const max = Number(waitMax.value)
    if (!Number.isInteger(min) || !Number.isInteger(max) || min < 0 || max > 1800) return 'Durées en secondes, entre 0 et 1800.'
    if (min > max) return 'Le minimum doit être inférieur ou égal au maximum.'
    return null
})
const minutes = (sec) => {
    const n = Number(sec)
    if (!Number.isFinite(n)) return ''
    const m = Math.floor(n / 60)
    const r = n % 60
    return m ? `${m} min${r ? ` ${String(r).padStart(2, '0')}` : ''}` : `${r} s`
}

function saveWait() {
    if (waitError.value) return
    const min = Number(waitMin.value)
    const max = Number(waitMax.value)
    run(() => send('PUT', '/data/sip/welcome', { wait_min: min, wait_max: max }), `Attente forcée : entre ${minutes(min)} et ${minutes(max)}`).then((ok) => {
        if (ok) waitEdited.value = false
    })
}
function resetWait() {
    waitMin.value = welcome.value?.wait_min
    waitMax.value = welcome.value?.wait_max
    waitEdited.value = false
}

// ─── Répartition du trafic entre notre serveur et Voxnode (voxnode118) ───────
const tel = computed(() => (telephony.value?.ok ? telephony.value.data : null))
const telSettings = computed(() => tel.value?.settings ?? null)
const saveTelephony = (payload, success) => run(() => send('PUT', '/data/telephony/settings', payload), success)

function toggleSelfhosted(enabled) {
    const message = enabled
        ? "Activer notre serveur ?\n\nIl recevra la part du trafic réglée ci-dessous."
        : "Désactiver notre serveur ?\n\nSes numéros repartent immédiatement chez Voxnode. Ses agents partent progressivement : le dernier attend qu'il ne reste plus aucun client ni numéro (au moins 3 min 30)."
    if (!confirm(message)) return
    saveTelephony({ selfhosted_enabled: enabled }, enabled ? 'Notre serveur est activé' : 'Notre serveur est désactivé')
}

// Réglages numériques de l'API téléphonie (bornes = celles de l'API), modifiés ici et enregistrés au clic.
// Un champ n'apparaît que si l'API le renvoie : régulateur et appelants prioritaires arrivent avec le déploiement de voxnode118.
function numberForm(fields) {
    const values = reactive({})
    const edited = ref(false)
    const available = computed(() => Object.keys(fields).filter((k) => telSettings.value && k in telSettings.value))
    const differ = () => available.value.some((k) => Number(values[k]) !== telSettings.value[k])

    // Tant qu'on ne les a pas modifiées ici, les valeurs suivent l'API
    watch(telSettings, (st) => {
        if (st && !edited.value) for (const k of available.value) values[k] = st[k]
    }, { immediate: true })
    watch(values, () => {
        if (telSettings.value && differ()) edited.value = true
    }, { deep: true })

    const dirty = computed(() => edited.value && !!telSettings.value && differ())
    const error = computed(() => {
        for (const k of available.value) {
            const v = Number(values[k])
            if (!Number.isInteger(v) || v < fields[k].min || v > fields[k].max) return fields[k].error
        }
        return null
    })
    async function save() {
        if (error.value) return
        // Seulement les valeurs modifiées
        const payload = Object.fromEntries(available.value.filter((k) => Number(values[k]) !== telSettings.value[k]).map((k) => [k, Number(values[k])]))
        if (await saveTelephony(payload, 'Réglages enregistrés')) edited.value = false
    }
    function reset() {
        for (const k of available.value) values[k] = telSettings.value[k]
        edited.value = false
    }
    return reactive({ values, available, dirty, error, save, reset })
}

const traffic = numberForm({
    selfhosted_percent: { min: 0, max: 100, error: 'Part du trafic : un nombre entier entre 0 et 100.' },
    pbx_recovery_minutes: { min: 1, max: 120, error: 'Délai de retour : entre 1 et 120 minutes.' },
    regulator_max_moves: { min: 1, max: 10, error: 'Agents déplacés par cycle : entre 1 et 10.' },
    regulator_cooldown_minutes: { min: 0, max: 60, error: 'Délai entre deux déplacements : entre 0 et 60 minutes.' },
    regulator_wait_seconds: { min: 0, max: 600, error: "Seuil d'attente : entre 0 et 600 secondes." },
})
const hasRegulator = computed(() => traffic.available.includes('regulator_max_moves'))

// Appelants prioritaires : blacklist, ou appel récent assez long (attente comprise) → pas d'attente forcée
const priority = numberForm({
    priority_min_duration: { min: 1, max: 3600, error: "Durée minimale d'appel : entre 1 et 3600 secondes." },
    priority_window_hours: { min: 1, max: 720, error: 'Période : entre 1 et 720 heures.' },
})
const priorityText = computed(() => {
    const st = telSettings.value
    return st && 'priority_min_duration' in st
        ? `appel d'au moins ${minutes(st.priority_min_duration)} dans les ${st.priority_window_hours} dernières heures`
        : "appel d'au moins 45 s dans les 72 dernières heures"
})

// ─── Horaires d'ouverture ─────────────────────────────────────────────────────
// La semaine entière est envoyée à chaque fois ; un jour fermé vaut null.
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/
const hoursWanted = computed(() => telSettings.value?.opening_hours ?? null)
const hoursApplied = computed(() => data.value?.['opening-hours']?.data ?? null)
const hoursForm = reactive({}) // jour → { closed, open, close }
const hoursEdited = ref(false)
const today = todayKey()

function fillHours(week) {
    for (const [day] of DAYS) {
        const h = week[day]
        // Un jour fermé garde les dernières heures saisies, pour pouvoir le rouvrir d'un clic
        hoursForm[day] = { closed: !h, open: h?.open ?? hoursForm[day]?.open ?? '08:00', close: h?.close ?? hoursForm[day]?.close ?? '20:00' }
    }
}
watch(hoursWanted, (w) => {
    if (w && !hoursEdited.value) fillHours(w)
}, { immediate: true })

const hoursPayload = computed(() => Object.fromEntries(DAYS.map(([day]) => [day, hoursForm[day]?.closed ? null : { open: hoursForm[day]?.open, close: hoursForm[day]?.close }])))
watch(hoursForm, () => {
    if (hoursWanted.value && !sameWeek(hoursPayload.value, hoursWanted.value)) hoursEdited.value = true
}, { deep: true })
const hoursDirty = computed(() => hoursEdited.value && !!hoursWanted.value && !sameWeek(hoursPayload.value, hoursWanted.value))
const dayChanged = (day) => !!hoursWanted.value && formatDay(hoursPayload.value[day]) !== formatDay(hoursWanted.value[day])
const hoursError = computed(() => {
    for (const [day, label] of DAYS) {
        const h = hoursPayload.value[day]
        if (!h) continue
        if (!TIME.test(h.open ?? '') || !TIME.test(h.close ?? '')) return `${label} : heures au format HH:MM (23:59 au plus tard).`
        if (h.open >= h.close) return `${label} : l'ouverture doit précéder la fermeture.`
    }
    return null
})

async function saveHours() {
    if (hoursError.value) return
    const changed = DAYS.filter(([day]) => dayChanged(day))
    const message = changed.length === 1 ? `${changed[0][1]} : ${formatDay(hoursPayload.value[changed[0][0]])}` : 'Horaires enregistrés'
    const ok = await run(async () => {
        await send('PUT', '/data/telephony/settings', { opening_hours: hoursPayload.value })
        markOpeningHoursSaved()
    }, `${message}, appliqué par le serveur SIP sous 2 minutes`)
    if (ok) {
        hoursEdited.value = false
        watchHoursCopy()
    }
}
function resetHours() {
    fillHours(hoursWanted.value)
    hoursEdited.value = false
}

// Copie sur le serveur SIP : relue toutes les 15 s tant qu'elle n'a pas suivi l'enregistrement
const now = ref(Date.now())
const hoursProblem = computed(() => openingHoursProblem(hoursWanted.value, hoursApplied.value, now.value))
let hoursTimer = null
function watchHoursCopy() {
    clearInterval(hoursTimer)
    hoursTimer = setInterval(async () => {
        now.value = Date.now()
        await load()
        if (hoursProblem.value?.level !== 'pending') clearInterval(hoursTimer)
    }, 15000)
}
const appliedDay = (day) => (hoursApplied.value?.days ? formatDay(hoursApplied.value.days[day]) : '—')
const appliedDiffers = (day) => !!hoursWanted.value && appliedDay(day) !== formatDay(hoursWanted.value[day])

// Coupures d'urgence. L'API refuse de couper les deux plateformes, ou Voxnode quand notre serveur est désactivé.
function cutBlockedReason(platform) {
    const st = telSettings.value
    if (!st) return ''
    const other = platform === 'selfhosted' ? 'voxnode' : 'selfhosted'
    if (st[`${other}_manual_off`]) return "Impossible : l'autre plateforme est déjà coupée"
    if (platform === 'voxnode' && !st.selfhosted_enabled) return 'Impossible : notre serveur est désactivé'
    return ''
}
function setManualOff(platform, off) {
    const key = platform === 'selfhosted' ? 'selfhosted_manual_off' : 'voxnode_manual_off'
    const messages = {
        'selfhosted-on': "COUPER NOTRE SERVEUR ?\n\nSes numéros repartent immédiatement chez Voxnode. Ses agents partent progressivement : le dernier attend qu'il ne reste plus aucun client ni numéro (au moins 3 min 30).",
        'selfhosted-off': 'Rétablir notre serveur ?\n\nIl recevra de nouveau sa part du trafic.',
        'voxnode-on': "COUPER VOXNODE ?\n\nLes numéros dont le provider est autorisé chez nous arrivent chez nous, ainsi que tous les agents automatiques.",
        'voxnode-off': "Rétablir Voxnode ?\n\nNotre serveur ne garde que la part de trafic réglée ; les autres numéros et agents repartent progressivement.",
    }
    if (!confirm(messages[`${platform}-${off ? 'on' : 'off'}`])) return
    const name = platform === 'selfhosted' ? 'Notre serveur' : 'Voxnode'
    saveTelephony({ [key]: off }, off ? `${name} est coupé` : `${name} est rétabli`)
}

// Mode des agents
const PIN_OPTIONS = [
    { value: '', label: 'Automatique' },
    { value: 'selfhosted', label: 'Toujours notre serveur' },
    { value: 'voxnode', label: 'Toujours Voxnode' },
]
const PLATFORMS = { selfhosted: 'Notre serveur', voxnode: 'Voxnode' }
const telAgents = computed(() => tel.value?.agents ?? [])
// Pannes de notre serveur détectées par voxnode118 (contrôle toutes les 10 s)
const healthEvents = computed(() => [...(tel.value?.health?.events ?? [])].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')).slice(0, 10))
const uptimeText = computed(() => {
    const s = health.value.uptime_seconds
    if (s == null) return '—'
    const d = Math.floor(s / 86400)
    const h = Math.floor((s % 86400) / 3600)
    const m = Math.floor((s % 3600) / 60)
    return d ? `${d} j ${h} h` : h ? `${h} h ${m} min` : `${m} min`
})
const agentColumns = [
    { key: 'name', label: 'Agent' },
    { key: 'callcenter', label: 'Centre' },
    { key: 'platform', label: 'Plateforme actuelle' },
    { key: 'pin', label: 'Mode' },
]
function setPin(agent, value) {
    const pin = value || null
    const label = PIN_OPTIONS.find((o) => o.value === value)?.label
    run(() => send('PUT', `/data/telephony/agents/${agent.id}`, { pin }), `${agent.name || agent.username} : ${label}`)
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
                <!-- ─── Horaires d'ouverture (voxnode118, recopiés par le serveur SIP) ─ -->
                <div v-if="hoursWanted">
                    <h2 class="text-base font-semibold text-gray-900">Horaires d'ouverture</h2>
                    <p class="mb-4 mt-0.5 text-sm text-gray-500">
                        Heure de Paris, de l'ouverture incluse à la fermeture exclue. En dehors, les nouveaux appels sont refusés sans décrocher
                        (l'appelant ne paie rien) ; un appel en cours n'est jamais coupé. Appliqué par le serveur SIP sous 2 minutes.
                    </p>

                    <div
                        v-if="hoursProblem"
                        class="mb-4 flex items-start gap-3 rounded-lg border p-4 text-sm"
                        :class="hoursProblem.level === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-gray-100 bg-gray-50 text-gray-600'"
                    >
                        <AlertTriangle v-if="hoursProblem.level === 'error'" class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>{{ hoursProblem.text }}</p>
                    </div>

                    <form class="rounded-lg border border-gray-100 bg-white" @submit.prevent="saveHours">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2 font-medium">Jour</th>
                                        <th class="px-4 py-2 font-medium">Fermé</th>
                                        <th class="px-4 py-2 font-medium">Ouverture</th>
                                        <th class="px-4 py-2 font-medium">Fermeture</th>
                                        <th class="px-4 py-2 text-right font-medium">Appliqué par le serveur SIP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="[day, label] in DAYS" :key="day" class="border-b border-gray-50 last:border-b-0" :class="dayChanged(day) ? 'bg-amber-50/60' : ''">
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <span class="font-medium text-gray-900">{{ label }}</span>
                                            <span v-if="day === today" class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">aujourd'hui</span>
                                        </td>
                                        <td class="px-4 py-2">
                                            <Checkbox :model-value="hoursForm[day].closed" :aria-label="`${label} fermé`" @update:model-value="hoursForm[day].closed = !!$event" />
                                        </td>
                                        <td class="px-4 py-2">
                                            <Input v-model="hoursForm[day].open" type="time" class="h-9 w-28 tabular-nums" :disabled="hoursForm[day].closed" :aria-label="`${label}, ouverture`" />
                                        </td>
                                        <td class="px-4 py-2">
                                            <Input v-model="hoursForm[day].close" type="time" max="23:59" class="h-9 w-28 tabular-nums" :disabled="hoursForm[day].closed" :aria-label="`${label}, fermeture`" />
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="appliedDiffers(day) ? 'font-medium text-red-600' : 'text-gray-500'">
                                            {{ appliedDay(day) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="hoursError || hoursDirty" class="flex items-center justify-end gap-3 border-t border-gray-100 px-4 py-3">
                            <span v-if="hoursError" class="mr-auto text-xs text-red-600">{{ hoursError }}</span>
                            <template v-if="hoursDirty">
                                <Button type="button" variant="outline" size="sm" :disabled="busy" @click="resetHours">Annuler</Button>
                                <Button type="submit" size="sm" :disabled="busy || !!hoursError">Enregistrer</Button>
                            </template>
                        </div>
                    </form>
                </div>

                <!-- ─── Répartition du trafic (voxnode118) ────────────────── -->
                <div v-if="telephony?.configured">
                    <h2 class="text-base font-semibold text-gray-900">Répartition du trafic</h2>
                    <p class="mb-4 mt-0.5 text-sm text-gray-500">Entre notre serveur et Voxnode. S'applique aux nouvelles assignations de numéros et aux agents automatiques.</p>

                    <p v-if="telephony.ok === false" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        API téléphonie indisponible : {{ telephony.error }}
                    </p>
                    <div v-else-if="telSettings" class="space-y-4">
                        <div class="rounded-lg border border-gray-100 bg-white">
                            <!-- Interrupteur général -->
                            <div class="flex items-center justify-between gap-4 border-b border-gray-50 px-4 py-3">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">Notre serveur reçoit du trafic</div>
                                    <div class="text-xs text-gray-500">Désactivé : ses numéros repartent tout de suite chez Voxnode, ses agents progressivement.</div>
                                </div>
                                <Switch :checked="telSettings.selfhosted_enabled" :disabled="busy" @update:checked="toggleSelfhosted" />
                            </div>
                            <!-- Part du trafic et délai de retour -->
                            <form class="space-y-4 px-4 py-3" @submit.prevent="traffic.save()">
                                <div class="flex flex-wrap items-end gap-4">
                                    <div class="space-y-1.5">
                                        <Label for="tel-percent">Part du trafic chez nous (%)</Label>
                                        <Input id="tel-percent" v-model.number="traffic.values.selfhosted_percent" type="number" min="0" max="100" class="h-9 w-32 tabular-nums" />
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label for="tel-recovery">Retour après une panne (min)</Label>
                                        <Input id="tel-recovery" v-model.number="traffic.values.pbx_recovery_minutes" type="number" min="1" max="120" class="h-9 w-32 tabular-nums" />
                                    </div>
                                    <p class="pb-2 text-xs text-gray-500">
                                        Baisser la part : on ne garde que la part demandée, les agents partent progressivement.
                                        Après une panne, retour après {{ traffic.values.pbx_recovery_minutes }} min de bonne santé.
                                    </p>
                                </div>

                                <!-- Régulateur : déplace les agents automatiques toutes les 30 s -->
                                <div v-if="hasRegulator" class="border-t border-gray-50 pt-3">
                                    <div class="text-sm font-medium text-gray-900">Régulateur des agents</div>
                                    <div class="mb-2 text-xs text-gray-500">Toutes les 30 s, il déplace des agents automatiques vers la plateforme qui en a besoin.</div>
                                    <div class="flex flex-wrap items-end gap-4">
                                        <div class="space-y-1.5">
                                            <Label for="reg-moves">Agents déplacés par cycle</Label>
                                            <Input id="reg-moves" v-model.number="traffic.values.regulator_max_moves" type="number" min="1" max="10" class="h-9 w-32 tabular-nums" />
                                        </div>
                                        <div class="space-y-1.5">
                                            <Label for="reg-cooldown">Repos entre deux déplacements (min)</Label>
                                            <Input id="reg-cooldown" v-model.number="traffic.values.regulator_cooldown_minutes" type="number" min="0" max="60" class="h-9 w-32 tabular-nums" />
                                        </div>
                                        <div class="space-y-1.5">
                                            <Label for="reg-wait">Attente qui déclenche un renfort (s)</Label>
                                            <Input id="reg-wait" v-model.number="traffic.values.regulator_wait_seconds" type="number" min="0" max="600" class="h-9 w-32 tabular-nums" />
                                        </div>
                                        <p class="pb-2 text-xs text-gray-500">
                                            Un client qui attend plus de {{ traffic.values.regulator_wait_seconds }} s dans notre file compte comme un besoin d'agents.
                                        </p>
                                    </div>
                                </div>

                                <div v-if="traffic.error || traffic.dirty" class="flex items-center justify-end gap-3">
                                    <span v-if="traffic.error" class="mr-auto text-xs text-red-600">{{ traffic.error }}</span>
                                    <template v-if="traffic.dirty">
                                        <Button type="button" variant="outline" size="sm" :disabled="busy" @click="traffic.reset()">Annuler</Button>
                                        <Button type="submit" size="sm" :disabled="busy || !!traffic.error">Enregistrer</Button>
                                    </template>
                                </div>
                            </form>
                        </div>

                        <!-- Coupures d'urgence -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <div
                                v-for="p in ['selfhosted', 'voxnode']"
                                :key="p"
                                class="flex items-center justify-between gap-4 rounded-lg border p-4"
                                :class="telSettings[`${p}_manual_off`] ? 'border-red-200 bg-red-50' : 'border-gray-100 bg-white'"
                            >
                                <div>
                                    <div class="text-sm font-medium" :class="telSettings[`${p}_manual_off`] ? 'text-red-700' : 'text-gray-900'">
                                        {{ PLATFORMS[p] }} : {{ telSettings[`${p}_manual_off`] ? 'coupé' : 'actif' }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ p === 'selfhosted'
                                            ? 'Couper : ses numéros repartent tout de suite chez Voxnode, ses agents progressivement (au moins 3 min 30).'
                                            : 'Couper : les numéros autorisés chez nous et tous les agents automatiques viennent chez nous.' }}
                                    </div>
                                </div>
                                <Button
                                    v-if="telSettings[`${p}_manual_off`]"
                                    size="sm"
                                    variant="outline"
                                    :disabled="busy"
                                    @click="setManualOff(p, false)"
                                >
                                    Rétablir
                                </Button>
                                <Button
                                    v-else
                                    size="sm"
                                    variant="outline"
                                    class="border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800"
                                    :disabled="busy || !!cutBlockedReason(p)"
                                    :title="cutBlockedReason(p)"
                                    @click="setManualOff(p, true)"
                                >
                                    Couper
                                </Button>
                            </div>
                        </div>

                        <!-- Mode des agents -->
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Mode des agents</h3>
                            <p class="mb-3 mt-0.5 text-sm text-gray-500">
                                Automatique : l'agent suit la répartition et les bascules (après un retour en automatique, le régulateur attend le délai de repos avant de le déplacer).
                                Sinon il reste toujours sur la même plateforme, même en cas de panne.
                            </p>
                            <div class="rounded-lg border border-gray-100 bg-white">
                                <DataTable :columns="agentColumns" :rows="telAgents" :loading="loading" empty-message="Aucun agent">
                                    <template #name="{ row }">
                                        <span class="font-medium text-gray-900">{{ row.name || row.username }}</span>
                                        <span v-if="row.name && row.username" class="ml-2 font-mono text-xs text-gray-400">{{ row.username }}</span>
                                    </template>
                                    <template #callcenter="{ value }"><span class="text-sm text-gray-600">{{ value || '—' }}</span></template>
                                    <template #platform="{ row }">
                                        <span class="text-sm text-gray-600">{{ PLATFORMS[row.current_platform] ?? '—' }}</span>
                                        <span v-if="row.switch_failed" class="ml-2 text-xs text-red-600">bascule ratée le {{ new Date(row.switch_failed_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
                                    </template>
                                    <template #pin="{ row }">
                                        <select
                                            :value="row.pin ?? ''"
                                            class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm"
                                            :disabled="busy"
                                            @change="setPin(row, $event.target.value)"
                                        >
                                            <option v-for="o in PIN_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                                        </select>
                                    </template>
                                </DataTable>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── Accueil des appels entrants ───────────────────────── -->
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Accueil des appels</h2>
                    <p class="mb-4 mt-0.5 text-sm text-gray-500">
                        Chaque appel entrant entend l'introduction une fois, puis patiente (attente forcée) avec l'audio d'attente en boucle,
                        jusqu'à ce qu'un agent décroche. Les appelants prioritaires sautent l'attente forcée.
                    </p>
                    <div class="space-y-6">
                        <SipSoundSequence
                            title="1. Introduction"
                            description="Jouée une fois au début de chaque appel, dans cet ordre."
                            :names="sequences.intro"
                            :library="sounds"
                            empty-text="Pas d'introduction : l'appel passe directement à l'attente."
                            :playing="playing"
                            :busy="busy"
                            :loading="loading"
                            @change="(list, message) => saveSequence('intro', list, message)"
                            @add="openSoundDialog('intro')"
                            @toggle-play="toggleSound"
                        />

                        <!-- Attente forcée -->
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">2. Attente forcée</h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                Durée tirée au hasard entre ces deux valeurs à chaque appel. Les appelants prioritaires (blacklist, ou
                                {{ priorityText }}) ne la subissent pas et passent en tête de file.
                            </p>
                            <form class="mt-3 flex flex-wrap items-end gap-3 rounded-lg border border-gray-100 bg-white p-4" @submit.prevent="saveWait">
                                <div class="space-y-1.5">
                                    <Label for="wait-min">Minimum (secondes)</Label>
                                    <Input id="wait-min" v-model.number="waitMin" type="number" min="0" max="1800" class="h-9 w-32 tabular-nums" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="wait-max">Maximum (secondes)</Label>
                                    <Input id="wait-max" v-model.number="waitMax" type="number" min="0" max="1800" class="h-9 w-32 tabular-nums" />
                                </div>
                                <p class="pb-2 text-sm text-gray-500">
                                    <template v-if="!waitError">soit entre {{ minutes(waitMin) }} et {{ minutes(waitMax) }}</template>
                                    <span v-else class="text-red-600">{{ waitError }}</span>
                                </p>
                                <div v-if="waitDirty" class="ml-auto flex gap-2">
                                    <Button type="button" variant="outline" size="sm" :disabled="busy" @click="resetWait">Annuler</Button>
                                    <Button type="submit" size="sm" :disabled="busy || !!waitError">Enregistrer</Button>
                                </div>
                            </form>

                            <!-- Appelants prioritaires (API téléphonie) -->
                            <form v-if="priority.available.length" class="mt-3 flex flex-wrap items-end gap-3 rounded-lg border border-gray-100 bg-white p-4" @submit.prevent="priority.save()">
                                <div class="space-y-1.5">
                                    <Label for="priority-duration">Appel prioritaire dès (secondes)</Label>
                                    <Input id="priority-duration" v-model.number="priority.values.priority_min_duration" type="number" min="1" max="3600" class="h-9 w-32 tabular-nums" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="priority-window">Sur les dernières (heures)</Label>
                                    <Input id="priority-window" v-model.number="priority.values.priority_window_hours" type="number" min="1" max="720" class="h-9 w-32 tabular-nums" />
                                </div>
                                <p class="pb-2 text-sm text-gray-500">
                                    <span v-if="priority.error" class="text-red-600">{{ priority.error }}</span>
                                    <template v-else>Durée totale de l'appel, attente comprise.</template>
                                </p>
                                <div v-if="priority.dirty" class="ml-auto flex gap-2">
                                    <Button type="button" variant="outline" size="sm" :disabled="busy" @click="priority.reset()">Annuler</Button>
                                    <Button type="submit" size="sm" :disabled="busy || !!priority.error">Enregistrer</Button>
                                </div>
                            </form>
                        </div>

                        <SipSoundSequence
                            title="3. Audio d'attente"
                            description="Joué en boucle pendant l'attente forcée, puis dans la file jusqu'à ce qu'un agent décroche."
                            :names="sequences.hold"
                            :library="sounds"
                            empty-text="Aucun son : musique d'attente par défaut du serveur."
                            :playing="playing"
                            :busy="busy"
                            :loading="loading"
                            @change="(list, message) => saveSequence('hold', list, message)"
                            @add="openSoundDialog('hold')"
                            @toggle-play="toggleSound"
                        />
                    </div>
                </div>

                <!-- ─── Service interne ───────────────────────────────────── -->
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Service interne</h2>
                    <p class="mb-4 mt-0.5 text-sm text-gray-500">Appels transférés vers le service interne ({{ INTERNAL_SERVICE_NUMBER }}).</p>
                    <SipSoundSequence
                        title="Sons joués"
                        description="L'appelant entend ces sons l'un après l'autre, en boucle."
                        :names="sequences.internal"
                        :library="sounds"
                        empty-text="Aucun son : musique d'attente par défaut du serveur."
                        :playing="playing"
                        :busy="busy"
                        :loading="loading"
                        @change="(list, message) => saveSequence('internal', list, message)"
                        @add="openSoundDialog('internal')"
                        @toggle-play="toggleSound"
                    />

                    <!-- Fichiers envoyés mais joués nulle part -->
                    <details v-if="unusedSounds.length" class="mt-4 text-sm">
                        <summary class="cursor-pointer text-gray-500 hover:text-gray-700">
                            {{ unusedSounds.length }} fichier{{ unusedSounds.length > 1 ? 's' : '' }} envoyé{{ unusedSounds.length > 1 ? 's' : '' }} mais joué{{ unusedSounds.length > 1 ? 's' : '' }} nulle part
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
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">En service depuis</dt><dd class="text-gray-900">{{ uptimeText }}</dd></div>
                            </dl>

                            <template v-if="healthEvents.length">
                                <h3 class="mb-2 mt-5 text-sm font-semibold text-gray-900">Dernières pannes</h3>
                                <ul class="space-y-1 text-sm">
                                    <li v-for="(e, i) in healthEvents" :key="i" class="flex gap-3">
                                        <span class="shrink-0 tabular-nums text-gray-400">{{ new Date(e.at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
                                        <span :class="e.state === 'down' ? 'text-red-600' : 'text-gray-700'">{{ e.state === 'down' ? 'Panne' : 'Rétabli' }}</span>
                                        <span class="min-w-0 truncate text-gray-500" :title="e.reason">{{ e.reason }}</span>
                                        <span v-if="e.simulated" class="text-gray-400">(simulé)</span>
                                    </li>
                                </ul>
                            </template>

                            <h3 class="mb-2 mt-5 text-sm font-semibold text-gray-900">Stockage</h3>
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Disque des enregistrements</dt><dd class="tabular-nums text-gray-900">{{ used('disk_data') }}</dd></div>
                                <template v-if="usage">
                                    <div class="flex justify-between gap-4 pl-4">
                                        <dt class="text-gray-500">Enregistrements d'appels</dt>
                                        <dd class="tabular-nums text-gray-900">{{ size(usage.recordings?.bytes) }} · {{ (usage.recordings?.files ?? 0).toLocaleString('fr-FR') }} fichier{{ usage.recordings?.files > 1 ? 's' : '' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4 pl-4">
                                        <dt class="text-gray-500">Plus ancien enregistrement</dt>
                                        <dd class="text-gray-900">{{ usage.recordings?.oldest_recording ? formatDate(usage.recordings.oldest_recording) : '—' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4 pl-4"><dt class="text-gray-500">Sons du service interne</dt><dd class="tabular-nums text-gray-900">{{ size(usage.sounds?.bytes) }}</dd></div>
                                    <div class="flex justify-between gap-4 pl-4"><dt class="text-gray-500">Autre</dt><dd class="tabular-nums text-gray-900">{{ size(usage.other?.bytes) }}</dd></div>
                                </template>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Disque système</dt><dd class="tabular-nums text-gray-900">{{ used('disk_root') }}</dd></div>
                            </dl>
                            <p v-if="usage?.computed_at" class="mt-3 text-xs text-gray-400">
                                Détail calculé à {{ new Date(usage.computed_at).toLocaleTimeString('fr-FR') }} (toutes les 5 min). Purge prévue : enregistrements agent après 120 j, transfert après 30 j, blacklist conservés.
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- ─── Fenêtre : ajouter un son à la lecture ─────────────────────── -->
        <Dialog v-model:open="showSoundDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ajouter un son {{ SEQUENCE_LABELS[soundTarget] }}</DialogTitle>
                    <DialogDescription>Il sera joué en dernier. L'ordre se change ensuite avec les flèches.</DialogDescription>
                </DialogHeader>
                <form id="sound-form" class="space-y-4" @submit.prevent="addSound">
                    <div class="flex gap-4 text-sm">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="soundSource" type="radio" value="upload" />
                            Nouveau fichier
                        </label>
                        <label class="flex items-center gap-2" :class="addableSounds.length ? 'cursor-pointer' : 'cursor-not-allowed text-gray-400'">
                            <input v-model="soundSource" type="radio" value="existing" :disabled="!addableSounds.length" />
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
                            <option v-for="s in addableSounds" :key="s.name" :value="s.name">{{ s.name }} ({{ seconds(s.duration) }})</option>
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
