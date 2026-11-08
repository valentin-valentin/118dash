<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Check, Copy, ExternalLink, Mic, MicOff, Volume2, VolumeX } from 'lucide-vue-next'
import { callTest, hangup, setMuted, setVolume, softphone } from '@/composables/useSoftphone'
import { xsrfToken } from '@/lib/csrf'

/**
 * Testeur de softphone : le panel joue le client. Il appelle notre serveur SIP avec l'appelant et l'appelé choisis ;
 * l'appel suit le vrai parcours et ne fait sonner que les comptes de test (test01, test02), connectés au softphone agent.
 *   1. Comptes de test (PIN pour se connecter au softphone agent)
 *   2. Appel : appelant, appelé, options ; micro et haut-parleur du navigateur (softphone du panel)
 *   3. Suivi en direct : canaux du serveur SIP de l'appel (même linkedid), étapes avec heure et durée
 *   4. Historique des appels de test lancés depuis ce navigateur (localStorage)
 */

// ─── Comptes de test et numéros proposés (voxnode118) ─────────────────────────
const setup = ref(null)
const setupError = ref(null)
async function loadSetup() {
    try {
        const res = await fetch('/data/softphone-tester', { headers: { Accept: 'application/json' } })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.message || `Erreur HTTP ${res.status}`)
        setup.value = json
        setupError.value = null
        if (!called.value) called.value = json.testCalled ?? ''
    } catch (e) {
        setupError.value = e.message
    }
}
let setupTimer = null
onMounted(() => {
    loadSetup()
    // État des comptes de test (connecté, en appel…) relu toutes les 10 s
    setupTimer = setInterval(() => document.visibilityState === 'visible' && loadSetup(), 10000)
})
onUnmounted(() => clearInterval(setupTimer))

// Appli agent (softphone des agents, connexion par PIN)
const AGENT_APP = 'https://agent.118.ae/'
const testerState = (t) => {
    if (!t.enabled) return { label: 'désactivé', class: 'text-gray-400', dot: 'bg-gray-300' }
    if (!t.online && !t.registered) return { label: 'hors ligne', class: 'text-gray-500', dot: 'bg-gray-300' }
    if (t.inCall) return { label: 'en appel', class: 'text-gray-900', dot: 'bg-gray-900' }
    if (t.onBreak) return { label: 'en pause', class: 'text-amber-700', dot: 'bg-amber-500' }
    if (t.registered) return { label: 'disponible', class: 'text-emerald-700', dot: 'bg-emerald-500' }
    return { label: 'appli ouverte, téléphone non connecté', class: 'text-gray-700', dot: 'bg-gray-400' }
}
const copied = ref(null)
async function copyPin(t) {
    try {
        await navigator.clipboard.writeText(String(t.pin))
        copied.value = t.id
        setTimeout(() => copied.value === t.id && (copied.value = null), 1500)
    } catch {
        // presse-papiers indisponible
    }
}

// ─── Formulaire ───────────────────────────────────────────────────────────────
const caller = ref('')
const called = ref('')
const skipWait = ref(false)
const realTransfer = ref(false)
const callerKind = ref('new')
function randomMobile() {
    caller.value = `+336${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`
    callerKind.value = 'new'
}
randomMobile()
const blacklisted = computed(() => setup.value?.presets?.blacklisted ?? [])
const recentCallers = computed(() => setup.value?.presets?.recentCallers ?? [])
const numbers = computed(() => setup.value?.presets?.numbers ?? [])
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '')

// ─── Appel ────────────────────────────────────────────────────────────────────
const active = computed(() => ['connecting', 'calling', 'early', 'ringing', 'established'].includes(softphone.status))
const current = ref(null) // appel de test lancé depuis cette page
async function start() {
    const options = { caller: caller.value, called: called.value, skipWait: skipWait.value, realTransfer: realTransfer.value }
    const startedAt = Date.now()
    if (!(await callTest(options))) return
    current.value = { ...options, startedAt, linkedid: null, steps: [], ended: false }
    channels.value = []
    poll()
}

// ─── Suivi en direct (GET /calls du serveur SIP, appels du testeur) ───────────
const channels = ref([])
const pollError = ref(null)
let pollTimer = null
async function poll() {
    clearTimeout(pollTimer)
    if (!current.value) return
    try {
        const res = await fetch('/data/softphone-tester/calls', { headers: { Accept: 'application/json' } })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.message || `Erreur HTTP ${res.status}`)
        pollError.value = null
        track(json.channels ?? [])
    } catch (e) {
        pollError.value = e.message
    }
    // Toutes les 1,5 s pendant l'appel, puis encore 5 s après la fin pour voir les derniers canaux
    if (current.value && (!current.value.ended || Date.now() - current.value.endedAt < 5000)) pollTimer = setTimeout(poll, 1500)
}
onUnmounted(() => clearTimeout(pollTimer))

// Notre appel : le linkedid (horodatage Asterisk en secondes) le plus proche du lancement, choisi une fois pour toutes
function track(list) {
    const c = current.value
    if (!c.linkedid) {
        const ids = [...new Set(list.map((x) => x.linkedid || x.uniqueid))]
        const candidates = ids.filter((id) => Number(id) * 1000 >= c.startedAt - 3000).sort((a, b) => Math.abs(Number(a) * 1000 - c.startedAt) - Math.abs(Number(b) * 1000 - c.startedAt))
        c.linkedid = candidates[0] ?? null
    }
    channels.value = c.linkedid ? list.filter((x) => (x.linkedid || x.uniqueid) === c.linkedid) : []
    if (channels.value.length) step(stageOf(channels.value))
}

// Étape d'après les canaux : agent d'abord, puis transfert, puis le canal du client
const testAgent = (ch) => ch.channel?.match(/^PJSIP\/(test\d+)-/)?.[1] ?? null
function stageOf(chs) {
    const agentUp = chs.find((x) => testAgent(x) && x.state === 'Up')
    if (agentUp) return { key: `agent-${testAgent(agentUp)}`, label: `En ligne avec ${testAgent(agentUp)}` }
    const ringing = chs.find((x) => testAgent(x) && x.state === 'Ringing')
    if (ringing) return { key: `ring-${testAgent(ringing)}`, label: `Sonne chez ${testAgent(ringing)}` }
    const internal = chs.find((x) => x.context === 'internal-service')
    if (internal) return { key: 'backup', label: 'Backup (service interne)' }
    const transfer = chs.find((x) => x.context === 'transfer')
    if (transfer) {
        const exten = transfer.exten && transfer.exten !== 's' ? transfer.exten : ''
        return transfer.app === 'Dial'
            ? { key: `transfer-${exten}`, label: `Transfert vers ${exten || 'la marque'}` }
            : { key: `transfer-sim-${exten}`, label: `Transfert simulé${exten ? ` vers ${exten}` : ''}` }
    }
    const client = chs.find((x) => x.context === 'from-providers')
    if (client?.app === 'Queue') return { key: 'queue', label: "File d'attente" }
    if (client?.app === 'MusicOnHold') return { key: 'forced', label: 'Attente forcée' }
    if (client) return { key: 'welcome', label: 'Accueil' }
    return null
}
function step(stage, at = Date.now()) {
    const c = current.value
    if (!stage || c.steps.at(-1)?.key === stage.key) return
    const last = c.steps.at(-1)
    if (last && !last.end) last.end = at
    c.steps.push({ ...stage, start: at, end: null })
}

// Fin de l'appel (session du navigateur) : dernière étape, puis historique
watch(
    () => softphone.status,
    (status) => {
        const c = current.value
        if (!c || c.ended || status !== 'ended') return
        const at = Date.now()
        const last = c.steps.at(-1)
        if (last && !last.end) last.end = at
        c.ended = true
        c.endedAt = at
        c.rejected = softphone.answeredAt || softphone.earlyAt ? null : softphone.rejected
        c.result = c.rejected ? `Refusé sans décrocher : SIP ${c.rejected.code}${c.rejected.reason ? ` ${c.rejected.reason}` : ''}` : (softphone.endReason ?? 'Raccroché')
        c.steps.push({ key: 'end', label: 'Raccroché', start: at, end: at })
        saveHistory(c)
    },
)

// Micro et son du navigateur (softphone du panel, partagé avec le widget)
const soundOff = computed(() => softphone.volume === 0)
let lastVolume = 1
function toggleSound() {
    if (soundOff.value) {
        setVolume(lastVolume || 1)
    } else {
        lastVolume = softphone.volume
        setVolume(0)
    }
}

// Raccrocher côté serveur : tous les canaux de l'appel (appel bloqué)
const serverHangupError = ref(null)
async function serverHangup() {
    const id = current.value?.linkedid
    if (!id) return
    serverHangupError.value = null
    const res = await fetch(`/data/softphone-tester/calls/${encodeURIComponent(id)}/hangup`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null)
    if (!res?.ok) serverHangupError.value = (await res?.json().catch(() => null))?.message ?? 'Échec'
}

// Durées, horloge
const now = ref(Date.now())
let clock = null
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 500)))
onUnmounted(() => clearInterval(clock))
const hms = (ms) => new Date(ms).toLocaleTimeString('fr-FR')
const dur = (ms) => {
    const s = Math.max(0, Math.round(ms / 1000))
    return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')}`
}
const STATUS_LABELS = { connecting: 'Connexion', calling: 'Appel envoyé', early: 'Audio du serveur', ringing: 'Sonnerie', established: 'Décroché par le serveur', ended: 'Terminé' }

// ─── Historique (localStorage) ────────────────────────────────────────────────
const HISTORY_KEY = 'softphone-tester-history'
const history = ref([])
try {
    history.value = JSON.parse(localStorage.getItem(HISTORY_KEY) ?? '[]')
} catch {
    history.value = []
}
function saveHistory(c) {
    const entry = {
        at: c.startedAt,
        caller: c.caller,
        called: c.called,
        skipWait: c.skipWait,
        realTransfer: c.realTransfer,
        result: c.result,
        steps: c.steps.filter((s) => s.key !== 'end').map((s) => ({ label: s.label, ms: (s.end ?? c.endedAt) - s.start })),
        total: c.endedAt - c.startedAt,
    }
    history.value = [entry, ...history.value].slice(0, 30)
    try {
        localStorage.setItem(HISTORY_KEY, JSON.stringify(history.value))
    } catch {
        // stockage indisponible : historique de la page seulement
    }
}
function clearHistory() {
    history.value = []
    try {
        localStorage.removeItem(HISTORY_KEY)
    } catch {
        // rien à faire
    }
}
</script>

<template>
    <Head title="Testeur de softphone" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Testeur de softphone' }]" />

            <p v-if="setupError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ setupError }}</p>

            <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
                <!-- ─── Lancer un appel : qui décroche, qui appelle, quel numéro ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <header class="border-b border-gray-100 px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-gray-900">Appel de test</h2>
                    </header>

                    <div class="space-y-6 px-5 py-5">
                        <!-- Comptes qui décrochent : PIN à saisir sur l'appli agent -->
                        <div>
                            <h3 class="mb-2 text-sm font-medium text-gray-900">Agent qui décroche</h3>
                            <div v-if="!setup && !setupError" class="h-20 animate-pulse rounded-md bg-gray-50" />
                            <p v-else-if="!setup?.testers?.length" class="text-sm text-gray-500">Aucun compte de test</p>
                            <table v-else class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100 text-left text-xs text-gray-500">
                                        <th class="pb-2 font-medium">Compte</th>
                                        <th class="pb-2 font-medium">État</th>
                                        <th class="pb-2 font-medium">PIN</th>
                                        <th class="pb-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="t in setup.testers" :key="t.id" class="border-b border-gray-50 last:border-0">
                                        <td class="py-2.5">
                                            <span class="font-medium text-gray-900">{{ t.username }}</span>
                                            <span v-if="t.name" class="ml-2 text-gray-500">{{ t.name }}</span>
                                        </td>
                                        <td class="py-2.5">
                                            <span class="inline-flex items-center gap-1.5" :class="testerState(t).class">
                                                <span class="h-2 w-2 rounded-full" :class="testerState(t).dot" />
                                                {{ testerState(t).label }}
                                            </span>
                                        </td>
                                        <td class="py-2.5">
                                            <button type="button" class="inline-flex items-center gap-2 rounded-md px-2 py-1 font-mono text-gray-900 hover:bg-gray-100" :aria-label="`Copier le PIN de ${t.username}`" @click="copyPin(t)">
                                                {{ t.pin }}
                                                <Check v-if="copied === t.id" class="h-3.5 w-3.5 text-emerald-600" />
                                                <Copy v-else class="h-3.5 w-3.5 text-gray-400" />
                                            </button>
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <a :href="AGENT_APP" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                                                Ouvrir l'appli agent <ExternalLink class="h-3.5 w-3.5" />
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Appelant et appelé -->
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-900" for="tester-caller">Appelant</label>
                                <Input id="tester-caller" v-model="caller" class="h-10 w-full font-mono" :placeholder="callerKind === 'masked' ? 'masqué' : '+33…'" @input="callerKind = 'free'" />
                                <!-- Raccourcis : quatre cases de même largeur -->
                                <div class="mt-2 grid grid-cols-2 gap-1.5">
                                    <button type="button" class="chip" :aria-pressed="callerKind === 'new'" @click="randomMobile">Nouveau numéro</button>
                                    <button type="button" class="chip" :aria-pressed="callerKind === 'masked'" @click="(caller = ''), (callerKind = 'masked')">Masqué</button>
                                    <select
                                        class="chip-select"
                                        :class="callerKind === 'blacklisted' && 'on'"
                                        :disabled="!blacklisted.length"
                                        @change="(e) => e.target.value && ((caller = e.target.value), (callerKind = 'blacklisted'), (e.target.value = ''))"
                                    >
                                        <option value="">Liste noire</option>
                                        <option v-for="b in blacklisted" :key="b.number" :value="b.number">{{ b.number }}{{ b.note ? ` · ${b.note}` : '' }}</option>
                                    </select>
                                    <select
                                        class="chip-select"
                                        :class="callerKind === 'recent' && 'on'"
                                        :disabled="!recentCallers.length"
                                        @change="(e) => e.target.value && ((caller = e.target.value), (callerKind = 'recent'), (e.target.value = ''))"
                                    >
                                        <option value="">Client récent</option>
                                        <option v-for="r in recentCallers" :key="r.number + r.calledAt" :value="r.number">{{ r.number }} · {{ r.brand || '—' }} · {{ fmtTime(r.calledAt) }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-900" for="tester-called">Numéro appelé</label>
                                <Input id="tester-called" v-model="called" class="h-10 w-full font-mono" placeholder="+33…" />
                                <select class="chip-select mt-2 w-full" @change="(e) => e.target.value && ((called = e.target.value), (e.target.value = ''))">
                                    <option value="">Nos numéros</option>
                                    <option v-for="n in numbers" :key="n.number" :value="n.number">{{ n.number }}{{ n.label ? ` · ${n.label}` : '' }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-2 text-sm text-gray-800">
                            <label class="flex cursor-pointer items-center gap-2.5"><input v-model="skipWait" type="checkbox" class="h-4 w-4" />Sauter l'attente forcée</label>
                            <label class="flex cursor-pointer items-center gap-2.5"><input v-model="realTransfer" type="checkbox" class="h-4 w-4" />Transfert réel vers la marque <span class="text-gray-500">(appelle vraiment la marque, facturé)</span></label>
                        </div>
                    </div>

                    <footer class="border-t border-gray-100 px-5 py-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button v-if="!active" type="button" class="min-w-28" :disabled="!called" @click="start">Appeler</Button>
                            <Button v-else type="button" class="min-w-28" variant="destructive" @click="hangup">Raccrocher</Button>

                            <!-- Micro et son pendant l'appel -->
                            <div class="inline-flex overflow-hidden rounded-md border border-gray-200">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-2 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-40"
                                    :class="softphone.muted ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                    :disabled="!active"
                                    :aria-pressed="softphone.muted"
                                    @click="setMuted(!softphone.muted)"
                                >
                                    <MicOff v-if="softphone.muted" class="h-4 w-4" />
                                    <Mic v-else class="h-4 w-4" />
                                    {{ softphone.muted ? 'Micro coupé' : 'Micro' }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-2 border-l border-gray-200 px-3 text-sm"
                                    :class="soundOff ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                    :aria-pressed="soundOff"
                                    @click="toggleSound"
                                >
                                    <VolumeX v-if="soundOff" class="h-4 w-4" />
                                    <Volume2 v-else class="h-4 w-4" />
                                    {{ soundOff ? 'Son coupé' : 'Son' }}
                                </button>
                            </div>

                            <Button type="button" variant="outline" class="ml-auto" :disabled="!current?.linkedid || !channels.length" @click="serverHangup">Raccrocher côté serveur</Button>
                        </div>
                        <p v-if="softphone.error" class="mt-2 text-sm text-red-600">{{ softphone.error }}</p>
                        <p v-else-if="serverHangupError" class="mt-2 text-sm text-red-600">{{ serverHangupError }}</p>
                    </footer>
                </section>

                <!-- ─── Suivi de l'appel en cours ─── -->
                <section class="rounded-lg border border-gray-100 bg-white xl:sticky xl:top-20">
                    <header class="flex items-baseline gap-3 border-b border-gray-100 px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-gray-900">Suivi de l'appel</h2>
                        <span v-if="active && softphone.startedAt" class="ml-auto text-sm tabular-nums text-gray-500">{{ dur(now - softphone.startedAt) }}</span>
                    </header>
                    <div class="px-5 py-4">
                        <p v-if="!current" class="text-sm text-gray-400">Aucun appel</p>
                        <template v-else>
                            <p class="text-sm text-gray-900">
                                <span class="font-mono">{{ current.caller || 'masqué' }}</span>
                                <span class="mx-1.5 text-gray-400">→</span>
                                <span class="font-mono">{{ current.called }}</span>
                            </p>
                            <p v-if="current.skipWait || current.realTransfer" class="mt-0.5 text-xs text-gray-500">
                                {{ [current.skipWait && 'sans attente forcée', current.realTransfer && 'transfert réel'].filter(Boolean).join(' · ') }}
                            </p>

                            <table class="mt-4 w-full text-sm">
                                <tbody>
                                    <tr v-if="!current.ended && !current.steps.length">
                                        <td class="py-2 text-gray-500">{{ STATUS_LABELS[softphone.status] ?? '…' }}</td>
                                    </tr>
                                    <tr v-for="(s, i) in current.steps" :key="i" class="border-b border-gray-50 last:border-0" :class="!s.end && s.key !== 'end' && 'bg-gray-50'">
                                        <td class="w-24 py-2 pl-2 font-mono text-xs text-gray-400">{{ hms(s.start) }}</td>
                                        <td class="py-2" :class="s.key === 'end' ? 'text-gray-500' : !s.end ? 'font-medium text-gray-900' : 'text-gray-700'">{{ s.label }}</td>
                                        <td class="py-2 pr-2 text-right tabular-nums text-gray-500">{{ s.key === 'end' ? '' : dur((s.end ?? now) - s.start) }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <p v-if="current.ended" class="mt-3 text-sm" :class="current.rejected ? 'text-red-600' : 'text-gray-700'">{{ current.result }} · {{ dur(current.endedAt - current.startedAt) }}</p>
                            <p v-if="pollError" class="mt-3 text-sm text-red-600">{{ pollError }}</p>

                            <details v-if="channels.length" class="mt-4 text-xs text-gray-500">
                                <summary class="cursor-pointer">Canaux Asterisk · {{ current.linkedid }}</summary>
                                <table class="mt-2 w-full font-mono">
                                    <tbody>
                                        <tr v-for="ch in channels" :key="ch.uniqueid" class="border-t border-gray-50">
                                            <td class="py-1 pr-3">{{ ch.channel }}</td>
                                            <td class="py-1 pr-3">{{ ch.context }}</td>
                                            <td class="py-1 pr-3">{{ ch.app }}</td>
                                            <td class="py-1 pr-3">{{ ch.state }}</td>
                                            <td class="py-1 text-right">{{ ch.duration }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </details>
                        </template>
                    </div>
                </section>
            </div>

            <!-- ─── Historique (ce navigateur) ─── -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="flex items-center gap-3 border-b border-gray-100 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-gray-900">Derniers appels de test</h2>
                    <button v-if="history.length" type="button" class="ml-auto text-sm text-gray-500 hover:text-gray-900" @click="clearHistory">Effacer</button>
                </header>
                <p v-if="!history.length" class="px-5 py-4 text-sm text-gray-400">Aucun appel</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs text-gray-500">
                                <th class="px-5 py-2.5 font-medium">Heure</th>
                                <th class="px-3 py-2.5 font-medium">Appelant</th>
                                <th class="px-3 py-2.5 font-medium">Appelé</th>
                                <th class="px-3 py-2.5 font-medium">Parcours</th>
                                <th class="px-3 py-2.5 font-medium">Fin</th>
                                <th class="px-5 py-2.5 text-right font-medium">Durée</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in history" :key="h.at" class="border-b border-gray-50 align-top last:border-0 hover:bg-gray-50/60">
                                <td class="px-5 py-3 whitespace-nowrap text-gray-500">{{ fmtTime(new Date(h.at).toISOString()) }}</td>
                                <td class="px-3 py-3 font-mono whitespace-nowrap">{{ h.caller || 'masqué' }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <span class="font-mono">{{ h.called }}</span>
                                    <span v-if="h.skipWait || h.realTransfer" class="block text-xs text-gray-500">{{ [h.skipWait && 'sans attente forcée', h.realTransfer && 'transfert réel'].filter(Boolean).join(' · ') }}</span>
                                </td>
                                <td class="px-3 py-3 text-gray-700">
                                    <span v-for="(st, i) in h.steps" :key="i" class="whitespace-nowrap">{{ i ? ' → ' : '' }}{{ st.label }} <span class="text-gray-400">{{ dur(st.ms) }}</span></span>
                                    <span v-if="!h.steps.length" class="text-gray-400">—</span>
                                </td>
                                <td class="px-3 py-3 text-gray-700">{{ h.result }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums">{{ dur(h.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.chip,
.chip-select {
    height: 30px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #4b5563;
    border-radius: 6px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
}
.chip:hover,
.chip-select:hover {
    background: #f3f4f6;
}
.chip[aria-pressed='true'],
.chip-select.on {
    background: #111827;
    color: #fff;
    border-color: #111827;
}
.chip-select:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
