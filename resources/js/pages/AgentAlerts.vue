<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'

/**
 * Alertes agents : quel agent pose problème, et de quelle façon.
 *   Filtres : période, centre, agent, sortes d'alerte (avec leur nombre)
 *   Par agent : une ligne par agent, du plus grave au moins grave ; un clic filtre sur cet agent
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
    { key: 'f5', label: 'F5 pendant une sonnerie', filter: 'F5 pendant une sonnerie', column: 'F5' },
    { key: 'reload', label: 'Rechargé pendant une sonnerie', filter: 'Rechargement', column: 'Rechargements' },
    { key: 'crash', label: 'Plantage du navigateur', filter: 'Plantage', column: 'Plantages' },
    { key: 'missed', label: 'Appel manqué', filter: 'Appel manqué', column: 'Appels manqués' },
    { key: 'endfail', label: 'Échec de fin de travail ou de pause', filter: 'Échec de fin', column: 'Échecs de fin' },
]
const kindLabel = Object.fromEntries(KINDS.map((k) => [k.key, k.label]))

const period = ref('today')
const center = ref('')
const agent = ref('')
const active = ref(KINDS.map((k) => k.key))
const page = ref(1)
const data = ref(null)
const loading = ref(false)
const error = ref(null)

async function load() {
    loading.value = true
    const q = new URLSearchParams({ period: period.value, kinds: active.value.join(','), page: page.value })
    if (center.value) q.set('center', center.value)
    if (agent.value) q.set('agent', agent.value)
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
watch([period, center, agent, active], () => (page.value === 1 ? load() : (page.value = 1)), { deep: true })
watch(page, load)
onMounted(load)

function toggleKind(key) {
    active.value = active.value.includes(key) ? active.value.filter((k) => k !== key) : [...active.value, key]
}
const hasFilters = computed(() => period.value !== 'today' || !!center.value || !!agent.value || active.value.length !== KINDS.length)
function reset() {
    period.value = 'today'
    center.value = ''
    agent.value = ''
    active.value = KINDS.map((k) => k.key)
}
const centerLabel = (key) => props.centers.find((c) => c.key === key)?.label ?? '—'

// Heure de Paris : « 19/10 16:02:11 »
const when = (iso) => new Date(iso).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const ring = (s) => (s == null ? '' : `, après ${s} s de sonnerie`)
function detailOf(a) {
    const d = a.detail
    if (a.kind === 'f5') return `Touche ${d.key}${ring(d.ring)}`
    if (a.kind === 'reload') return `Bouton du navigateur ou extension${ring(d.ring)}`
    if (a.kind === 'crash') return `Page rechargée sans la quitter${ring(d.ring)}`
    if (a.kind === 'missed') return d.origin === 'server' ? 'Sonné sans réponse sur notre serveur, mis en pause par la file' : 'Sonnerie non décrochée'
    return d.what === 'break' ? 'Fin de pause' : 'Fin de travail'
}

// Classement : un clic sur un agent filtre la page sur lui ; un nouveau clic retire le filtre
const rankingColumns = [
    { key: 'agent', label: 'Agent' },
    { key: 'center', label: 'Centre' },
    ...KINDS.map((k) => ({ key: k.key, label: k.column })),
    { key: 'answered', label: 'Appels décrochés' },
    { key: 'last', label: 'Dernière alerte' },
]
const rankingRows = computed(() => (data.value?.ranking ?? []).map((r) => ({ ...r, ...r.counts })))
const selectedRowClass = (row) => (row.agent === agent.value ? 'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]' : '')

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
                    <span class="text-xs text-gray-500">Agent</span>
                    <div class="w-56"><FilterSelect v-model="agent" :options="(data?.agents ?? []).map((a) => ({ value: a, label: a }))" placeholder="Tous les agents" searchable /></div>
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
                        :row-click="(row) => (agent = agent === row.agent ? '' : row.agent)"
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
    </AppLayout>
</template>
