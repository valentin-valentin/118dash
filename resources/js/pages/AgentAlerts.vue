<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { TriangleAlert } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { formatPhone } from '@/lib/calls'

/**
 * Alertes agents : quel agent pose problème, et de quelle façon (maquette validée par le propriétaire).
 *   Filtres : période, centre
 *   Deux onglets : Comportement (ce que l'agent aurait pu éviter) et Technique (jamais reproché à l'agent), avec le
 *   total de chaque famille ; sur chacun, une case par sorte (pastille de sa couleur et nombre), le classement par agent
 *   (un clic ouvre sa fenêtre) et la liste des alertes ; sur Technique, les incidents collectifs en tête
 *   Fenêtre d'un agent : la période en chiffres, puis le fil (alertes, pauses, débuts de journée), filtrable
 */
const props = defineProps({ centers: { type: Array, default: () => [] } })

const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: 'week', label: '7 jours' },
    { key: 'month', label: '30 jours' },
]
// Une couleur par sorte : [texte, fond de l'étiquette, pastille] ; label : case à cocher, column : classement (court,
// pour que toutes les colonnes tiennent ; la couleur fait le lien avec la case)
const KINDS = {
    behaviour: [
        { key: 'missed', label: 'Appel manqué', column: 'Manqués', color: ['#c2410c', '#ffedd5', '#f97316'] },
        { key: 'reload', label: 'Rechargé pendant une sonnerie ou un appel', column: 'Rechargés', color: ['#b91c1c', '#fee2e2', '#dc2626'] },
        { key: 'closed', label: 'Page fermée ou autre page ouverte', column: 'Page fermée', color: ['#7e22ce', '#f3e8ff', '#9333ea'] },
        { key: 'phone', label: 'Téléphone laissé éteint (> 2 min hors pause)', column: 'Téléphone', color: ['#854d0e', '#fef9c3', '#eab308'] },
        { key: 'pin', label: 'PIN pas retapé (> 2 min)', column: 'PIN', color: ['#be185d', '#fce7f3', '#ec4899'] },
        { key: 'hangup', label: "Raccroché par l'agent", column: 'Raccrochés', color: ['#9f1239', '#ffe4e6', '#e11d48'] },
        { key: 'transfer', label: 'Transfert dans les 10 s', column: 'Transferts', color: ['#a21caf', '#fae8ff', '#d946ef'] },
        { key: 'hold', label: 'Client laissé en attente 20 min', column: 'Attente', color: ['#92400e', '#fef3c7', '#d97706'] },
        // Événement comportement que la page ne connaît pas encore : jamais perdu, son nom en détail
        { key: 'other', label: 'Autre', column: 'Autre', color: ['#4b5563', '#f3f4f6', '#9ca3af'] },
    ],
    technical: [
        { key: 'lost', label: 'Sonnerie perdue ou en retard', column: 'Sonneries', color: ['#0369a1', '#e0f2fe', '#0ea5e9'] },
        { key: 'breaker', label: 'Sorti des appels (disjoncteur)', column: 'Disjoncteur', color: ['#1e40af', '#dbeafe', '#2563eb'] },
        { key: 'ghost', label: 'Appel fantôme libéré', column: 'Fantômes', color: ['#0f766e', '#ccfbf1', '#14b8a6'] },
        { key: 'restart', label: 'Téléphone relancé', column: 'Relancés', color: ['#0e7490', '#cffafe', '#06b6d4'] },
        { key: 'net', label: 'Coupure réseau', column: 'Réseau', color: ['#334155', '#e2e8f0', '#64748b'] },
        { key: 'page', label: 'Page figée ou erreur', column: 'Page', color: ['#4338ca', '#e0e7ff', '#6366f1'] },
        { key: 'mic', label: 'Micro ou sonnerie bloqués', column: 'Micro / son', color: ['#3f6212', '#ecfccb', '#65a30d'] },
        { key: 'unpause', label: 'Levée de pause échouée', column: 'Pause', color: ['#155e75', '#cffafe', '#0891b2'] },
        { key: 'unverified', label: 'Sonnerie Voxnode non vérifiable', column: 'Non vérifiables', color: ['#4b5563', '#f3f4f6', '#9ca3af'] },
    ],
}
const KIND = Object.fromEntries([...KINDS.behaviour, ...KINDS.technical].map((k) => [k.key, k]))
const chipStyle = (kind) => ({ color: KIND[kind].color[0], background: KIND[kind].color[1] })
const countStyle = (kind, value) => (value ? { color: KIND[kind].color[0], fontWeight: 600 } : { color: '#d1d5db' })

const period = ref('today')
const center = ref('')
const family = ref('behaviour')
const active = ref({ behaviour: KINDS.behaviour.map((k) => k.key), technical: KINDS.technical.map((k) => k.key) })
const page = ref(1)
const data = ref(null)
const loading = ref(false)
const error = ref(null)

// Réponses dans le désordre (filtres changés vite) : seule la dernière demande est affichée
let lastRequest = 0
async function load() {
    const request = ++lastRequest
    loading.value = true
    const q = new URLSearchParams({ period: period.value, family: family.value, kinds: active.value[family.value].join(','), page: page.value })
    if (center.value) q.set('center', center.value)
    try {
        const res = await fetch(`/data/agent-alerts?${q}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const json = await res.json()
        if (request !== lastRequest) return
        data.value = json
        error.value = null
    } catch (e) {
        if (request === lastRequest) error.value = e.message
    } finally {
        if (request === lastRequest) loading.value = false
    }
}
watch([period, center, family, active], () => (page.value === 1 ? load() : (page.value = 1)), { deep: true })
watch(page, load)
onMounted(load)

function toggleKind(key) {
    const list = active.value[family.value]
    active.value[family.value] = list.includes(key) ? list.filter((k) => k !== key) : [...list, key]
}
const hasFilters = computed(() => period.value !== 'today' || !!center.value)
function reset() {
    period.value = 'today'
    center.value = ''
}
const familyTotal = (f) => KINDS[f].reduce((sum, k) => sum + (data.value?.counts?.[k.key] ?? 0), 0)
const centerLabel = (key) => props.centers.find((c) => c.key === key)?.label ?? '—'
const periodLabel = computed(() => PERIODS.find((p) => p.key === period.value)?.label ?? '')

// ─── Textes ───────────────────────────────────────────────────────────────────
const TZ = 'Europe/Paris'
const time = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit' })
// Sur plusieurs jours, la date en plus : « 12/11 16:48:02 »
const when = (iso) => (period.value === 'today' || period.value === 'yesterday' ? time(iso) : `${new Date(iso).toLocaleDateString('fr-FR', { timeZone: TZ, day: '2-digit', month: '2-digit' })} ${time(iso)}`)
// Dernière alerte : sur plusieurs jours, la date et l'heure sans les secondes
const lastWhen = (iso) => (period.value === 'today' || period.value === 'yesterday' ? time(iso) : when(iso).slice(0, -3))
// 45 s, 5 min 45, 1 h 12
function duration(s) {
    s = Math.max(0, Math.round(s ?? 0))
    if (s < 60) return `${s} s`
    if (s < 3600) return `${Math.floor(s / 60)} min${s % 60 ? ` ${String(s % 60).padStart(2, '0')}` : ''}`
    return `${Math.floor(s / 3600)} h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}`
}
// « ctrl+r » → « Ctrl+R »
const keyName = (k) => String(k).split('+').map((p) => (p.length === 1 ? p.toUpperCase() : p.charAt(0).toUpperCase() + p.slice(1))).join('+')
// État de l'agent quand une deuxième page a pris la main
const TAKEN_OVER_STATES = { ready: 'disponible', in_call: 'en appel', break: 'en pause', missed: 'écran PIN', phone_off: 'téléphone éteint' }
const ring = (d) => (d.ring_s != null ? `, après ${d.ring_s} s de sonnerie` : '')
const pin = (d) => (d.pin ? ' (PIN demandé)' : '')
// Étiquette d'une alerte : la sorte, ou plus précise quand l'événement le dit
const EVENT_LABELS = {
    page_closed_while_working: 'Page fermée pendant le travail',
    old_page_still_open: 'Ancienne page restée ouverte',
    page_taken_over: 'Deuxième page ouverte',
    page_closed_on_pin: 'Page fermée sur l’écran PIN',
    logout_on_pin: 'Déconnecté sur l’écran PIN',
    page_left_in_call: 'Page quittée en plein appel',
    missed_reload_during_ring: 'Rechargé pendant une sonnerie',
    reload_blocked: 'Rechargement bloqué',
    ring_not_received: 'Sonnerie non reçue',
    ring_late: 'Sonnerie en retard',
    phone_down: 'Téléphone déconnecté',
    microphone_unavailable: 'Micro bloqué',
    ringtone_blocked: 'Sonnerie bloquée',
}
const labelOf = (a) =>
    a.detail?.event === 'page_left_in_call' && a.detail.crashed
        ? 'Plantage pendant un appel'
        : (EVENT_LABELS[a.detail?.event] ?? KIND[a.kind].label.replace(/ \(.*\)$/, ''))
function detailOf(a) {
    const d = a.detail ?? {}
    const span = (what) => (d.until ? `${what} de ${time(a.at)} à ${time(d.until)} (${duration(d.seconds)})` : `${what} depuis ${time(a.at)} (${duration(d.seconds)})`)
    switch (d.event) {
        case 'missed_call':
            return `Sonnerie de ${d.ring_s ?? '?'} s non décrochée${d.from === 'queue' ? ', constaté par le journal de notre serveur' : ''}`
        case 'missed_reload_during_ring':
            return `${d.how === 'key' ? 'Touche F5 / Ctrl+R' : 'Bouton recharger ou page quittée'}${ring(d)} (PIN demandé)`
        case 'reload_blocked':
            return `Touche ${d.value ? keyName(d.value) : '?'}, rechargement bloqué`
        case 'page_closed_while_working':
            return 'Onglet fermé pendant le travail'
        case 'page_taken_over':
            return `Nouvelle page ouverte pendant que l’autre était encore active${TAKEN_OVER_STATES[d.state] ? ` (agent : ${TAKEN_OVER_STATES[d.state]})` : ''}`
        case 'page_closed_on_pin':
            return 'Page fermée pendant l’écran PIN (le PIN revient à son retour)'
        case 'page_left_in_call':
            return d.crashed ? 'Plantage du navigateur pendant un appel' : 'Rechargée ou fermée pendant un appel : le client a été coupé'
        case 'logout_on_pin':
            return 'Déconnexion pendant l’écran PIN (le PIN revient à la reconnexion)'
        case 'old_page_still_open':
            return 'Ancienne page laissée ouverte à côté de la nouvelle'
        case 'hold_too_long':
            return `Client raccroché par la page après ${d.hold_s != null ? duration(d.hold_s) : '20 min'} en attente`
        case 'phone_left_off':
            return span('Éteint')
        case 'pin_not_typed':
            return span('Écran PIN')
        case 'agent_hangup':
            return `Après ${d.after_s ?? '?'} s d’appel${d.reason ? ` · raison donnée : « ${d.reason} »` : ''}`
        case 'transfer_within_10s':
            return `Transféré ${d.after_s ?? '?'} s après le décroché${d.brand ? ` vers ${d.brand}` : ''}${d.number ? ` (${formatPhone(d.number)})` : ''}`
        case 'ring_not_received':
            return 'Le serveur a fait sonner, la page n’a rien reçu'
        case 'ring_late':
            return `Arrivée en retard à la page${d.page_ring_s != null ? ` (${d.page_ring_s} s de sonnerie vue)` : ''}`
        case 'ring_end_not_seen':
            return 'Fin de sonnerie jamais arrivée à la page'
        case 'ring_too_long':
            return `Fin de sonnerie perdue en route : la page a sonné ${d.ring_s ?? '?'} s sans fin (pas de PIN)`
        case 'ring_refused_busy':
            return 'Sonnerie refusée aussitôt (la page se croyait en appel)'
        case 'technical_circuit_breaker':
            return `${d.misses ?? '?'} sonneries perdues · sorti des appels`
        case 'ghost_call_released':
            // Libéré par la page seule (Voxnode, connexion audio perdue) ou par le serveur
            return d.by === 'page' ? 'Connexion audio perdue · appel libéré par la page' : 'Fin d’appel jamais arrivée à la page · libéré par le serveur'
        case 'phone_not_registered':
            return 'Notre serveur ne joignait plus le téléphone · relancé'
        case 'silent_while_working':
            return 'Plus aucun signe de la page'
        case 'silent_on_pin':
            return 'Plus aucun signe de la page pendant l’écran PIN (réseau ou ordinateur fermé)'
        case 'phone_down':
            return 'Le téléphone ne répondait plus au serveur'
        case 'js_error':
            return 'Erreur dans la page'
        case 'stalled':
            return 'Page bloquée'
        case 'frozen':
            return 'Page figée'
        case 'ring_lost_page_crashed':
            return 'Plantage pendant une sonnerie'
        case 'page_reloaded_after_crash':
            return 'Page rouverte après un plantage du navigateur'
        case 'microphone_unavailable':
            return 'Le navigateur refuse le micro'
        case 'ringtone_blocked':
            return 'Le navigateur bloque la sonnerie'
        case 'answer_failed':
            return 'Décroché impossible'
        case 'unpause_failed':
            return 'Le serveur téléphonique n’a pas levé la pause'
        case 'ring_unverifiable':
            return 'Voxnode : sonnerie vue par la page, sa fin jamais tracée'
        case 'missed_ring_not_seen':
            return 'Sonnerie manquée jamais vue par la page'
        case 'break_end_without_break':
            return 'Fin de pause sans pause enregistrée'
        // Jours avant le nouveau journal de présence
        case 'legacy_reload':
            return `${d.value ? `Touche ${keyName(d.value)}` : 'Bouton du navigateur'}${ring(d)}${pin(d)}`
        case 'legacy_crash':
            return `Page rechargée sans la quitter${ring(d)}${pin(d)}`
        case 'legacy_missed':
            return 'Sonnerie non décrochée'
        case 'legacy_missed_server':
            return 'Sonné sans réponse sur notre serveur, mis en pause par la file'
        case 'legacy_end_work_failed':
            return 'Fin de travail refusée'
        case 'legacy_end_break_failed':
            return 'Fin de pause refusée'
        default:
            return d.event ?? '—'
    }
}

// ─── Classement et liste ──────────────────────────────────────────────────────
// « Autre » (événement comportement inconnu) : case et colonne seulement s'il y en a sur la période
const shownKinds = computed(() => KINDS[family.value].filter((k) => k.key !== 'other' || data.value?.counts?.other))
const rankingColumns = computed(() => [
    // Centre en petit sous le nom (comme « Compris : » sous le nom tapé des marques demandées) : une colonne de moins
    { key: 'agent', label: 'Agent' },
    ...shownKinds.value.map((k) => ({ key: k.key, label: k.column })),
    ...(family.value === 'behaviour'
        ? [
              { key: 'answered', label: 'Décrochés' },
              { key: 'breakSec', label: 'Pauses' },
          ]
        : []),
    { key: 'last', label: 'Dernière' },
])
const rankingRows = computed(() => (data.value?.ranking ?? []).map((r) => ({ ...r, ...r.counts })))
const listColumns = [
    { key: 'at', label: 'Heure' },
    { key: 'agent', label: 'Agent' },
    { key: 'kind', label: 'Alerte' },
    { key: 'detail', label: 'Détail' },
]

// ─── Fenêtre d'un agent ──────────────────────────────────────────────────────
const openAgent = ref(null)
const agentData = ref(null)
const agentError = ref(null)
const thread = ref('all')
const agentOpen = computed({
    get: () => openAgent.value !== null,
    set: (open) => !open && (openAgent.value = null),
})
watch(openAgent, async (name) => {
    agentData.value = null
    agentError.value = null
    thread.value = 'all'
    if (name === null) return
    try {
        const res = await fetch(`/data/agent-alerts/agent?${new URLSearchParams({ agent: name, period: period.value })}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        if (openAgent.value === name) agentData.value = await res.json()
    } catch (e) {
        agentError.value = e.message
    }
})
const agentTotal = (f) => KINDS[f].reduce((sum, k) => sum + (agentData.value?.counts?.[k.key] ?? 0), 0)
const BREAK_TYPES = { pause: 'pause', lunch: 'déjeuner', manager: 'manager', other: 'autre' }
const breaksLine = computed(() =>
    Object.entries(agentData.value?.breakSec ?? {})
        .filter(([, s]) => s > 0)
        .map(([t, s]) => `${BREAK_TYPES[t]} ${duration(s)}`)
        .join(' · '),
)
const FAMILIES = { behaviour: 'Comportement', technical: 'Technique', info: 'Journée' }
const familyChip = { behaviour: 'bg-gray-900 text-white', technical: 'bg-gray-200 text-gray-700', info: 'bg-gray-100 text-gray-500' }
function infoText(a) {
    if (a.kind === 'start') return { label: 'Début de journée', detail: 'Connecté' }
    const d = a.detail
    return { label: `Pause (${BREAK_TYPES[d.type] ?? d.type ?? 'pause'})`, detail: `${time(a.at)} → ${d.until ? time(d.until) : 'en cours'} · ${duration(d.seconds)}` }
}
// Fil jour par jour, filtré : Tout, Comportement (et la journée), Technique (et la journée)
const agentDays = computed(() => {
    const days = []
    for (const a of agentData.value?.items ?? []) {
        if (thread.value !== 'all' && a.family !== 'info' && a.family !== thread.value) continue
        const key = new Date(a.at).toLocaleDateString('fr-CA', { timeZone: TZ })
        if (days.at(-1)?.key !== key) days.push({ key, title: new Date(a.at).toLocaleDateString('fr-FR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long' }), items: [] })
        days.at(-1).items.push(a)
    }
    return days
})

const choice = (on) => (on ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100')
</script>

<template>
    <Head title="Alertes agents" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Alertes agents' }]" />

            <FilterBar :has-active-filters="hasFilters" @reset="reset">
                <div class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2">
                    <span class="text-xs text-gray-500">Période</span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button v-for="p in PERIODS" :key="p.key" type="button" class="h-8 rounded-md px-3 text-xs font-medium transition-all" :class="choice(period === p.key)" @click="period = p.key">
                            {{ p.label }}
                        </button>
                    </div>
                    <span class="text-xs text-gray-500">Centre</span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="c in [{ key: '', label: 'Tous' }, ...centers]"
                            :key="c.key"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="choice(center === c.key)"
                            @click="center = c.key"
                        >
                            {{ c.label }}
                        </button>
                    </div>
                </div>
            </FilterBar>

            <!-- Deux familles, avec leur total sur la période -->
            <div class="flex items-center gap-1.5">
                <button
                    v-for="f in ['behaviour', 'technical']"
                    :key="f"
                    type="button"
                    class="flex h-8 items-center gap-2 rounded-md px-3 text-xs font-medium transition-all"
                    :class="choice(family === f)"
                    @click="family = f"
                >
                    {{ FAMILIES[f] }}
                    <span class="text-sm font-semibold tabular-nums">{{ data ? familyTotal(f) : '' }}</span>
                </button>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <div class="space-y-6" :class="loading && data && 'opacity-60'">
                <p class="text-sm text-gray-500">
                    <template v-if="family === 'behaviour'">Ce que l'agent aurait pu éviter. Un appel n'y figure que si la sonnerie est allée au bout et que sa page l'a vue en entier.</template>
                    <template v-else>Ce qui ne vient pas de l'agent : réseau, navigateur, serveur SIP, Voxnode, notre serveur. Jamais reproché à l'agent.</template>
                </p>

                <!-- Incidents collectifs : tous les agents à la fois, pas d'alerte individuelle -->
                <div
                    v-for="inc in family === 'technical' ? (data?.incidents ?? []) : []"
                    :key="inc.at"
                    class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
                >
                    <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-medium">Incident collectif · {{ when(inc.at) }}</p>
                        <p class="mt-0.5 text-amber-700">
                            <template v-if="inc.silent">{{ inc.silent }} agents silencieux en même temps{{ inc.active ? ` sur ${inc.active}` : '' }}</template>
                            <template v-if="inc.silent && inc.noRing"> · </template>
                            <template v-if="inc.noRing">{{ inc.noRing }} agents sans sonnerie{{ inc.onPbx ? ` sur ${inc.onPbx} sur notre serveur` : '' }}</template>
                            <template v-if="inc.seconds"> pendant {{ duration(inc.seconds) }}</template> : rien n'a été fermé, aucune alerte individuelle.
                        </p>
                    </div>
                </div>

                <!-- Une case par sorte : pastille de sa couleur et nombre sur la période. Retour à la ligne voulu : jusqu'à 9 sortes -->
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2" data-ui-ok>
                    <label v-for="k in shownKinds" :key="k.key" class="flex cursor-pointer items-center gap-2 text-sm">
                        <Checkbox :model-value="active[family].includes(k.key)" @update:model-value="toggleKind(k.key)" />
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: k.color[2] }" />
                        <span class="text-gray-700">{{ k.label }}</span>
                        <span class="font-semibold tabular-nums" :style="data?.counts?.[k.key] ? { color: k.color[0] } : {}">{{ data?.counts?.[k.key] ?? '' }}</span>
                    </label>
                </div>

                <!-- Par agent : du plus grand nombre d'alertes au plus petit ; un clic ouvre sa fenêtre -->
                <section>
                    <h2 class="mb-2 text-sm font-semibold text-gray-900">
                        Par agent <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.ranking.length : '' }}</span>
                    </h2>
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <DataTable
                            :columns="rankingColumns"
                            :rows="rankingRows"
                            :loading="!data && !error"
                            empty-message="Aucune alerte sur la période"
                            :row-class="(row) => (row.agent === openAgent ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')"
                            :row-click="(row) => (openAgent = row.agent)"
                        >
                            <template #agent="{ row }">
                                <div class="text-sm font-medium text-gray-900">{{ row.agent }}</div>
                                <div class="text-xs text-gray-500">{{ centerLabel(row.center) }}</div>
                            </template>
                            <template v-for="k in shownKinds" :key="k.key" #[k.key]="{ value }">
                                <span class="text-sm tabular-nums" :style="countStyle(k.key, value)">{{ value }}</span>
                            </template>
                            <template #answered="{ value }"><span class="text-sm tabular-nums text-gray-700">{{ value.toLocaleString('fr-FR') }}</span></template>
                            <template #breakSec="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ value ? duration(value) : '—' }}</span></template>
                            <template #last="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ lastWhen(value) }}</span></template>
                        </DataTable>
                    </div>
                </section>

                <!-- Alertes, la plus récente d'abord -->
                <section>
                    <h2 class="mb-2 text-sm font-semibold text-gray-900">
                        Alertes <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.total.toLocaleString('fr-FR') : '' }}</span>
                    </h2>
                    <div class="rounded-lg border border-gray-100 bg-white">
                        <DataTable :columns="listColumns" :rows="data?.items ?? []" :loading="!data && !error" empty-message="Aucune alerte sur la période">
                            <template #at="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ when(value) }}</span></template>
                            <template #agent="{ value }"><span class="text-sm text-gray-900">{{ value }}</span></template>
                            <template #kind="{ row }">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold whitespace-nowrap" :style="chipStyle(row.kind)">{{ labelOf(row) }}</span>
                            </template>
                            <template #detail="{ row }"><span class="text-sm text-gray-600">{{ detailOf(row) }}</span></template>
                        </DataTable>

                        <div v-if="data?.total" class="flex items-center justify-between border-t border-gray-50 px-4 py-3">
                            <div class="text-sm text-gray-500">
                                Page {{ data.page }} sur {{ data.lastPage }} · {{ data.total.toLocaleString('fr-FR') }} alerte{{ data.total !== 1 ? 's' : '' }}
                            </div>
                            <div class="flex gap-2">
                                <Button v-if="data.page > 1" variant="outline" size="sm" @click="page = data.page - 1">Précédent</Button>
                                <Button v-if="data.page < data.lastPage" variant="outline" size="sm" @click="page = data.page + 1">Suivant</Button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Fenêtre d'un agent -->
        <Dialog v-model:open="agentOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{{ openAgent }}</DialogTitle>
                    <DialogDescription>{{ agentData ? centerLabel(agentData.center) : '' }} · {{ periodLabel }}</DialogDescription>
                </DialogHeader>

                <p v-if="agentError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ agentError }}</p>
                <div v-else-if="!agentData" class="space-y-3">
                    <div v-for="i in 3" :key="i" class="h-12 animate-pulse rounded bg-gray-50" />
                </div>
                <div v-else class="space-y-6">
                    <!-- La période en chiffres -->
                    <div class="grid grid-cols-4 gap-3">
                        <div class="space-y-1 rounded-lg border border-gray-100 p-3">
                            <p class="text-xs text-gray-500">Travail</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900">{{ duration(agentData.workSec) }}</p>
                            <p class="text-xs text-gray-500">dont {{ duration(agentData.inCallSec) }} en appel</p>
                        </div>
                        <div class="space-y-1 rounded-lg border border-gray-100 p-3">
                            <p class="text-xs text-gray-500">Pauses</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900">{{ duration(Object.values(agentData.breakSec).reduce((a, b) => a + b, 0)) }}</p>
                            <p class="text-xs text-gray-500">{{ breaksLine || '—' }}</p>
                        </div>
                        <div class="space-y-1 rounded-lg border border-gray-100 p-3">
                            <p class="text-xs text-gray-500">Appels décrochés</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900">{{ agentData.answered.toLocaleString('fr-FR') }}</p>
                            <p class="text-xs text-gray-500">{{ agentData.counts.missed }} manqué{{ agentData.counts.missed > 1 ? 's' : '' }}</p>
                        </div>
                        <div class="space-y-1 rounded-lg border border-gray-100 p-3">
                            <p class="text-xs text-gray-500">Alertes</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900">
                                {{ agentTotal('behaviour') }} <span class="text-sm font-normal text-gray-500">comportement</span>
                            </p>
                            <p class="text-xs text-gray-500">{{ agentTotal('technical') }} technique{{ agentTotal('technical') > 1 ? 's' : '' }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button
                            v-for="[k, l] in [['all', 'Tout'], ['behaviour', 'Comportement'], ['technical', 'Technique']]"
                            :key="k"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="choice(thread === k)"
                            @click="thread = k"
                        >
                            {{ l }}
                        </button>
                    </div>

                    <!-- Fil jour par jour, le plus récent d'abord -->
                    <p v-if="!agentDays.length" class="text-sm text-gray-500">Rien sur la période</p>
                    <section v-for="d in agentDays" :key="d.key">
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 first-letter:uppercase">
                            {{ d.title }} <span class="ml-1 font-normal tabular-nums text-gray-400">{{ d.items.length }}</span>
                        </h3>
                        <div class="divide-y divide-gray-50 rounded-lg border border-gray-100">
                            <div v-for="a in d.items" :key="a.id" class="grid grid-cols-[5rem_8rem_15rem_1fr] items-baseline gap-3 px-4 py-2 text-sm">
                                <span class="tabular-nums text-gray-700">{{ time(a.at) }}</span>
                                <span><span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="familyChip[a.family]">{{ FAMILIES[a.family] }}</span></span>
                                <template v-if="a.family === 'info'">
                                    <span class="text-gray-500">{{ infoText(a).label }}</span>
                                    <span class="text-gray-500">{{ infoText(a).detail }}</span>
                                </template>
                                <template v-else>
                                    <span><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :style="chipStyle(a.kind)">{{ labelOf(a) }}</span></span>
                                    <span class="text-gray-600">{{ detailOf(a) }}</span>
                                </template>
                            </div>
                        </div>
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
