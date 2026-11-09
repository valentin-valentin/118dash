<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'

/**
 * Alertes agents : quel agent pose problème, et de quelle façon.
 *   Filtres : période, centre, sortes d'alerte (avec leur nombre)
 *   Par agent : une ligne par agent, du plus grave au moins grave ; un clic ouvre la fenêtre de ses alertes
 *   (le classement reste entier, la ligne choisie surlignée)
 *   Alertes : la liste, la plus récente d'abord
 */
const props = defineProps({ centers: { type: Array, default: () => [] } })

const PERIODS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: 'week', label: '7 jours' },
    { key: 'month', label: '30 jours' },
]
// Dans l'ordre de gravité
// label : liste des alertes ; filter : case à cocher ; column : colonne du classement
const KINDS = [
    { key: 'f5', label: 'Rechargé au clavier', filter: 'Rechargé au clavier', column: 'Clavier' },
    { key: 'reload', label: 'Rechargé (bouton ou extension)', filter: 'Rechargé (bouton ou extension)', column: 'Bouton' },
    { key: 'crash', label: 'Plantage du navigateur', filter: 'Plantage du navigateur', column: 'Plantage' },
    { key: 'missed', label: 'Appel manqué', filter: 'Appel manqué', column: 'Appels manqués' },
    { key: 'endfail', label: 'Échec de fin de travail ou de pause', filter: 'Échec de fin', column: 'Échecs de fin' },
]
const kindLabel = Object.fromEntries(KINDS.map((k) => [k.key, k.label]))

const period = ref('today')
const center = ref('')
const active = ref(KINDS.map((k) => k.key))
const page = ref(1)
const data = ref(null)
const loading = ref(false)
const error = ref(null)

async function load() {
    loading.value = true
    const q = new URLSearchParams({ period: period.value, kinds: active.value.join(','), page: page.value })
    if (center.value) q.set('center', center.value)
    try {
        const res = await fetch(`/data/agent-alerts?${q}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}
watch([period, center, active], () => (page.value === 1 ? load() : (page.value = 1)), { deep: true })
watch(page, load)
onMounted(load)

function toggleKind(key) {
    active.value = active.value.includes(key) ? active.value.filter((k) => k !== key) : [...active.value, key]
}
const hasFilters = computed(() => period.value !== 'today' || !!center.value || active.value.length !== KINDS.length)
function reset() {
    period.value = 'today'
    center.value = ''
    active.value = KINDS.map((k) => k.key)
}
const centerLabel = (key) => props.centers.find((c) => c.key === key)?.label ?? '—'

// Heure de Paris : « 19/10 16:02:11 »
const when = (iso) => new Date(iso).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
// Rechargement pendant une sonnerie : « après N s de sonnerie », et « (PIN demandé) » quand l'écran PIN a suivi
const ring = (d) => [d.ring == null ? null : `après ${d.ring} s de sonnerie`, d.pin ? '(PIN demandé)' : null].filter(Boolean).join(' ')
const withRing = (text, d) => [text, ring(d)].filter(Boolean).join(', ').replace(', (', ' (')
// « ctrl+r » → « Ctrl+R », « F5 » → « F5 », « meta+r » → « Meta+R »
const keyName = (k) => String(k).split('+').map((p) => (p.length === 1 ? p.toUpperCase() : p.charAt(0).toUpperCase() + p.slice(1))).join('+')
function detailOf(a) {
    const d = a.detail
    if (a.kind === 'f5') return withRing(`Touche ${keyName(d.key)}`, d)
    if (a.kind === 'reload') {
        const text = ring(d)
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : '—'
    }
    if (a.kind === 'crash') return withRing('Page rechargée sans la quitter', d)
    if (a.kind === 'missed') return d.origin === 'server' ? 'Sonné sans réponse sur notre serveur, mis en pause par la file' : 'Sonnerie non décrochée'
    return d.what === 'break' ? 'Fin de pause' : 'Fin de travail'
}

// Classement : un clic sur un agent ouvre la fenêtre de ses alertes
const rankingColumns = [
    { key: 'agent', label: 'Agent' },
    { key: 'center', label: 'Centre' },
    ...KINDS.map((k) => ({ key: k.key, label: k.column })),
    { key: 'answered', label: 'Appels décrochés' },
    { key: 'last', label: 'Dernière alerte' },
]
const rankingRows = computed(() => (data.value?.ranking ?? []).map((r) => ({ ...r, ...r.counts })))
const selectedRowClass = (row) => (row.agent === openAgent.value ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')

// ─── Fenêtre d'un agent : ses nombres, puis toutes ses alertes de la période, jour par jour ──
const openAgent = ref(null)
const agentData = ref(null)
const agentError = ref(null)
const agentOpen = computed({
    get: () => openAgent.value !== null,
    set: (open) => !open && (openAgent.value = null),
})
watch(openAgent, async (name) => {
    agentData.value = null
    agentError.value = null
    if (name === null) return
    try {
        const res = await fetch(`/data/agent-alerts/agent?${new URLSearchParams({ agent: name, period: period.value })}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        if (openAgent.value === name) agentData.value = await res.json()
    } catch (e) {
        agentError.value = e.message
    }
})
const periodLabel = computed(() => PERIODS.find((p) => p.key === period.value)?.label ?? '')
const dayKey = (iso) => new Date(iso).toLocaleDateString('fr-CA', { timeZone: 'Europe/Paris' })
const dayTitle = (iso) => new Date(iso).toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris', weekday: 'long', day: 'numeric', month: 'long' })
const timeOf = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const agentDays = computed(() => {
    const days = []
    for (const a of agentData.value?.items ?? []) {
        const key = dayKey(a.at)
        if (days.at(-1)?.key !== key) days.push({ key, title: dayTitle(a.at), items: [] })
        days.at(-1).items.push(a)
    }
    return days
})

const listColumns = [
    { key: 'at', label: 'Date' },
    { key: 'agent', label: 'Agent' },
    { key: 'kind', label: 'Alerte' },
    { key: 'detail', label: 'Détail' },
]
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
                    <span class="text-xs text-gray-500">Centre</span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="c in [{ key: '', label: 'Tous' }, ...centers]"
                            :key="c.key"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                            :class="center === c.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="center = c.key"
                        >
                            {{ c.label }}
                        </button>
                    </div>
                    <span class="text-xs text-gray-500">Alertes</span>
                    <!-- Plusieurs sortes à la fois : une case par sorte, avec son nombre sur la période -->
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <label v-for="k in KINDS" :key="k.key" class="flex cursor-pointer items-center gap-2 text-sm">
                            <Checkbox :model-value="active.includes(k.key)" @update:model-value="toggleKind(k.key)" />
                            <span class="text-gray-700">{{ k.filter }}</span>
                            <span class="font-semibold tabular-nums" :class="k.key === 'f5' && data?.counts?.f5 ? 'text-red-600' : 'text-gray-900'">{{ data?.counts?.[k.key] ?? '' }}</span>
                        </label>
                    </div>
                </div>
            </FilterBar>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <!-- Par agent : du plus grave au moins grave -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Par agent <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.ranking.length : '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white" :class="loading && data && 'opacity-60'">
                    <DataTable
                        :columns="rankingColumns"
                        :rows="rankingRows"
                        :loading="!data && !error"
                        empty-message="Aucune alerte sur la période"
                        :row-class="selectedRowClass"
                        :row-click="(row) => (openAgent = row.agent)"
                    >
                        <template #agent="{ value }"><span class="text-sm font-medium text-gray-900">{{ value }}</span></template>
                        <template #center="{ value }"><span class="text-sm text-gray-600">{{ centerLabel(value) }}</span></template>
                        <template v-for="k in KINDS" :key="k.key" #[k.key]="{ value }">
                            <span class="text-sm tabular-nums" :class="!value ? 'text-gray-300' : k.key === 'f5' ? 'font-semibold text-red-600' : 'text-gray-900'">{{ value }}</span>
                        </template>
                        <template #answered="{ value }"><span class="text-sm tabular-nums text-gray-700">{{ value.toLocaleString('fr-FR') }}</span></template>
                        <template #last="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ when(value) }}</span></template>
                    </DataTable>
                </div>
            </section>

            <!-- Alertes, la plus récente d'abord -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Alertes <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data ? data.total.toLocaleString('fr-FR') : '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white" :class="loading && data && 'opacity-60'">
                    <DataTable :columns="listColumns" :rows="data?.items ?? []" :loading="!data && !error" empty-message="Aucune alerte sur la période">
                        <template #at="{ value }"><span class="text-sm whitespace-nowrap tabular-nums text-gray-700">{{ when(value) }}</span></template>
                        <template #agent="{ value }"><span class="text-sm text-gray-900">{{ value }}</span></template>
                        <template #kind="{ value }">
                            <span class="text-sm whitespace-nowrap" :class="value === 'f5' ? 'font-semibold text-red-600' : 'text-gray-900'">{{ kindLabel[value] }}</span>
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
                    <!-- Nombres : la ligne du classement -->
                    <dl class="flex flex-wrap gap-x-8 gap-y-2 rounded-lg border border-gray-100 px-4 py-3 text-sm">
                        <div v-for="k in KINDS" :key="k.key">
                            <dt class="inline text-gray-500">{{ k.column }}</dt>
                            <dd class="ml-1.5 inline font-semibold tabular-nums" :class="!agentData.counts[k.key] ? 'text-gray-300' : k.key === 'f5' ? 'text-red-600' : 'text-gray-900'">{{ agentData.counts[k.key] }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-gray-500">Appels décrochés</dt>
                            <dd class="ml-1.5 inline font-semibold tabular-nums text-gray-900">{{ agentData.answered.toLocaleString('fr-FR') }}</dd>
                        </div>
                    </dl>

                    <!-- Alertes jour par jour, la plus récente d'abord -->
                    <p v-if="!agentDays.length" class="text-sm text-gray-500">Aucune alerte sur la période</p>
                    <section v-for="d in agentDays" :key="d.key">
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 first-letter:uppercase">
                            {{ d.title }} <span class="ml-1 font-normal tabular-nums text-gray-400">{{ d.items.length }}</span>
                        </h3>
                        <div class="divide-y divide-gray-50 rounded-lg border border-gray-100">
                            <div v-for="a in d.items" :key="a.id" class="grid grid-cols-[5rem_16rem_1fr] gap-3 px-4 py-2 text-sm">
                                <span class="tabular-nums text-gray-700">{{ timeOf(a.at) }}</span>
                                <span :class="a.kind === 'f5' ? 'font-semibold text-red-600' : 'text-gray-900'">{{ kindLabel[a.kind] }}</span>
                                <span class="text-gray-600">{{ detailOf(a) }}</span>
                            </div>
                        </div>
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
