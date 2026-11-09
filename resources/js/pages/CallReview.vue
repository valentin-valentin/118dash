<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { FileText, LoaderCircle, Pause, PhoneForwarded, Play, X } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import CallDetailPanel from '@/components/CallDetailPanel.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { agentLabel, formatDay, formatDuration, formatPhone } from '@/lib/calls'
import { formatQuality, qualityClass } from '@/lib/quality'

/**
 * Appels à vérifier : réécouter vite les appels qui posent question.
 *   Filtres : période, agent, et les cas (avec leur nombre sur la période), tous actifs par défaut
 *   Tableau : un appel par ligne avec tous ses cas ; ▶ écoute la conversation (et le transfert s'il est enregistré)
 *   Lecteur en bas de page et fiche de l'appel : les mêmes que la page Appels
 */
const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: 'week', label: '7 jours' },
    { key: 'month', label: '30 jours' },
]
const CASES = [
    { key: 'reported', label: "Signalé par l'agent" },
    { key: 'hangup', label: "Raccroché par l'agent" },
    { key: 'blacklist', label: 'Liste noire' },
    { key: 'against', label: 'Transfert contre la suggestion' },
    { key: 'audio', label: 'Audio mauvais' },
]
const caseLabel = Object.fromEntries(CASES.map((c) => [c.key, c.label]))

const period = ref('today')
const agent = ref('')
const active = ref(CASES.map((c) => c.key))
const page = ref(1)
const data = ref(null)
const loading = ref(false)
const error = ref(null)

async function load() {
    loading.value = true
    const q = new URLSearchParams({ period: period.value, cases: active.value.join(','), page: page.value })
    if (agent.value) q.set('agent', agent.value)
    try {
        const res = await fetch(`/data/calls-to-review?${q}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
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
    if (c.key === 'against') return c.toBackup ? 'Marque proposée → transféré vers Backup' : `Backup proposé → transféré vers ${row.brand || '—'}`
    if (c.key === 'audio') return formatQuality(c.mos)
    return c.detail || '—'
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

// Ligne sélectionnée = l'appel ouvert dans le lecteur. Un clic sur la ligne l'écoute (la conversation, sinon le transfert) ;
// sans enregistrement, il ouvre la fiche de l'appel.
const selectedRowClass = (row) => (row.id === player.call?.id ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')
function openRow(row) {
    if (row.record_agent) play(row, 'agent')
    else if (row.record_transfer) play(row, 'transfer')
    else openId.value = row.id
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
onUnmounted(() => audio?.pause())

// Fiche de l'appel : le panneau de la page Appels
const openId = ref(null)
const sheetOpen = computed({
    get: () => openId.value !== null,
    set: (open) => !open && (openId.value = null),
})
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
                            <span class="font-semibold tabular-nums text-gray-900">{{ data?.counts?.[c.key] ?? '' }}</span>
                        </label>
                    </div>
                </div>
            </FilterBar>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <section>
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
                        <!-- Date : ouvre la fiche de l'appel (même panneau que la page Appels) -->
                        <template #at="{ row }">
                            <button type="button" class="text-sm whitespace-nowrap text-gray-700 tabular-nums underline decoration-gray-300 underline-offset-2 hover:text-gray-900" title="Fiche de l'appel" @click.stop="openId = row.id">{{ when(row.at) }}</button>
                        </template>
                        <template #agent="{ value }"><span class="text-sm whitespace-nowrap text-gray-900">{{ agentLabel(value) ?? '—' }}</span></template>
                        <template #called="{ value }"><span class="text-sm whitespace-nowrap text-gray-700">{{ calledLabel(value) }}</span></template>
                        <template #caller="{ value }"><span class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ value ? formatPhone(value) : '—' }}</span></template>
                        <!-- Cas et détail : une ligne par cas, alignées d'une colonne à l'autre -->
                        <template #cases="{ row }">
                            <div class="flex flex-col items-start gap-1">
                                <span v-for="c in row.cases" :key="c.key" class="inline-flex h-6 items-center rounded-full bg-gray-100 px-2 text-xs font-medium whitespace-nowrap text-gray-700">{{ caseLabel[c.key] }}</span>
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
                        <template #record="{ row }">
                            <div v-if="row.record_agent || row.record_transfer" class="flex items-center gap-1">
                                <button
                                    v-if="row.record_agent"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                    :class="isPlaying(row, 'agent') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                    :title="isPlaying(row, 'agent') ? 'Pause' : 'Écouter la conversation'"
                                    @click.stop="play(row, 'agent')"
                                >
                                    <Pause v-if="isPlaying(row, 'agent')" class="size-3.5" />
                                    <Play v-else class="size-3.5" />
                                    Agent
                                </button>
                                <button
                                    v-if="row.record_transfer"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                    :class="isPlaying(row, 'transfer') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                    :title="isPlaying(row, 'transfer') ? 'Pause' : 'Écouter le transfert'"
                                    @click.stop="play(row, 'transfer')"
                                >
                                    <Pause v-if="isPlaying(row, 'transfer')" class="size-3.5" />
                                    <PhoneForwarded v-else class="size-3.5" />
                                    Transfert
                                </button>
                            </div>
                            <span v-else class="text-sm whitespace-nowrap text-gray-400">Pas d'enregistrement</span>
                        </template>
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
        <div v-if="player.call" class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur md:left-64">
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
                    <button type="button" class="flex h-7 items-center gap-1 rounded-md border border-gray-200 px-2 text-xs text-gray-700 hover:bg-gray-50" @click="openId = player.call.id"><FileText class="size-3.5" /> Fiche</button>
                    <button type="button" class="flex size-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Fermer le lecteur" @click="closePlayer"><X class="size-4" /></button>
                </div>
            </div>
        </div>

        <!-- Fiche d'un appel (même panneau que la page Appels) -->
        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader class="sr-only">
                    <SheetTitle>Fiche de l'appel</SheetTitle>
                    <SheetDescription>Enregistrements et détail de l'appel</SheetDescription>
                </SheetHeader>
                <div class="px-6 pt-6 pb-10">
                    <CallDetailPanel v-if="openId" :call-id="openId" in-panel @open="(id) => (openId = id)" />
                </div>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
