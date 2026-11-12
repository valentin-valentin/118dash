<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { FileText, LoaderCircle, Pause, Play, X } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import CallPlayButtons from '@/components/CallPlayButtons.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { agentLabel, formatDay, formatDuration, formatPhone, WHO_HANGUP } from '@/lib/calls'
import { formatQuality, qualityClass } from '@/lib/quality'

/**
 * Appels à vérifier : réécouter vite les appels qui posent question.
 *   Filtres : période, agent, et les cas (avec leur nombre sur la période), tous actifs par défaut
 *   Par agent : nombre de chaque cas, appels à vérifier, appels décrochés ; un clic choisit l'agent dans la liste
 *   Tableau : un appel par ligne avec tous ses cas ; un clic sur la ligne ouvre sa fenêtre, seul ▶ lance l'écoute
 *   Lecteur en bas : celui de la page Appels, seulement pour écouter
 *   Fenêtre d'un appel (clic sur la ligne, ou « Détail » dans le lecteur) : ses cas en entier, son déroulé, le réseau de
 *   l'agent (audio mauvais) et l'appelant : liste noire, nombre d'appels, ses autres appels avec leurs cas
 */
const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: 'week', label: '7 jours' },
    { key: 'month', label: '30 jours' },
]
// column : colonne du tableau « Par agent »
const CASES = [
    { key: 'reported', label: "Signalé par l'agent", column: 'Signalés' },
    { key: 'hangup', label: "Raccroché par l'agent", column: 'Raccrochés' },
    { key: 'blacklist', label: 'Liste noire', column: 'Liste noire' },
    { key: 'against', label: 'Transfert contre la suggestion', column: 'Contre la suggestion' },
    { key: 'audio', label: 'Audio mauvais', column: 'Audio mauvais' },
]
const caseLabel = Object.fromEntries(CASES.map((c) => [c.key, c.label]))
// Signalé par l'agent : mis en avant en rouge partout (pastille, nombres) ; les autres cas en gris
const casePill = (key) => (key === 'reported' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700')

const period = ref('today')
const agent = ref('')
const active = ref(CASES.map((c) => c.key))
const page = ref(1)
const data = ref(null)
const loading = ref(false)
const error = ref(null)

// Réponses dans le désordre (filtres changés vite) : seule la dernière demande est affichée
let lastRequest = 0
async function load() {
    const request = ++lastRequest
    loading.value = true
    const q = new URLSearchParams({ period: period.value, cases: active.value.join(','), page: page.value })
    if (agent.value) q.set('agent', agent.value)
    try {
        const res = await fetch(`/data/calls-to-review?${q}`, { headers: { Accept: 'application/json' } })
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
watch([period, agent, active], () => (page.value === 1 ? load() : (page.value = 1)), { deep: true })
watch(page, load)
onMounted(load)

// Cas proposés : « Transfert contre la suggestion » seulement quand la colonne existe (nombre non nul)
const caseFilters = computed(() => CASES.filter((c) => data.value?.counts?.[c.key] !== null))
function toggleCase(key) {
    active.value = active.value.includes(key) ? active.value.filter((k) => k !== key) : [...active.value, key]
}
const hasFilters = computed(() => period.value !== 'today' || !!agent.value || active.value.length !== CASES.length)
function reset() {
    period.value = 'today'
    agent.value = ''
    active.value = CASES.map((c) => c.key)
}

// Numéro appelé : 118 500, sinon une ligne support (même règle que le poste des agents)
const CALLED = { 118500: '118 500', 11876: '11876', RTBE: 'RTBE' }
const calledLabel = (n) => CALLED[n] ?? 'Support'
// 19/10 21:22 : l'année n'apporte rien sur 30 jours au plus
const when = (iso) => new Date(iso).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(' ', ' ')
function detailOf(c, row) {
    if (c.key === 'against') return `Backup proposé → transféré vers ${row.brand || '—'}${c.toInternal ? ' (renvoyé au service interne)' : ''}`
    if (c.key === 'audio') return formatQuality(c.mos)
    return c.detail || '—'
}

// Par agent : un clic choisit l'agent dans la liste des appels (le tableau reste entier, la ligne surlignée) et y descend ;
// un nouveau clic retire le choix
const rankingColumns = computed(() => [
    { key: 'agent', label: 'Agent' },
    ...caseFilters.value.map((c) => ({ key: c.key, label: c.column })),
    { key: 'review', label: 'À vérifier' },
    { key: 'answered', label: 'Appels décrochés' },
])
const rankingRows = computed(() => (data.value?.ranking ?? []).map((r) => ({ ...r, ...r.counts })))
const rankingRowClass = (row) => (row.agent === agent.value ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')
const callsSection = ref(null)
function pickAgent(row) {
    agent.value = agent.value === row.agent ? '' : row.agent
    if (agent.value) callsSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const columns = [
    { key: 'at', label: 'Date' },
    { key: 'agent', label: 'Agent' },
    { key: 'called', label: 'Appelé' },
    { key: 'caller', label: 'Appelant' },
    { key: 'cases', label: 'Cas' },
    { key: 'detail', label: 'Détail' },
    { key: 'brand', label: 'Marque' },
    { key: 'duration_agent', label: 'Durée agent' },
    { key: 'record', label: 'Conversation' },
]

const historyColumns = [
    { key: 'at', label: 'Date' },
    { key: 'agent', label: 'Agent' },
    { key: 'brand', label: 'Marque' },
    { key: 'duration_agent', label: 'Durée agent' },
    { key: 'cases', label: 'Cas' },
    { key: 'record', label: 'Conversation' },
]

// Un clic sur une ligne ouvre la fenêtre de l'appel, sans lancer le son (seuls les boutons ▶ l'écoutent).
// Ligne sélectionnée = le dernier appel ouvert, pour retrouver où on en est dans la liste après la fenêtre.
const lastOpenedId = ref(null)
const selectedRowClass = (row) => (row.id === lastOpenedId.value ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')
function openRow(row) {
    lastOpenedId.value = row.id
    reviewId.value = row.id
}

// ─── Lecteur : le même que la page Appels (un seul enregistrement à la fois, en bas de la page) ──
const audio = typeof Audio !== 'undefined' ? new Audio() : null
const player = reactive({ call: null, part: 'agent', playing: false, current: 0, duration: 0, rate: 1, loading: false, error: null })
const RATES = [1, 1.25, 1.5, 2]
if (audio) {
    audio.preload = 'auto'
    audio.addEventListener('timeupdate', () => (player.current = audio.currentTime))
    audio.addEventListener('loadedmetadata', () => (player.duration = audio.duration))
    audio.addEventListener('play', () => (player.playing = true))
    audio.addEventListener('pause', () => (player.playing = false))
    audio.addEventListener('waiting', () => (player.loading = true))
    audio.addEventListener('playing', () => (player.loading = false))
    audio.addEventListener('error', () => {
        player.loading = false
        player.error = "Impossible de lire l'enregistrement"
    })
    // Fin de la conversation : le transfert suit, s'il est enregistré
    audio.addEventListener('ended', () => {
        if (player.part === 'agent' && player.call?.record_transfer) play(player.call, 'transfer')
    })
}
function play(call, part = 'agent') {
    if (!audio) return
    const url = part === 'transfer' ? call.record_transfer : call.record_agent
    if (!url) return
    if (player.call?.id === call.id && player.part === part) {
        audio.paused ? audio.play() : audio.pause()
        return
    }
    Object.assign(player, { call, part, current: 0, duration: 0, error: null, loading: true })
    audio.src = url
    audio.playbackRate = player.rate
    audio.play().catch(() => {})
}
function seek(e) {
    if (audio && player.duration) audio.currentTime = Number(e.target.value)
}
function cycleRate() {
    player.rate = RATES[(RATES.indexOf(player.rate) + 1) % RATES.length]
    if (audio) audio.playbackRate = player.rate
}
function closePlayer() {
    audio?.pause()
    player.call = null
}
const isPlaying = (row, part) => player.call?.id === row.id && player.part === part && player.playing

// ─── Fenêtre d'un appel : ce qui aide à le juger (cas, déroulé, réseau, appelant et ses autres appels) ──
const reviewId = ref(null)
const review = ref(null)
const reviewError = ref(null)
const reviewOpen = computed({
    get: () => reviewId.value !== null,
    set: (open) => !open && (reviewId.value = null),
})
watch(reviewId, async (id) => {
    review.value = null
    reviewError.value = null
    if (id === null) return
    try {
        const res = await fetch(`/data/calls-to-review/${id}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        if (reviewId.value === id) review.value = await res.json()
    } catch (e) {
        reviewError.value = e.message
    }
})
// Clic dans le lecteur pendant que la fenêtre est ouverte : la fenêtre reste ouverte
function keepOpenForPlayer(e) {
    if (e.target?.closest?.('[data-player]')) e.preventDefault()
}
const ms = (v) => (v == null ? '—' : `${Math.round(v)} ms`)
const pctOf = (v) => (v == null ? '—' : `${String(Number(v)).replace('.', ',')} %`)
const dayOf = (iso) => new Date(iso).toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', year: 'numeric' })
onUnmounted(() => audio?.pause())

</script>

<template>
    <Head title="Appels à vérifier" />

    <AppLayout>
        <div class="space-y-6 p-6" :class="player.call && 'pb-28'">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Appels à vérifier' }]" />

            <FilterBar :has-active-filters="hasFilters" @reset="reset">
                <div class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2">
                    <span class="text-xs text-gray-500">Période</span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="p in PERIODS"
                            :key="p.key"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="period === p.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="period = p.key"
                        >
                            {{ p.label }}
                        </button>
                    </div>
                    <span class="text-xs text-gray-500">Agent</span>
                    <div class="w-56"><FilterSelect v-model="agent" :options="(data?.agents ?? []).map((a) => ({ value: a, label: agentLabel(a) ?? a }))" placeholder="Tous les agents" searchable /></div>
                    <span class="text-xs text-gray-500">Cas</span>
                    <!-- Plusieurs cas à la fois : une case à cocher par cas, avec son nombre d'appels sur la période -->
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <label v-for="c in caseFilters" :key="c.key" class="flex cursor-pointer items-center gap-2 text-sm">
                            <Checkbox :model-value="active.includes(c.key)" @update:model-value="toggleCase(c.key)" />
                            <span class="text-gray-700">{{ c.label }}</span>
                            <span class="font-semibold tabular-nums" :class="c.key === 'reported' && data?.counts?.reported ? 'text-red-600' : 'text-gray-900'">{{ data?.counts?.[c.key] ?? '' }}</span>
                        </label>
                    </div>
                </div>
            </FilterBar>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <!-- Par agent : du plus grand nombre d'appels à vérifier au plus petit -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Par agent <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.ranking.length : '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white" :class="loading && data && 'opacity-60'">
                    <DataTable
                        :columns="rankingColumns"
                        :rows="rankingRows"
                        :loading="!data && !error"
                        empty-message="Aucun appel à vérifier sur la période"
                        :row-class="rankingRowClass"
                        :row-click="pickAgent"
                    >
                        <template #agent="{ value }"><span class="text-sm font-medium text-gray-900">{{ agentLabel(value) ?? value }}</span></template>
                        <template v-for="c in caseFilters" :key="c.key" #[c.key]="{ value }">
                            <span class="text-sm tabular-nums" :class="!value ? 'text-gray-300' : c.key === 'reported' ? 'font-semibold text-red-600' : 'text-gray-900'">{{ value }}</span>
                        </template>
                        <template #review="{ value }"><span class="text-sm font-semibold tabular-nums text-gray-900">{{ value }}</span></template>
                        <template #answered="{ value }"><span class="text-sm tabular-nums text-gray-700">{{ value.toLocaleString('fr-FR') }}</span></template>
                    </DataTable>
                </div>
            </section>

            <section ref="callsSection" class="scroll-mt-20">
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.total.toLocaleString('fr-FR') : '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white" :class="loading && data && 'opacity-60'">
                    <DataTable
                        :columns="columns"
                        :rows="data?.items ?? []"
                        :loading="!data && !error"
                        empty-message="Aucun appel à vérifier sur la période"
                        :row-class="selectedRowClass"
                        :row-click="openRow"
                    >
                        <template #at="{ row }"><span class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ when(row.at) }}</span></template>
                        <template #agent="{ value }"><span class="text-sm whitespace-nowrap text-gray-900">{{ agentLabel(value) ?? '—' }}</span></template>
                        <template #called="{ value }"><span class="text-sm whitespace-nowrap text-gray-700">{{ calledLabel(value) }}</span></template>
                        <template #caller="{ value }"><span class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ value ? formatPhone(value) : '—' }}</span></template>
                        <!-- Cas et détail : une ligne par cas, alignées d'une colonne à l'autre -->
                        <template #cases="{ row }">
                            <div class="flex flex-col items-start gap-1">
                                <span v-for="c in row.cases" :key="c.key" :class="casePill(c.key)" class="inline-flex h-6 items-center rounded-full px-2 text-xs font-medium whitespace-nowrap">{{ caseLabel[c.key] }}</span>
                            </div>
                        </template>
                        <template #detail="{ row }">
                            <!-- Assez large pour qu'un motif court tienne sur sa ligne, en face de sa pastille -->
                            <div class="flex min-w-48 flex-col gap-1">
                                <span v-for="c in row.cases" :key="c.key" class="flex min-h-6 items-center text-sm" :class="c.key === 'audio' ? ['whitespace-nowrap', qualityClass(c.mos) || 'text-gray-900'] : 'text-gray-900'">{{ detailOf(c, row) }}</span>
                            </div>
                        </template>
                        <template #brand="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                        <template #duration_agent="{ value }"><span class="text-sm text-gray-700 tabular-nums">{{ formatDuration(value) }}</span></template>
                        <template #record="{ row }"><CallPlayButtons :row="row" :is-playing="isPlaying" @play="play" /></template>
                    </DataTable>

                    <div v-if="data?.total" class="flex items-center justify-between border-t border-gray-50 px-4 py-3">
                        <div class="text-sm text-gray-500">
                            Page {{ data.page }} sur {{ data.lastPage }} · {{ data.total.toLocaleString('fr-FR') }} appel{{ data.total !== 1 ? 's' : '' }}
                        </div>
                        <div class="flex gap-2">
                            <Button v-if="data.page > 1" variant="outline" size="sm" @click="page = data.page - 1">Précédent</Button>
                            <Button v-if="data.page < data.lastPage" variant="outline" size="sm" @click="page = data.page + 1">Suivant</Button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Lecteur (même barre que la page Appels) -->
        <!-- Fenêtre ouverte : le lecteur passe devant son fond sombre et reste cliquable (la fenêtre bloque le reste de la page) -->
        <div
            v-if="player.call"
            data-player
            class="fixed inset-x-0 bottom-0 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur md:left-64"
            :class="reviewOpen ? 'pointer-events-auto z-[60]' : 'z-40'"
        >
            <div class="flex items-center gap-3 py-2.5 pr-20 pl-4">
                <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-900 text-white hover:bg-gray-800" :title="player.playing ? 'Pause' : 'Lecture'" @click="play(player.call, player.part)">
                    <LoaderCircle v-if="player.loading" class="size-4 animate-spin" />
                    <Pause v-else-if="player.playing" class="size-4" />
                    <Play v-else class="ml-0.5 size-4" />
                </button>
                <div class="w-56 min-w-0 shrink-0">
                    <div class="truncate text-sm font-medium text-gray-900">{{ player.call.caller ? formatPhone(player.call.caller) : '—' }} · {{ agentLabel(player.call.agent) ?? '—' }}</div>
                    <div class="truncate text-xs text-gray-500">
                        {{ formatDay(player.call.at) }}, {{ new Date(player.call.at).toLocaleTimeString('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit' }) }}
                        · {{ player.part === 'transfer' ? 'transfert' : 'conversation agent' }}
                    </div>
                </div>
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-gray-500">{{ formatDuration(player.current) }}</span>
                <input type="range" min="0" :max="player.duration || 0" step="0.1" :value="player.current" class="min-w-0 flex-1 accent-gray-900" aria-label="Position" @input="seek" />
                <span class="w-10 shrink-0 text-xs tabular-nums text-gray-500">{{ formatDuration(player.duration) }}</span>
                <p v-if="player.error" class="shrink-0 text-xs text-red-600">{{ player.error }}</p>
                <div class="flex shrink-0 items-center gap-1.5">
                    <button
                        v-if="player.call.record_transfer && player.call.record_agent"
                        type="button"
                        class="h-7 rounded-md border px-2 text-xs"
                        :class="player.part === 'transfer' ? 'border-gray-900 text-gray-900' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                        @click="play(player.call, player.part === 'transfer' ? 'agent' : 'transfer')"
                    >
                        {{ player.part === 'transfer' ? 'Partie agent' : 'Partie transfert' }}
                    </button>
                    <button type="button" class="h-7 w-12 rounded-md border border-gray-200 text-xs tabular-nums text-gray-700 hover:bg-gray-50" title="Vitesse de lecture" @click="cycleRate">{{ String(player.rate).replace('.', ',') }}×</button>
                    <button type="button" class="flex h-7 items-center gap-1 rounded-md border border-gray-200 px-2 text-xs text-gray-700 hover:bg-gray-50" @click="reviewId = player.call.id"><FileText class="size-3.5" /> Détail</button>
                    <button type="button" class="flex size-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Fermer le lecteur" @click="closePlayer"><X class="size-4" /></button>
                </div>
            </div>
        </div>


        <!-- Fenêtre d'un appel -->
        <!-- Fenêtre avec son fond sombre ; le lecteur reste devant et utilisable, un clic dessus ne ferme pas la fenêtre.
             Hauteur limitée pour ne pas passer sous le lecteur -->
        <Dialog v-model:open="reviewOpen">
            <DialogContent class="max-h-[calc(100vh-10rem)] overflow-y-auto sm:max-w-4xl" @interact-outside="keepOpenForPlayer">
                <DialogHeader>
                    <DialogTitle>{{ review ? `Appel du ${when(review.call.at)}` : 'Appel' }}</DialogTitle>
                    <DialogDescription v-if="review" as="div" class="flex items-center gap-4">
                        <span>{{ agentLabel(review.call.agent) ?? 'Sans agent' }} · {{ review.call.caller ? formatPhone(review.call.caller) : 'Appelant inconnu' }} vers {{ calledLabel(review.call.called) }} · Marque : {{ review.call.brand || '—' }}</span>
                        <CallPlayButtons :row="review.call" :is-playing="isPlaying" @play="play" />
                    </DialogDescription>
                </DialogHeader>

                <p v-if="reviewError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ reviewError }}</p>
                <div v-else-if="!review" class="space-y-3">
                    <div v-for="i in 3" :key="i" class="h-16 animate-pulse rounded bg-gray-50" />
                </div>
                <div v-else class="space-y-6">
                    <!-- Cas : le motif en entier ; pour l'audio, le réseau de l'agent pendant l'appel et sa journée -->
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">Cas</h3>
                        <div class="divide-y divide-gray-50 rounded-lg border border-gray-100">
                            <div v-for="c in review.call.cases" :key="c.key" class="grid grid-cols-[13rem_1fr] gap-3 px-4 py-3 text-sm">
                                <span><span class="inline-flex rounded-full px-2 py-1 text-xs font-medium" :class="casePill(c.key)">{{ caseLabel[c.key] }}</span></span>
                                <div class="space-y-1">
                                    <p :class="c.key === 'audio' ? qualityClass(c.mos) || 'text-gray-900' : 'text-gray-900'">{{ detailOf(c, review.call) }}</p>
                                    <template v-if="c.key === 'audio'">
                                        <p v-if="review.quality" class="text-gray-600">
                                            Pertes reçues {{ pctOf(review.quality.loss_pct) }} · envoyées {{ pctOf(review.quality.sent_loss_pct) }} · gigue max {{ ms(review.quality.jitter_max_ms) }} · aller-retour {{ ms(review.quality.rtt_avg_ms) }} (max {{ ms(review.quality.rtt_max_ms) }})
                                        </p>
                                        <p v-if="review.agentDay" class="text-gray-600">
                                            {{ agentLabel(review.call.agent) }} ce jour-là : {{ review.agentDay.bad }} appel{{ review.agentDay.bad > 1 ? 's' : '' }} en audio mauvais sur {{ review.agentDay.measured }} mesurés, note moyenne {{ formatQuality(review.agentDay.mos) }}
                                        </p>
                                    </template>
                                    <p v-if="c.key === 'against'" class="text-gray-600">Durée du transfert : {{ formatDuration(review.call.duration_transfer) }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Déroulé -->
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">Déroulé</h3>
                        <dl class="flex flex-wrap gap-x-8 gap-y-2 rounded-lg border border-gray-100 px-4 py-3 text-sm">
                            <div><dt class="inline text-gray-500">Durée totale</dt> <dd class="ml-1 inline tabular-nums text-gray-900">{{ formatDuration(review.call.total_duration) }}</dd></div>
                            <div><dt class="inline text-gray-500">Avec l'agent</dt> <dd class="ml-1 inline tabular-nums text-gray-900">{{ formatDuration(review.call.duration_agent) }}</dd></div>
                            <div><dt class="inline text-gray-500">Transfert</dt> <dd class="ml-1 inline tabular-nums text-gray-900">{{ formatDuration(review.call.duration_transfer) }}</dd></div>
                            <div><dt class="inline text-gray-500">Raccroché par</dt> <dd class="ml-1 inline text-gray-900">{{ WHO_HANGUP[review.call.who_hangup] ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    <!-- Appelant : liste noire, ses autres appels (les plus récents) avec leurs cas -->
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">
                            Appelant <span class="ml-1 font-normal text-gray-500">{{ review.callerTotal.toLocaleString('fr-FR') }} appel{{ review.callerTotal > 1 ? 's' : '' }} au total</span>
                        </h3>
                        <p v-if="review.blacklist" class="mb-2 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            Sur liste noire depuis le {{ review.blacklist.created_at ? dayOf(review.blacklist.created_at) : '—' }}<template v-if="review.blacklist.note"> : {{ review.blacklist.note }}</template><template v-if="review.blacklist.source"> ({{ review.blacklist.source }})</template>
                        </p>
                        <div class="rounded-lg border border-gray-100">
                            <DataTable :columns="historyColumns" :rows="review.history" empty-message="Aucun autre appel de ce numéro">
                                <template #at="{ row }"><span class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ dayOf(row.at) }} {{ when(row.at).slice(-5) }}</span></template>
                                <template #agent="{ value }"><span class="text-sm whitespace-nowrap text-gray-900">{{ agentLabel(value) ?? '—' }}</span></template>
                                <template #brand="{ value }"><span class="text-sm text-gray-700">{{ value || '—' }}</span></template>
                                <template #duration_agent="{ value }"><span class="text-sm text-gray-700 tabular-nums">{{ formatDuration(value) }}</span></template>
                                <template #record="{ row }"><CallPlayButtons :row="row" :is-playing="isPlaying" @play="play" /></template>
                                <template #cases="{ row }">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="c in row.cases" :key="c.key" class="inline-flex rounded-full px-2 py-1 text-xs font-medium whitespace-nowrap" :class="casePill(c.key)" :title="detailOf(c, row)">{{ caseLabel[c.key] }}</span>
                                        <span v-if="!row.cases.length" class="text-sm text-gray-400">—</span>
                                    </div>
                                </template>
                            </DataTable>
                        </div>
                    </section>
                </div>

                <DialogFooter v-if="review">
                    <Button variant="outline" as-child><Link :href="`/calls/${review.call.id}`">Ouvrir la page de l'appel</Link></Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
