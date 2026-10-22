<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import SipSoundSequence from '@/components/SipSoundSequence.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { AlertTriangle, ArrowLeft, Pause, Play, Plus, Trash2 } from 'lucide-vue-next'
import { xsrfToken } from '@/lib/csrf'
import { DAYS, formatDay, hoursHeadline, markOpeningHoursSaved, openingHoursProblem, sameWeek, todayKey } from '@/lib/openingHours'

/**
 * Configuration du serveur SIP : un onglet par sujet, un seul sujet à l'écran.
 *
 * Tous les onglets suivent le même modèle : une carte par groupe de réglages, une ligne par réglage
 * (libellé + explication à gauche, champ à droite), et un pied de carte « Annuler / Enregistrer »
 * qui n'apparaît que si quelque chose a changé. Les actions immédiates (état des plateformes)
 * sont séparées des réglages et passent par une fenêtre de confirmation qui dit la conséquence.
 *
 * Sources : API de voxnode118 (/data/telephony : trafic, horaires, priorités, régulateur)
 * et API de notre serveur (/data/sip : accueil, service interne, IP, stockage).
 */
const INTERNAL_SERVICE_NUMBER = '01 84 13 15 72'
const VOXNODE_IP = '13.36.113.246'

const data = ref(null)
const telephony = ref(null)
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
// Relue toutes les 15 s (états, horaires appliqués, régulateur) ; les champs en cours de saisie ne sont pas écrasés
let refreshTimer = null
onMounted(() => {
    load()
    refreshTimer = setInterval(() => {
        now.value = Date.now()
        if (document.visibilityState === 'visible' && !busy.value) load()
    }, 15000)
})
onUnmounted(() => {
    stopSound()
    clearInterval(hoursTimer)
    clearInterval(refreshTimer)
})

const configured = computed(() => data.value?.configured !== false)
const health = computed(() => data.value?.health?.data ?? {})
const tel = computed(() => (telephony.value?.ok ? telephony.value.data : null))
const telSettings = computed(() => tel.value?.settings ?? null)

const formatDate = (v) => (v ? new Date(v).toLocaleDateString('fr-FR') : '—')
const seconds = (v) => (v != null ? `${Number(v).toLocaleString('fr-FR')} s` : '—')
const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`
function minutes(sec) {
    const n = Number(sec)
    if (!Number.isFinite(n)) return ''
    const m = Math.floor(n / 60)
    const r = n % 60
    return m ? `${m} min${r ? ` ${String(r).padStart(2, '0')}` : ''}` : `${r} s`
}
function size(bytes) {
    if (bytes == null) return '—'
    const units = [['Go', 1e9], ['Mo', 1e6], ['Ko', 1e3]]
    const [unit, factor] = units.find(([, f]) => bytes >= f) ?? ['Ko', 1e3]
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: bytes >= 10 * factor ? 0 : 1 }).format(bytes / factor)} ${unit}`
}

// ─── Écritures : résultat affiché en haut, page relue après chaque action ─────
const notice = ref(null)
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
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(json.message || json.errors?.[Object.keys(json.errors)[0]]?.[0] || `Erreur HTTP ${res.status}`)
    return json
}

const busy = ref(false)
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

// Fenêtre de confirmation : ask({ title, body, confirm, danger }) → Promise<boolean>
const confirmState = ref(null)
function ask(options) {
    return new Promise((resolve) => (confirmState.value = { confirm: 'Confirmer', danger: false, ...options, resolve }))
}
function closeConfirm(answer) {
    confirmState.value?.resolve(answer)
    confirmState.value = null
}

// ─── Réglages numériques de l'API téléphonie ──────────────────────────────────
// Un groupe de champs = une carte = un enregistrement, qui n'envoie que les valeurs modifiées.
// Un champ absent de l'API (avant un déploiement de voxnode118) n'est pas affiché.
const saveTelephony = (payload, success) => run(() => send('PUT', '/data/telephony/settings', payload), success)

// Un champ vidé n'est pas un zéro : Number('') vaut 0, ce qui renverrait par exemple tous nos numéros chez Voxnode
const isEmpty = (v) => v === '' || v === null || v === undefined

// confirmBefore(payload) : confirmation éventuelle avant l'envoi (Promise<boolean>)
function numberForm(fields, confirmBefore = null) {
    const values = reactive({})
    const edited = ref(false)
    const available = computed(() => Object.keys(fields).filter((k) => telSettings.value && k in telSettings.value))
    const differ = () => available.value.some((k) => Number(values[k]) !== telSettings.value[k])

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
            if (isEmpty(values[k]) || !Number.isInteger(v) || v < fields[k].min || v > fields[k].max) return fields[k].error
        }
        return null
    })
    async function save() {
        if (error.value) return
        const payload = Object.fromEntries(available.value.filter((k) => Number(values[k]) !== telSettings.value[k]).map((k) => [k, Number(values[k])]))
        if (confirmBefore && !(await confirmBefore(payload))) return
        if (await saveTelephony(payload, 'Réglages enregistrés')) edited.value = false
    }
    function reset() {
        for (const k of available.value) values[k] = telSettings.value[k]
        edited.value = false
    }
    return reactive({ values, available, dirty, error, save, reset })
}

const trafficForm = numberForm(
    {
        selfhosted_percent: { min: 0, max: 100, error: 'Part des nouveaux numéros : un nombre entier entre 0 et 100.' },
        pbx_recovery_minutes: { min: 1, max: 120, error: 'Retour après une panne : entre 1 et 120 minutes.' },
    },
    // Baisser la part renvoie immédiatement des numéros chez Voxnode : on confirme
    (payload) =>
        'selfhosted_percent' in payload && payload.selfhosted_percent < telSettings.value.selfhosted_percent
            ? ask({
                  title: `Passer de ${telSettings.value.selfhosted_percent} % à ${payload.selfhosted_percent} % ?`,
                  body: 'Les numéros chez nous au-delà du nouveau pourcentage repartent immédiatement chez Voxnode ; les agents suivent progressivement.',
                  confirm: 'Baisser la part',
                  danger: true,
              })
            : Promise.resolve(true),
)
const priorityForm = numberForm({
    priority_min_duration: { min: 1, max: 3600, error: "Durée d'appel : entre 1 et 3600 secondes." },
    priority_window_hours: { min: 1, max: 720, error: 'Période : entre 1 et 720 heures.' },
})
const regulatorForm = numberForm({
    regulator_max_moves: { min: 1, max: 10, error: 'Agents déplacés : entre 1 et 10.' },
    regulator_cooldown_minutes: { min: 0, max: 60, error: 'Délai avant de redéplacer un agent : entre 0 et 60 minutes.' },
    regulator_wait_seconds: { min: 0, max: 600, error: "Seuil d'attente : entre 0 et 600 secondes." },
})

// ─── Trafic : état des plateformes (actions immédiates) ───────────────────────
// Notre serveur : Actif ou Désactivé. « Désactivé » (selfhosted_enabled) et « coupé à la main »
// (selfhosted_manual_off) ont exactement le même effet côté voxnode118 : un seul choix ici.
// Un ancien « coupé à la main » s'affiche donc comme Désactivé.
const ourState = computed(() => {
    const st = telSettings.value
    if (!st) return null
    return st.selfhosted_enabled && !st.selfhosted_manual_off ? 'on' : 'off'
})
const ourDown = computed(() => ourState.value === 'on' && !tel.value?.available?.selfhosted)
const ourStateText = computed(() => {
    const st = telSettings.value
    if (!st) return ''
    if (ourState.value === 'off') return `Ne reçoit aucun nouveau numéro ; ses agents partent progressivement chez Voxnode. En le réactivant, il reprend ${st.selfhosted_percent} % des nouveaux numéros.`
    if (ourDown.value) return `En panne : tout part chez Voxnode, retour automatique après ${st.pbx_recovery_minutes} min de bon fonctionnement.`
    return `Reçoit ${st.selfhosted_percent} % des nouveaux numéros ; le régulateur lui envoie des agents.`
})
// L'API refuse de désactiver notre serveur quand Voxnode est coupé
const ourOffBlocked = computed(() => (telSettings.value?.voxnode_manual_off ? 'Impossible : Voxnode est coupé' : ''))

async function setOurState(target) {
    if (target === ourState.value) return
    const st = telSettings.value
    const ok = await ask(
        target === 'on'
            ? { title: 'Activer notre serveur ?', body: `Il recevra ${st.selfhosted_percent} % des nouveaux numéros, et le régulateur lui enverra des agents.`, confirm: 'Activer' }
            : {
                  title: 'Désactiver notre serveur ?',
                  body: "Ses numéros repartent immédiatement chez Voxnode. Ses agents partent progressivement : le dernier attend qu'il ne reste plus aucun client ni numéro chez nous (au moins 3 min 30).",
                  confirm: 'Désactiver',
                  danger: true,
              },
    )
    if (!ok) return
    // Seulement les clés qui changent ; réactiver lève aussi une ancienne coupure à la main
    const payload = target === 'on' ? { ...(st.selfhosted_manual_off && { selfhosted_manual_off: false }), ...(!st.selfhosted_enabled && { selfhosted_enabled: true }) } : { selfhosted_enabled: false }
    saveTelephony(payload, target === 'on' ? 'Notre serveur est activé' : 'Notre serveur est désactivé')
}

// Voxnode : actif ou coupé. L'API refuse de couper les deux, ou Voxnode quand notre serveur n'est pas actif.
const voxnodeCutBlocked = computed(() => (ourState.value === 'off' ? 'Impossible : notre serveur est désactivé' : ''))
async function setVoxnodeCut(cut) {
    if (cut === !!telSettings.value?.voxnode_manual_off) return
    const ok = await ask(
        cut
            ? {
                  title: 'Couper Voxnode ?',
                  body: 'Les numéros dont le fournisseur est autorisé chez nous arrivent immédiatement chez nous, ainsi que tous les agents automatiques.',
                  confirm: 'Couper Voxnode',
                  danger: true,
              }
            : { title: 'Rétablir Voxnode ?', body: 'Notre serveur ne garde que sa part réglée : les autres numéros repartent immédiatement chez Voxnode, les agents progressivement.', confirm: 'Rétablir' },
    )
    if (ok) saveTelephony({ voxnode_manual_off: cut }, cut ? 'Voxnode est coupé' : 'Voxnode est rétabli')
}

// ─── Horaires d'ouverture : la semaine entière est envoyée, un jour fermé vaut null ─
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/
const hoursWanted = computed(() => telSettings.value?.opening_hours ?? null)
const hoursApplied = computed(() => data.value?.['opening-hours']?.data ?? null)
const hoursForm = reactive({})
const hoursEdited = ref(false)
const today = todayKey()

function fillHours(week) {
    for (const [day] of DAYS) {
        const h = week[day]
        // Un jour fermé garde les dernières heures saisies, pour le rouvrir d'un clic
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

// ─── Sons : introduction, audio d'attente, service interne ────────────────────
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

const sounds = computed(() => data.value?.sounds?.data?.sounds ?? [])
const welcome = computed(() => data.value?.welcome?.data ?? null)
const sequences = computed(() => ({
    internal: data.value?.sounds?.data?.playlist ?? [],
    intro: welcome.value?.intro ?? [],
    hold: welcome.value?.hold ?? [],
    transfer: welcome.value?.transfer_announcement ?? [],
}))
// Message avant transfert : affiché seulement si le serveur SIP le connaît
const hasTransferAnnouncement = computed(() => !!welcome.value && 'transfer_announcement' in welcome.value)
// Un fichier ne peut être supprimé que s'il n'est joué nulle part
const usedNames = computed(() => new Set(Object.values(sequences.value).flat()))
const unusedSounds = computed(() => sounds.value.filter((s) => !usedNames.value.has(s.name)))
const SEQUENCE_LABELS = { internal: 'au service interne', intro: "à l'introduction", hold: "à l'audio d'attente", transfer: 'au message avant transfert' }
// Nom de chaque liste dans l'API /welcome
const WELCOME_KEYS = { intro: 'intro', hold: 'hold', transfer: 'transfer_announcement' }

function sequenceRequest(target, list) {
    return target === 'internal' ? send('PUT', '/data/sip/playlist', { sounds: list }) : send('PUT', '/data/sip/welcome', { [WELCOME_KEYS[target]]: list })
}
const saveSequence = (target, list, success) => run(() => sequenceRequest(target, list), success)

async function deleteSound(name) {
    if (!(await ask({ title: `Supprimer « ${name} » ?`, body: 'Le fichier est supprimé définitivement du serveur.', confirm: 'Supprimer', danger: true }))) return
    run(() => send('DELETE', `/data/sip/sounds/${encodeURIComponent(name)}`), `Fichier « ${name} » supprimé`)
}

const showSoundDialog = ref(false)
const soundTarget = ref('internal')
const soundSource = ref('upload')
const soundFile = ref(null)
const soundName = ref('')
const existingName = ref('')
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
            // Le serveur SIP choisit le nom final (nettoyé + suffixe unique) : on reprend celui de sa réponse
            name = (await send('POST', '/data/sip/sounds', form)).sound.name
        }
        await sequenceRequest(target, [...sequences.value[target], name])
    }, `Son ajouté ${SEQUENCE_LABELS[target]}`)
    if (ok) showSoundDialog.value = false
}

// Attente forcée : durée tirée au hasard entre min et max à chaque appel
const waitMin = ref(null)
const waitMax = ref(null)
const waitEdited = ref(false)
const waitDiffers = () => !!welcome.value && (Number(waitMin.value) !== welcome.value.wait_min || Number(waitMax.value) !== welcome.value.wait_max)
const waitDirty = computed(() => waitEdited.value && waitDiffers())
watch(welcome, (w) => {
    if (w && !waitEdited.value) {
        waitMin.value = w.wait_min
        waitMax.value = w.wait_max
    }
}, { immediate: true })
watch([waitMin, waitMax], () => {
    if (waitDiffers()) waitEdited.value = true
})
const waitError = computed(() => {
    const min = Number(waitMin.value)
    const max = Number(waitMax.value)
    if (isEmpty(waitMin.value) || isEmpty(waitMax.value) || !Number.isInteger(min) || !Number.isInteger(max) || min < 0 || max > 1800) return 'Durées en secondes, entre 0 et 1800.'
    if (min > max) return 'Le minimum doit être inférieur ou égal au maximum.'
    return null
})
async function saveWait() {
    if (waitError.value) return
    const min = Number(waitMin.value)
    const max = Number(waitMax.value)
    if (await run(() => send('PUT', '/data/sip/welcome', { wait_min: min, wait_max: max }), `Attente forcée : entre ${minutes(min)} et ${minutes(max)}`)) waitEdited.value = false
}
function resetWait() {
    waitMin.value = welcome.value?.wait_min
    waitMax.value = welcome.value?.wait_max
    waitEdited.value = false
}

// ─── Fournisseurs : IP autorisées à nous envoyer des appels ───────────────────
const trustedIps = computed(() => data.value?.['trusted-ips']?.data?.ips ?? [])
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
async function removeIp(ip) {
    if (!(await ask({ title: `Retirer ${ip} ?`, body: 'Les appels venant de cette IP seront refusés immédiatement.', confirm: 'Retirer', danger: true }))) return
    run(() => send('DELETE', `/data/sip/trusted-ips/${encodeURIComponent(ip)}`), `${ip} retirée`)
}

// ─── Régulateur ───────────────────────────────────────────────────────────────
const lastRun = computed(() => tel.value?.regulator?.last_run ?? null)
const regulatorAge = computed(() => (lastRun.value?.at ? Math.max(0, Math.round((now.value - new Date(lastRun.value.at).getTime()) / 1000)) : null))
const regulatorStopped = computed(() => !!tel.value?.regulator && (regulatorAge.value == null || regulatorAge.value > 120))

// ─── Serveur et stockage (lecture seule) ──────────────────────────────────────
const version = computed(() => health.value.version?.match(/Asterisk ([\d.]+)/)?.[1] ?? '—')
const usage = computed(() => health.value.data_usage ?? null)
const uptimeText = computed(() => {
    const sec = health.value.uptime_seconds
    if (sec == null) return '—'
    const d = Math.floor(sec / 86400)
    const h = Math.floor((sec % 86400) / 3600)
    const m = Math.floor((sec % 3600) / 60)
    return d ? `${d} j ${h} h` : h ? `${h} h ${m} min` : `${m} min`
})
// Barre rouge au-delà de 90 % ou sous ces seuils d'espace libre (mêmes règles que la page Serveur SIP)
const DISKS = [
    { prefix: 'disk_root', label: 'Disque système', minFree: 5e9 },
    { prefix: 'disk_data', label: 'Disque des enregistrements', minFree: 20e9 },
]
const disks = computed(() =>
    DISKS.map(({ prefix, label, minFree }) => {
        const total = health.value[`${prefix}_total`]
        const used = health.value[`${prefix}_used`]
        const free = health.value[`${prefix}_free`]
        const pct = total ? used / total : null
        return { prefix, label, total, used, free, pct, low: (pct !== null && pct > 0.9) || (free != null && free < minFree) }
    }),
)
// Bucket 118records : recalculé une fois par jour (05:17), alerte au-delà de 26 h
const cloud = computed(() => {
    const u = health.value.s3_usage
    if (!u) return null
    const ageMin = u.computed_at ? Math.round((now.value - u.computed_at * 1000) / 60000) : null
    const ago = ageMin == null ? 'jamais calculé' : ageMin < 60 ? `calculé il y a ${ageMin} min` : `calculé il y a ${Math.floor(ageMin / 60)} h ${String(ageMin % 60).padStart(2, '0')}`
    return { bytes: u.bytes, objects: u.objects, ago, stale: ageMin == null || ageMin > 26 * 60 }
})

// ─── Onglets : le menu résume l'état de chaque sujet, point rouge en cas de problème ─
const tabs = computed(() => {
    const st = telSettings.value
    const list = []
    if (telephony.value?.configured) {
        let summary = '—'
        if (st) summary = ourState.value === 'on' ? (ourDown.value ? 'Notre serveur en panne' : `Actif · ${st.selfhosted_percent} %`) : 'Notre serveur désactivé'
        if (st?.voxnode_manual_off) summary = 'Voxnode coupé'
        list.push({ id: 'trafic', label: 'Trafic', summary, problem: telephony.value.ok === false || ourDown.value || !!st?.voxnode_manual_off })
    }
    if (hoursWanted.value) list.push({ id: 'horaires', label: 'Horaires', summary: hoursHeadline(hoursWanted.value)?.text, problem: hoursProblem.value?.level === 'error' })
    list.push({
        id: 'accueil',
        label: 'Accueil des appels',
        summary: welcome.value ? `Attente ${minutes(welcome.value.wait_min)} – ${minutes(welcome.value.wait_max)}` : '—',
        problem: false,
    })
    list.push({ id: 'service-interne', label: 'Service interne', summary: plural(sequences.value.internal.length, 'son'), problem: false })
    list.push({ id: 'fournisseurs', label: 'Fournisseurs', summary: `${trustedIps.value.length} IP autorisées`, problem: false })
    if (regulatorForm.available.length) list.push({ id: 'regulateur', label: 'Régulateur', summary: tel.value?.regulator ? (regulatorStopped.value ? 'Arrêté' : 'Tourne') : '—', problem: regulatorStopped.value })
    const storageLow = disks.value.some((d) => d.low) || !!cloud.value?.stale
    list.push({ id: 'serveur', label: 'Serveur et stockage', summary: storageLow ? 'Stockage à surveiller' : `Asterisk ${version.value}`, problem: storageLow })
    return list
})

// Onglet dans l'adresse (#horaires…) : les liens depuis la page Serveur SIP ouvrent le bon onglet
const tab = ref(typeof window !== 'undefined' ? window.location.hash.slice(1) : '')
const currentTab = computed(() => (tabs.value.some((t) => t.id === tab.value) ? tab.value : tabs.value[0]?.id))
function openTab(id) {
    tab.value = id
    history.replaceState(history.state, '', `#${id}`)
}
const onHash = () => (tab.value = window.location.hash.slice(1))
onMounted(() => window.addEventListener('hashchange', onHash))
onUnmounted(() => window.removeEventListener('hashchange', onHash))
</script>

<template>
    <Head title="Configuration du serveur SIP" />

    <AppLayout>
        <div class="space-y-5 p-6">
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

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Impossible de joindre le panel : {{ error }}</p>
            <div v-if="!configured" class="rounded-lg border border-gray-100 bg-white p-8 text-center text-sm text-gray-500">
                API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache.
            </div>

            <div v-else class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <!-- ─── Menu des onglets ──────────────────────────────────────── -->
                <nav class="min-w-0 lg:sticky lg:top-6">
                    <ul class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
                        <li v-for="t in tabs" :key="t.id" class="shrink-0">
                            <button
                                type="button"
                                class="w-full rounded-md px-3 py-2 text-left transition-colors"
                                :class="currentTab === t.id ? 'bg-white shadow-sm ring-1 ring-gray-100' : 'hover:bg-white/60'"
                                @click="openTab(t.id)"
                            >
                                <span class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                    {{ t.label }}
                                    <span v-if="t.problem" class="h-2 w-2 rounded-full bg-red-500" />
                                </span>
                                <span class="block truncate text-xs" :class="t.problem ? 'text-red-600' : 'text-gray-500'">{{ loading ? '…' : t.summary }}</span>
                            </button>
                        </li>
                    </ul>
                    <p class="mt-4 hidden px-3 text-xs text-gray-400 lg:block">Les changements s'appliquent immédiatement en production.</p>
                </nav>

                <!-- ─── Contenu de l'onglet ───────────────────────────────────── -->
                <div class="min-w-0 max-w-4xl space-y-5">
                    <div v-if="loading" class="h-64 animate-pulse rounded-lg bg-white" />

                    <!-- ═══ Trafic ═══ -->
                    <template v-else-if="currentTab === 'trafic'">
                        <p v-if="telephony?.ok === false" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">API téléphonie indisponible : {{ telephony.error }}</p>
                        <template v-else-if="telSettings">
                            <!-- Actions immédiates -->
                            <section class="rounded-lg border border-gray-100 bg-white">
                                <header class="border-b border-gray-100 px-5 py-3">
                                    <h2 class="font-semibold text-gray-900">État des plateformes</h2>
                                    <p class="text-sm text-gray-500">S'applique tout de suite, après confirmation.</p>
                                </header>
                                <div class="divide-y divide-gray-50">
                                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                                        <div class="text-sm font-medium text-gray-900">Notre serveur</div>
                                        <div>
                                            <div class="inline-flex rounded-md border border-gray-200 p-0.5">
                                                <button
                                                    v-for="(label, key) in { on: 'Actif', off: 'Désactivé' }"
                                                    :key="key"
                                                    type="button"
                                                    class="rounded px-3 py-1.5 text-sm transition-colors disabled:opacity-40"
                                                    :class="ourState === key ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                                    :disabled="busy || (key === 'off' && ourState === 'on' && !!ourOffBlocked)"
                                                    :title="key === 'off' && ourState === 'on' ? ourOffBlocked : ''"
                                                    @click="setOurState(key)"
                                                >
                                                    {{ label }}
                                                </button>
                                            </div>
                                            <p class="mt-2 text-sm" :class="ourDown ? 'text-red-600' : 'text-gray-500'">{{ ourStateText }}</p>
                                        </div>
                                    </div>
                                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                                        <div class="text-sm font-medium text-gray-900">Voxnode</div>
                                        <div>
                                            <div class="inline-flex rounded-md border border-gray-200 p-0.5">
                                                <button
                                                    type="button"
                                                    class="rounded px-3 py-1.5 text-sm transition-colors"
                                                    :class="!telSettings.voxnode_manual_off ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                                    :disabled="busy"
                                                    @click="setVoxnodeCut(false)"
                                                >
                                                    Actif
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded px-3 py-1.5 text-sm transition-colors disabled:opacity-40"
                                                    :class="telSettings.voxnode_manual_off ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-50'"
                                                    :disabled="busy || (!telSettings.voxnode_manual_off && !!voxnodeCutBlocked)"
                                                    :title="telSettings.voxnode_manual_off ? '' : voxnodeCutBlocked"
                                                    @click="setVoxnodeCut(true)"
                                                >
                                                    Coupé (urgence)
                                                </button>
                                            </div>
                                            <p class="mt-2 text-sm" :class="telSettings.voxnode_manual_off ? 'text-red-600' : 'text-gray-500'">
                                                {{ telSettings.voxnode_manual_off
                                                    ? 'Coupé à la main : tout ce qui peut venir chez nous y est.'
                                                    : voxnodeCutBlocked
                                                      ? `Reçoit le reste du trafic. ${voxnodeCutBlocked.replace('Impossible : ', 'Ne peut pas être coupé : ')}.`
                                                      : 'Reçoit le reste du trafic.' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Réglages -->
                            <form class="rounded-lg border border-gray-100 bg-white" @submit.prevent="trafficForm.save()">
                                <header class="border-b border-gray-100 px-5 py-3">
                                    <h2 class="font-semibold text-gray-900">Répartition</h2>
                                </header>
                                <div class="divide-y divide-gray-50">
                                    <div class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[1fr_auto]">
                                        <div>
                                            <Label for="percent" class="text-gray-900">Part des nouveaux numéros envoyée chez nous</Label>
                                            <p class="text-sm text-gray-500">La baisser : les numéros en trop repartent immédiatement chez Voxnode, les agents progressivement.</p>
                                        </div>
                                        <div class="flex items-center gap-2 text-sm text-gray-600">
                                            <Input id="percent" v-model.number="trafficForm.values.selfhosted_percent" type="number" min="0" max="100" class="h-9 w-24 text-right tabular-nums" />
                                            %
                                        </div>
                                    </div>
                                    <div class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[1fr_auto]">
                                        <div>
                                            <Label for="recovery" class="text-gray-900">Retour après une panne</Label>
                                            <p class="text-sm text-gray-500">Temps de bon fonctionnement avant de renvoyer du trafic sur notre serveur.</p>
                                        </div>
                                        <div class="flex items-center gap-2 text-sm text-gray-600">
                                            <Input id="recovery" v-model.number="trafficForm.values.pbx_recovery_minutes" type="number" min="1" max="120" class="h-9 w-24 text-right tabular-nums" />
                                            min
                                        </div>
                                    </div>
                                </div>
                                <footer v-if="trafficForm.dirty || trafficForm.error" class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                                    <span v-if="trafficForm.error" class="mr-auto text-sm text-red-600">{{ trafficForm.error }}</span>
                                    <template v-if="trafficForm.dirty">
                                        <Button type="button" variant="outline" size="sm" :disabled="busy" @click="trafficForm.reset()">Annuler</Button>
                                        <Button type="submit" size="sm" :disabled="busy || !!trafficForm.error">Enregistrer</Button>
                                    </template>
                                </footer>
                            </form>

                            <Link href="/sip-server#modes" class="block rounded-lg border border-gray-100 bg-white px-5 py-3 text-sm hover:bg-gray-50">
                                <span class="font-medium text-gray-900">Mode des agents →</span>
                                <span class="ml-2 text-gray-500">forcer un agent sur une plateforme (page Serveur SIP)</span>
                            </Link>
                        </template>
                    </template>

                    <!-- ═══ Horaires ═══ -->
                    <form v-else-if="currentTab === 'horaires'" class="rounded-lg border border-gray-100 bg-white" @submit.prevent="saveHours">
                        <header class="border-b border-gray-100 px-5 py-3">
                            <h2 class="font-semibold text-gray-900">Horaires d'ouverture</h2>
                            <p class="text-sm text-gray-500">
                                Heure de Paris. En dehors des horaires, les nouveaux appels sont refusés sans décrocher (l'appelant ne paie rien) ; un appel en cours n'est jamais coupé.
                            </p>
                        </header>
                        <p
                            v-if="hoursProblem"
                            class="flex items-start gap-2 border-b px-5 py-3 text-sm"
                            :class="hoursProblem.level === 'error' ? 'border-red-100 bg-red-50 text-red-700' : 'border-gray-100 bg-gray-50 text-gray-600'"
                        >
                            <AlertTriangle v-if="hoursProblem.level === 'error'" class="mt-0.5 h-4 w-4 shrink-0" />
                            {{ hoursProblem.text }}
                        </p>
                        <div class="overflow-x-auto px-5 py-2">
                            <table class="text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-gray-500">
                                        <th class="py-2 pr-6 font-normal">Jour</th>
                                        <th class="py-2 pr-6 font-normal">Fermé</th>
                                        <th class="py-2 pr-3 font-normal">De</th>
                                        <th class="py-2 pr-8 font-normal">À</th>
                                        <th class="py-2 font-normal">Appliqué par le serveur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="[day, label] in DAYS" :key="day" :class="dayChanged(day) ? 'bg-amber-50/70' : ''">
                                        <td class="py-1.5 pr-6">
                                            <span class="font-medium text-gray-900">{{ label }}</span>
                                            <span v-if="day === today" class="ml-2 text-xs text-gray-400">aujourd'hui</span>
                                        </td>
                                        <td class="py-1.5 pr-6">
                                            <Checkbox :model-value="hoursForm[day].closed" :aria-label="`${label} fermé`" @update:model-value="hoursForm[day].closed = !!$event" />
                                        </td>
                                        <td class="py-1.5 pr-3">
                                            <Input v-model="hoursForm[day].open" type="time" class="h-8 w-28 tabular-nums" :disabled="hoursForm[day].closed" :aria-label="`${label}, ouverture`" />
                                        </td>
                                        <td class="py-1.5 pr-8">
                                            <Input v-model="hoursForm[day].close" type="time" max="23:59" class="h-8 w-28 tabular-nums" :disabled="hoursForm[day].closed" :aria-label="`${label}, fermeture`" />
                                        </td>
                                        <td class="py-1.5 tabular-nums" :class="appliedDiffers(day) ? 'font-medium text-red-600' : 'text-gray-400'">{{ appliedDay(day) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <footer v-if="hoursDirty || hoursError" class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                            <span v-if="hoursError" class="mr-auto text-sm text-red-600">{{ hoursError }}</span>
                            <template v-if="hoursDirty">
                                <span v-if="!hoursError" class="mr-auto text-sm text-gray-500">Appliqué par le serveur SIP sous 2 minutes.</span>
                                <Button type="button" variant="outline" size="sm" :disabled="busy" @click="resetHours">Annuler</Button>
                                <Button type="submit" size="sm" :disabled="busy || !!hoursError">Enregistrer</Button>
                            </template>
                        </footer>
                    </form>

                    <!-- ═══ Accueil des appels : le parcours de l'appelant, étape par étape ═══ -->
                    <template v-else-if="currentTab === 'accueil'">
                        <section class="rounded-lg border border-gray-100 bg-white">
                            <header class="border-b border-gray-100 px-5 py-3">
                                <h2 class="font-semibold text-gray-900">Parcours de l'appelant</h2>
                                <p class="text-sm text-gray-500">Ce qu'entend chaque appel entrant, dans l'ordre : jusqu'à ce qu'un agent décroche, puis s'il est transféré.</p>
                            </header>
                            <ol class="px-5 py-2">
                                <li class="relative border-l border-gray-200 pb-6 pl-6 pt-3">
                                    <span class="absolute -left-3 top-3 flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-medium text-white">1</span>
                                    <SipSoundSequence
                                        title="Introduction"
                                        description="Jouée une fois au début de l'appel, dans cet ordre."
                                        :names="sequences.intro"
                                        :library="sounds"
                                        empty-text="Pas d'introduction : l'appel passe directement à l'attente."
                                        :playing="playing"
                                        :busy="busy"
                                        @change="(list, message) => saveSequence('intro', list, message)"
                                        @add="openSoundDialog('intro')"
                                        @toggle-play="toggleSound"
                                    />
                                </li>
                                <li class="relative border-l border-gray-200 pb-6 pl-6 pt-1">
                                    <span class="absolute -left-3 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-medium text-white">2</span>
                                    <h3 class="text-sm font-semibold text-gray-900">Attente forcée</h3>
                                    <p class="mt-0.5 text-sm text-gray-500">Durée tirée au hasard à chaque appel. Les appelants prioritaires (ci-dessous) ne la font pas.</p>
                                    <form class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 px-4 py-3 text-sm text-gray-700" @submit.prevent="saveWait">
                                        Entre
                                        <Input v-model.number="waitMin" type="number" min="0" max="1800" class="h-8 w-20 text-right tabular-nums" aria-label="Minimum en secondes" />
                                        et
                                        <Input v-model.number="waitMax" type="number" min="0" max="1800" class="h-8 w-20 text-right tabular-nums" aria-label="Maximum en secondes" />
                                        secondes
                                        <span class="text-gray-400" :class="waitError && 'text-red-600'">{{ waitError || `(${minutes(waitMin)} à ${minutes(waitMax)})` }}</span>
                                        <span v-if="waitDirty" class="ml-auto flex gap-2">
                                            <Button type="button" variant="outline" size="sm" :disabled="busy" @click="resetWait">Annuler</Button>
                                            <Button type="submit" size="sm" :disabled="busy || !!waitError">Enregistrer</Button>
                                        </span>
                                    </form>
                                </li>
                                <li class="relative pb-6 pl-6 pt-1" :class="hasTransferAnnouncement && 'border-l border-gray-200'">
                                    <span class="absolute -left-3 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-medium text-white">3</span>
                                    <SipSoundSequence
                                        title="Audio d'attente"
                                        description="Joué en boucle pendant l'attente forcée, puis dans la file jusqu'à ce qu'un agent décroche."
                                        :names="sequences.hold"
                                        :library="sounds"
                                        empty-text="Aucun son : musique d'attente par défaut du serveur."
                                        :playing="playing"
                                        :busy="busy"
                                        @change="(list, message) => saveSequence('hold', list, message)"
                                        @add="openSoundDialog('hold')"
                                        @toggle-play="toggleSound"
                                    />
                                </li>
                                <li v-if="hasTransferAnnouncement" class="relative pb-3 pl-6 pt-1">
                                    <span class="absolute -left-3 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-medium text-white">4</span>
                                    <SipSoundSequence
                                        title="Message avant transfert"
                                        description="Joué en entier au client quand l'agent transfère vers une marque (l'agent est libéré tout de suite), puis transfert. Activé par marque (colonne transfer_announcement), désactivé pour le service interne."
                                        :names="sequences.transfer"
                                        :library="sounds"
                                        empty-text="Aucun message : le transfert est immédiat."
                                        :playing="playing"
                                        :busy="busy"
                                        @change="(list, message) => saveSequence('transfer', list, message)"
                                        @add="openSoundDialog('transfer')"
                                        @toggle-play="toggleSound"
                                    />
                                </li>
                            </ol>
                        </section>

                        <form v-if="priorityForm.available.length" class="rounded-lg border border-gray-100 bg-white" @submit.prevent="priorityForm.save()">
                            <header class="border-b border-gray-100 px-5 py-3">
                                <h2 class="font-semibold text-gray-900">Appelants prioritaires</h2>
                                <p class="text-sm text-gray-500">Ils sautent l'attente forcée et passent en tête de file.</p>
                            </header>
                            <p class="flex flex-wrap items-center gap-2 px-5 py-4 text-sm text-gray-700">
                                Les blacklistés, et ceux qui ont eu un appel d'au moins
                                <Input v-model.number="priorityForm.values.priority_min_duration" type="number" min="1" max="3600" class="h-8 w-20 text-right tabular-nums" aria-label="Durée minimale d'appel en secondes" />
                                s (attente comprise) dans les
                                <Input v-model.number="priorityForm.values.priority_window_hours" type="number" min="1" max="720" class="h-8 w-20 text-right tabular-nums" aria-label="Période en heures" />
                                dernières heures.
                            </p>
                            <footer v-if="priorityForm.dirty || priorityForm.error" class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                                <span v-if="priorityForm.error" class="mr-auto text-sm text-red-600">{{ priorityForm.error }}</span>
                                <template v-if="priorityForm.dirty">
                                    <Button type="button" variant="outline" size="sm" :disabled="busy" @click="priorityForm.reset()">Annuler</Button>
                                    <Button type="submit" size="sm" :disabled="busy || !!priorityForm.error">Enregistrer</Button>
                                </template>
                            </footer>
                        </form>
                    </template>

                    <!-- ═══ Service interne ═══ -->
                    <section v-else-if="currentTab === 'service-interne'" class="rounded-lg border border-gray-100 bg-white">
                        <header class="border-b border-gray-100 px-5 py-3">
                            <h2 class="font-semibold text-gray-900">Service interne</h2>
                            <p class="text-sm text-gray-500">Ce qu'entendent les appels transférés vers le service interne ({{ INTERNAL_SERVICE_NUMBER }}).</p>
                        </header>
                        <div class="px-5 py-4">
                            <SipSoundSequence
                                title="Sons joués"
                                description="L'un après l'autre, en boucle."
                                :names="sequences.internal"
                                :library="sounds"
                                empty-text="Aucun son : musique d'attente par défaut du serveur."
                                :playing="playing"
                                :busy="busy"
                                @change="(list, message) => saveSequence('internal', list, message)"
                                @add="openSoundDialog('internal')"
                                @toggle-play="toggleSound"
                            />
                        </div>
                        <!-- Fichiers envoyés mais joués nulle part : les seuls qu'on peut supprimer -->
                        <details v-if="unusedSounds.length" class="border-t border-gray-100 px-5 py-3 text-sm">
                            <summary class="cursor-pointer text-gray-500 hover:text-gray-700">
                                {{ plural(unusedSounds.length, 'fichier') }} joué{{ unusedSounds.length > 1 ? 's' : '' }} nulle part
                            </summary>
                            <ul class="mt-2">
                                <li v-for="s in unusedSounds" :key="s.name" class="flex items-center gap-3 py-1">
                                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0" :title="playing === s.name ? 'Arrêter' : 'Écouter'" @click="toggleSound(s.name)">
                                        <Pause v-if="playing === s.name" class="size-3.5" />
                                        <Play v-else class="size-3.5" />
                                    </Button>
                                    <span class="flex-1 text-gray-700">{{ s.name }}</span>
                                    <span class="tabular-nums text-gray-400">{{ seconds(s.duration) }} · {{ formatDate(s.modified) }}</span>
                                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400 hover:text-red-600" :disabled="busy" title="Supprimer le fichier" @click="deleteSound(s.name)">
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </li>
                            </ul>
                        </details>
                    </section>

                    <!-- ═══ Fournisseurs ═══ -->
                    <section v-else-if="currentTab === 'fournisseurs'" class="rounded-lg border border-gray-100 bg-white">
                        <header class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-3">
                            <div>
                                <h2 class="font-semibold text-gray-900">IP autorisées · {{ trustedIps.length }}</h2>
                                <p class="text-sm text-gray-500">Seuls ces serveurs de fournisseurs peuvent nous envoyer des appels. L'IP de Voxnode est exclue volontairement.</p>
                            </div>
                            <Button size="sm" variant="secondary" class="shrink-0" :disabled="busy" @click="openIpDialog">
                                <Plus class="mr-1.5 h-4 w-4" />
                                Ajouter
                            </Button>
                        </header>
                        <p v-if="data?.['trusted-ips']?.ok === false" class="px-5 py-4 text-sm text-red-600">Liste indisponible : {{ data['trusted-ips'].error }}</p>
                        <ul v-else class="grid gap-x-4 px-3 py-2 sm:grid-cols-2 xl:grid-cols-3">
                            <li v-for="ip in trustedIps" :key="ip" class="flex items-center justify-between rounded px-2 py-1 hover:bg-gray-50">
                                <span class="font-mono text-sm text-gray-900">{{ ip }}</span>
                                <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400 hover:text-red-600" :disabled="busy" :title="`Retirer ${ip}`" @click="removeIp(ip)">
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </li>
                        </ul>
                    </section>

                    <!-- ═══ Régulateur ═══ -->
                    <form v-else-if="currentTab === 'regulateur'" class="rounded-lg border border-gray-100 bg-white" @submit.prevent="regulatorForm.save()">
                        <header class="border-b border-gray-100 px-5 py-3">
                            <h2 class="font-semibold text-gray-900">Régulateur</h2>
                            <p class="text-sm text-gray-500">Toutes les 30 s, il déplace des agents automatiques vers la plateforme qui en a besoin.</p>
                        </header>
                        <p v-if="tel?.regulator" class="border-b px-5 py-3 text-sm" :class="regulatorStopped ? 'border-red-100 bg-red-50 text-red-700' : 'border-gray-100 text-gray-600'">
                            <template v-if="regulatorStopped">Arrêté : dernière décision {{ lastRun?.at ? new Date(lastRun.at).toLocaleTimeString('fr-FR') : 'inconnue' }}.</template>
                            <template v-else>Dernière décision il y a {{ regulatorAge }} s : {{ lastRun?.reason }}</template>
                            <Link href="/sip-server" class="ml-2 underline">journal →</Link>
                        </p>
                        <div class="divide-y divide-gray-50">
                            <div
                                v-for="f in [
                                    { key: 'regulator_max_moves', label: 'Agents déplacés à chaque passage', help: 'Au maximum, toutes les 30 s.', unit: 'agents', min: 1, max: 10 },
                                    { key: 'regulator_cooldown_minutes', label: 'Délai avant de redéplacer un agent', help: 'Un agent qui vient de changer de plateforme n\'est pas redéplacé avant.', unit: 'min', min: 0, max: 60 },
                                    { key: 'regulator_wait_seconds', label: 'Attente qui déclenche un renfort', help: 'Un client qui attend plus longtemps dans notre file = besoin d\'agents chez nous.', unit: 's', min: 0, max: 600 },
                                ]"
                                :key="f.key"
                                class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[1fr_auto]"
                            >
                                <div>
                                    <Label :for="f.key" class="text-gray-900">{{ f.label }}</Label>
                                    <p class="text-sm text-gray-500">{{ f.help }}</p>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-gray-600">
                                    <Input :id="f.key" v-model.number="regulatorForm.values[f.key]" type="number" :min="f.min" :max="f.max" class="h-9 w-24 text-right tabular-nums" />
                                    <span class="w-12">{{ f.unit }}</span>
                                </div>
                            </div>
                        </div>
                        <footer v-if="regulatorForm.dirty || regulatorForm.error" class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                            <span v-if="regulatorForm.error" class="mr-auto text-sm text-red-600">{{ regulatorForm.error }}</span>
                            <template v-if="regulatorForm.dirty">
                                <Button type="button" variant="outline" size="sm" :disabled="busy" @click="regulatorForm.reset()">Annuler</Button>
                                <Button type="submit" size="sm" :disabled="busy || !!regulatorForm.error">Enregistrer</Button>
                            </template>
                        </footer>
                    </form>

                    <!-- ═══ Serveur et stockage (lecture seule) ═══ -->
                    <template v-else-if="currentTab === 'serveur'">
                        <section class="rounded-lg border border-gray-100 bg-white">
                            <header class="border-b border-gray-100 px-5 py-3">
                                <h2 class="font-semibold text-gray-900">Stockage</h2>
                            </header>
                            <div class="divide-y divide-gray-50">
                                <div v-for="d in disks" :key="d.prefix" class="px-5 py-4">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                                        <span class="font-medium text-gray-900">{{ d.label }}</span>
                                        <span class="tabular-nums" :class="d.low ? 'font-medium text-red-600' : 'text-gray-500'">
                                            <template v-if="d.total"><span :class="d.low ? '' : 'text-gray-900'">{{ size(d.free) }} libres</span> · {{ size(d.used) }} utilisés sur {{ size(d.total) }}</template>
                                            <template v-else>—</template>
                                        </span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div v-if="d.pct" class="h-full rounded-full" :class="d.low ? 'bg-red-500' : 'bg-gray-900'" :style="{ width: `${Math.max(d.pct * 100, 1)}%` }" />
                                    </div>
                                    <dl v-if="d.prefix === 'disk_data' && usage" class="mt-3 grid gap-x-8 gap-y-1 text-sm text-gray-500 sm:grid-cols-2">
                                        <div class="flex justify-between"><dt>Enregistrements d'appels</dt><dd class="tabular-nums text-gray-900">{{ size(usage.recordings?.bytes) }} · {{ plural(usage.recordings?.files ?? 0, 'fichier') }}</dd></div>
                                        <div class="flex justify-between"><dt>Plus ancien</dt><dd class="text-gray-900">{{ usage.recordings?.oldest_recording ? formatDate(usage.recordings.oldest_recording) : '—' }}</dd></div>
                                        <div class="flex justify-between"><dt>Sons</dt><dd class="tabular-nums text-gray-900">{{ size(usage.sounds?.bytes) }}</dd></div>
                                        <div class="flex justify-between"><dt>Autre</dt><dd class="tabular-nums text-gray-900">{{ size(usage.other?.bytes) }}</dd></div>
                                    </dl>
                                </div>
                                <div v-if="cloud" class="px-5 py-4">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                                        <span class="font-medium text-gray-900">Cloud <span class="font-normal text-gray-500">(enregistrements publics)</span></span>
                                        <span class="tabular-nums text-gray-500">
                                            <span class="text-gray-900">{{ size(cloud.bytes) }}</span> · {{ plural(cloud.objects ?? 0, 'fichier') }} ·
                                            <span :class="cloud.stale ? 'font-medium text-red-600' : ''">{{ cloud.ago }}</span>
                                        </span>
                                    </div>
                                    <p v-if="cloud.stale" class="mt-2 text-sm text-red-600">La taille n'est plus recalculée (normalement chaque jour à 05:17) : le chiffre peut être faux.</p>
                                </div>
                            </div>
                            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-400">
                                Purge prévue : enregistrements agent après 120 j, transfert après 30 j, blacklist conservés.
                                <template v-if="usage?.computed_at">Détail calculé à {{ new Date(usage.computed_at).toLocaleTimeString('fr-FR') }}.</template>
                            </p>
                        </section>

                        <section class="rounded-lg border border-gray-100 bg-white">
                            <header class="border-b border-gray-100 px-5 py-3">
                                <h2 class="font-semibold text-gray-900">Serveur</h2>
                            </header>
                            <dl class="divide-y divide-gray-50 text-sm">
                                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-gray-500">IP à donner aux fournisseurs (SIP / RTP)</dt><dd class="font-mono text-gray-900">54.36.250.193</dd></div>
                                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-gray-500">WebRTC (softphone du panel)</dt><dd class="font-mono text-gray-900">wss://sip.118.ae/ws</dd></div>
                                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-gray-500">Machine</dt><dd class="text-gray-900">OVH RISE-2 · Gravelines</dd></div>
                                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-gray-500">Système</dt><dd class="text-gray-900">Ubuntu 26.04 · Asterisk {{ version }}</dd></div>
                                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-gray-500">En service depuis</dt><dd class="text-gray-900">{{ uptimeText }}</dd></div>
                            </dl>
                        </section>
                    </template>
                </div>
            </div>
        </div>

        <!-- ─── Fenêtre : confirmation d'une action ──────────────────────────── -->
        <Dialog :open="!!confirmState" @update:open="(open) => !open && closeConfirm(false)">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ confirmState?.title }}</DialogTitle>
                    <DialogDescription class="text-gray-700">{{ confirmState?.body }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="closeConfirm(false)">Annuler</Button>
                    <Button :variant="confirmState?.danger ? 'destructive' : 'default'" @click="closeConfirm(true)">{{ confirmState?.confirm }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- ─── Fenêtre : ajouter un son ─────────────────────────────────────── -->
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
                            <Label for="sound-name">Nom (facultatif, nettoyé automatiquement)</Label>
                            <Input id="sound-name" v-model="soundName" type="text" placeholder="accueil été" />
                            <p class="text-xs text-gray-500">Sans nom, celui du fichier est utilisé. Un suffixe unique est ajouté : un envoi n'écrase jamais un son existant.</p>
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

        <!-- ─── Fenêtre : ajouter une IP ─────────────────────────────────────── -->
        <Dialog v-model:open="showIpDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Autoriser une IP de fournisseur</DialogTitle>
                    <DialogDescription>Le fournisseur pourra nous envoyer des appels depuis cette IP, en environ 1 seconde.</DialogDescription>
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
