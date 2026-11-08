<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTable from '@/components/DataTable.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
    if (!t.enabled) return { label: 'Désactivé', class: 'bg-gray-100 text-gray-500' }
    if (!t.online && !t.registered) return { label: 'Hors ligne', class: 'bg-gray-100 text-gray-700' }
    if (t.inCall) return { label: 'En appel', class: 'bg-blue-100 text-blue-700' }
    if (t.onBreak) return { label: 'En pause', class: 'bg-amber-100 text-amber-700' }
    if (t.registered) return { label: 'Disponible', class: 'bg-green-100 text-green-700' }
    return { label: 'Téléphone non connecté', class: 'bg-gray-100 text-gray-700' }
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
const pickBlacklisted = ref(null)
const pickRecent = ref(null)
const pickCalled = ref(null)
watch(pickBlacklisted, (v) => v && ((caller.value = v), (callerKind.value = 'blacklisted'), (pickBlacklisted.value = null)))
watch(pickRecent, (v) => v && ((caller.value = v), (callerKind.value = 'recent'), (pickRecent.value = null)))
watch(pickCalled, (v) => v && ((called.value = v), (pickCalled.value = null)))
const blacklisted = computed(() => setup.value?.presets?.blacklisted ?? [])
const recentCallers = computed(() => setup.value?.presets?.recentCallers ?? [])
const numbers = computed(() => setup.value?.presets?.numbers ?? [])
const blacklistOptions = computed(() => blacklisted.value.map((b) => ({ value: b.number, label: b.note ? `${b.number} · ${b.note}` : b.number })))
const recentOptions = computed(() => recentCallers.value.map((r) => ({ value: r.number, label: `${r.number} · ${r.brand || '—'} · ${fmtTime(r.calledAt)}` })))
const numberOptions = computed(() => numbers.value.map((n) => ({ value: n.number, label: n.label ? `${n.number} · ${n.label}` : n.number })))
const testerColumns = [
    { key: 'username', label: 'Compte' },
    { key: 'name', label: 'Nom' },
    { key: 'state', label: 'État' },
    { key: 'pin', label: 'PIN' },
    { key: 'actions', label: '' },
]
const historyColumns = [
    { key: 'at', label: 'Heure' },
    { key: 'caller', label: 'Appelant' },
    { key: 'called', label: 'Appelé' },
    { key: 'steps', label: 'Parcours' },
    { key: 'result', label: 'Fin' },
    { key: 'total', label: 'Durée' },
]
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

    <AppLayout width="form">
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Testeur de softphone' }]" />

            <p v-if="setupError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ setupError }}</p>

            <!-- ─── Comptes de test ─── -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Comptes de test <span class="ml-1 font-normal text-gray-500">seuls ces comptes reçoivent les appels de test · connexion sur agent.118.ae avec le PIN</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="testerColumns" :rows="setup?.testers ?? []" :loading="!setup && !setupError" empty-message="Aucun compte de test">
                        <template #username="{ value }"><span class="font-medium text-gray-900">{{ value }}</span></template>
                        <template #name="{ value }"><span class="text-sm text-gray-600">{{ value || '-' }}</span></template>
                        <template #state="{ row }">
                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium" :class="testerState(row).class">{{ testerState(row).label }}</span>
                        </template>
                        <template #pin="{ row }">
                            <button type="button" class="inline-flex items-center gap-1.5 font-mono text-sm text-gray-900 hover:text-gray-600" :title="`Copier le PIN de ${row.username}`" @click="copyPin(row)">
                                {{ row.pin }}
                                <Check v-if="copied === row.id" class="size-3.5 text-green-600" />
                                <Copy v-else class="size-3.5 text-gray-400" />
                            </button>
                        </template>
                        <template #actions>
                            <div class="text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <a :href="AGENT_APP" target="_blank" rel="noopener"><ExternalLink class="mr-2 h-4 w-4" />agent.118.ae</a>
                                </Button>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </section>

            <!-- ─── Appel de test ─── -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold text-gray-900">Appel de test</h2>
                    <p class="text-sm text-gray-500">Le panel appelle notre serveur SIP comme un client, avec l'appelant et le numéro de votre choix.</p>
                </header>
                <div class="divide-y divide-gray-50">
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                        <Label for="tester-caller" class="pt-2 text-gray-900">Appelant</Label>
                        <!-- Champ sur une ligne, raccourcis sur la suivante (ne tiennent pas ensemble en largeur « form ») -->
                        <div class="space-y-2">
                            <Input id="tester-caller" v-model="caller" class="h-9 w-56 font-mono" :placeholder="callerKind === 'masked' ? 'Masqué' : '+33…'" @input="callerKind = 'free'" />
                            <div class="flex items-center gap-2">
                                <Button type="button" size="sm" :variant="callerKind === 'new' ? 'default' : 'outline'" @click="randomMobile">Nouveau numéro</Button>
                                <Button type="button" size="sm" :variant="callerKind === 'masked' ? 'default' : 'outline'" @click="(caller = ''), (callerKind = 'masked')">Masqué</Button>
                                <div class="w-44"><FilterSelect v-model="pickBlacklisted" :options="blacklistOptions" placeholder="Liste noire" :searchable="blacklistOptions.length > 8" /></div>
                                <div class="w-44"><FilterSelect v-model="pickRecent" :options="recentOptions" placeholder="Client récent" searchable /></div>
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                        <Label for="tester-called" class="pt-2 text-gray-900">Numéro appelé</Label>
                        <div class="flex flex-wrap items-center gap-2">
                            <Input id="tester-called" v-model="called" class="h-9 w-48 font-mono" placeholder="+33…" />
                            <div class="w-60"><FilterSelect v-model="pickCalled" :options="numberOptions" placeholder="Nos numéros" :searchable="numberOptions.length > 8" /></div>
                        </div>
                    </div>
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                        <span class="text-sm font-medium text-gray-900">Options</span>
                        <div class="space-y-2 text-sm text-gray-700">
                            <label class="flex items-center gap-2"><Checkbox :model-value="skipWait" @update:model-value="skipWait = !!$event" />Sauter l'attente forcée</label>
                            <label class="flex items-center gap-2"><Checkbox :model-value="realTransfer" @update:model-value="realTransfer = !!$event" />Transfert réel vers la marque <span class="text-gray-500">(appelle vraiment la marque, facturé)</span></label>
                        </div>
                    </div>
                </div>
                <footer class="flex flex-wrap items-center gap-3 border-t border-gray-100 px-5 py-3">
                    <Button v-if="!active" type="button" size="sm" :disabled="!called" @click="start">Appeler</Button>
                    <Button v-else type="button" size="sm" variant="destructive" @click="hangup">Raccrocher</Button>
                    <Button type="button" size="sm" variant="outline" :disabled="!active" @click="setMuted(!softphone.muted)">
                        <MicOff v-if="softphone.muted" class="mr-2 h-4 w-4" /><Mic v-else class="mr-2 h-4 w-4" />{{ softphone.muted ? 'Réactiver le micro' : 'Couper le micro' }}
                    </Button>
                    <Button type="button" size="sm" variant="outline" @click="toggleSound">
                        <VolumeX v-if="soundOff" class="mr-2 h-4 w-4" /><Volume2 v-else class="mr-2 h-4 w-4" />{{ soundOff ? 'Remettre le son' : 'Couper le son' }}
                    </Button>
                    <span v-if="softphone.error" class="text-sm text-red-600">{{ softphone.error }}</span>
                    <span v-else-if="serverHangupError" class="text-sm text-red-600">{{ serverHangupError }}</span>
                    <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="!current?.linkedid || !channels.length" @click="serverHangup">Raccrocher côté serveur</Button>
                </footer>
            </section>

            <!-- ─── Suivi de l'appel ─── -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="flex items-baseline gap-3 border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold text-gray-900">Suivi de l'appel</h2>
                    <span v-if="current" class="text-sm text-gray-500">
                        <span class="font-mono">{{ current.caller || 'masqué' }}</span> → <span class="font-mono">{{ current.called }}</span>
                        <template v-if="current.skipWait"> · sans attente forcée</template>
                        <template v-if="current.realTransfer"> · transfert réel</template>
                    </span>
                    <span v-if="active && softphone.startedAt" class="ml-auto text-sm tabular-nums text-gray-500">{{ dur(now - softphone.startedAt) }}</span>
                </header>
                <p v-if="!current" class="px-5 py-4 text-sm text-gray-500">Aucun appel en cours</p>
                <div v-else class="divide-y divide-gray-50">
                    <div v-if="!current.ended && !current.steps.length" class="px-5 py-3 text-sm text-gray-500">{{ STATUS_LABELS[softphone.status] ?? '…' }}</div>
                    <div v-for="(st, i) in current.steps" :key="i" class="grid items-baseline gap-3 px-5 py-3 text-sm sm:grid-cols-[13rem_1fr_auto]">
                        <span class="font-mono text-xs text-gray-500">{{ hms(st.start) }}</span>
                        <span :class="st.key === 'end' ? 'text-gray-500' : !st.end ? 'font-medium text-gray-900' : 'text-gray-700'">{{ st.label }}</span>
                        <span class="tabular-nums text-gray-500">{{ st.key === 'end' ? '' : dur((st.end ?? now) - st.start) }}</span>
                    </div>
                    <div v-if="current.ended" class="px-5 py-3 text-sm" :class="current.rejected ? 'text-red-600' : 'text-gray-700'">{{ current.result }} · {{ dur(current.endedAt - current.startedAt) }}</div>
                    <div v-if="pollError" class="px-5 py-3 text-sm text-red-600">{{ pollError }}</div>
                    <details v-if="channels.length" class="px-5 py-3 text-sm text-gray-500">
                        <summary class="cursor-pointer">Canaux Asterisk · {{ current.linkedid }}</summary>
                        <table class="mt-2 w-full font-mono text-xs">
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
                </div>
            </section>

            <!-- ─── Derniers appels de test (ce navigateur) ─── -->
            <section>
                <div class="mb-2 flex items-center gap-3">
                    <h2 class="text-sm font-semibold text-gray-900">Derniers appels de test <span class="ml-1 font-normal tabular-nums text-gray-400">{{ history.length }}</span></h2>
                    <button v-if="history.length" type="button" class="ml-auto text-xs text-gray-500 hover:text-gray-900" @click="clearHistory">Effacer</button>
                </div>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="historyColumns" :rows="history" empty-message="Aucun appel">
                        <template #at="{ value }"><span class="text-sm whitespace-nowrap text-gray-600">{{ fmtTime(new Date(value).toISOString()) }}</span></template>
                        <template #caller="{ value }"><span class="font-mono text-sm whitespace-nowrap text-gray-700">{{ value || 'masqué' }}</span></template>
                        <template #called="{ row }">
                            <span class="font-mono text-sm whitespace-nowrap text-gray-700">{{ row.called }}</span>
                            <span v-if="row.skipWait || row.realTransfer" class="block text-xs text-gray-500">{{ [row.skipWait && 'sans attente forcée', row.realTransfer && 'transfert réel'].filter(Boolean).join(' · ') }}</span>
                        </template>
                        <template #steps="{ row }">
                            <span class="text-sm text-gray-700">
                                <span v-for="(st, i) in row.steps" :key="i" class="whitespace-nowrap">{{ i ? ' → ' : '' }}{{ st.label }} <span class="text-gray-400">{{ dur(st.ms) }}</span></span>
                                <span v-if="!row.steps.length" class="text-gray-400">-</span>
                            </span>
                        </template>
                        <template #result="{ value }"><span class="text-sm text-gray-700">{{ value }}</span></template>
                        <template #total="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ dur(value) }}</span></template>
                    </DataTable>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
