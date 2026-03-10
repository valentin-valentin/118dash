<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { useFilterOptions } from '@/composables/useFilterOptions'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/calls/stats')

// ─── Filter Options ───────────────────────────────────────────────────────────
const {
    options: filterOptions,
    isLoading: isLoadingOptions,
    isClearing: isClearingCache,
    load: loadFilterOptions,
    clearCache: clearFilterCache,
} = useFilterOptions('/data/calls/filter-options', {
    brands: [],
    agents: [],
    callcenters: [],
    carriers: [],
    who_hangup: [],
    ratings_reviewers: [],
    blacklists: [],
})

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/calls')

const { filters, reset } = useFilters(
    {
        caller: '',
        called: '',
        brand_name: [],
        agent_name: [],
        callcenter_id: [],
        date_from: '',
        date_to: '',
        duration_min: '',
        duration_max: '',
        carrier: [],
        who_hangup: [],
        has_rating: '',
        ratings_warning: '',
        ratings_danger: '',
        ratings_not_rated: '',
        ratings_reviewer: [],
        phone_agent_hangup: '',
        phone_reported_by_agent: '',
        phone_cant_provide_service: '',
        blacklist_id: '',
        sort: 'called_at',
        dir: 'desc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.caller ||
    !!filters.called ||
    (Array.isArray(filters.brand_name) && filters.brand_name.length > 0) ||
    (Array.isArray(filters.agent_name) && filters.agent_name.length > 0) ||
    (Array.isArray(filters.callcenter_id) && filters.callcenter_id.length > 0) ||
    !!filters.date_from ||
    !!filters.date_to ||
    !!filters.duration_min ||
    !!filters.duration_max ||
    (Array.isArray(filters.carrier) && filters.carrier.length > 0) ||
    (Array.isArray(filters.who_hangup) && filters.who_hangup.length > 0) ||
    !!filters.has_rating ||
    !!filters.ratings_warning ||
    !!filters.ratings_danger ||
    !!filters.ratings_not_rated ||
    (Array.isArray(filters.ratings_reviewer) && filters.ratings_reviewer.length > 0) ||
    !!filters.phone_agent_hangup ||
    !!filters.phone_reported_by_agent ||
    !!filters.phone_cant_provide_service ||
    !!filters.blacklist_id
)

function toggleSort(key) {
    if (filters.sort === key) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.sort = key
        filters.dir = 'asc'
    }
}

const columns = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'called_at', label: 'Date', sortable: true },
    { key: 'caller', label: 'Appelant' },
    { key: 'called', label: 'Appelé' },
    { key: 'brand_name', label: 'Marque', sortable: true },
    { key: 'agent_name', label: 'Agent', sortable: true },
    { key: 'total_duration', label: 'Durée', sortable: true },
    { key: 'duration_agent', label: 'Durée agent', sortable: true },
    { key: 'payout', label: 'Payout', sortable: true },
    { key: 'who_hangup', label: 'Qui raccroche' },
]

function formatDate(dateString) {
    if (!dateString) return '-'
    const date = new Date(dateString)
    return date.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function formatDuration(seconds) {
    if (!seconds) return '-'
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}

function getRowClass(row) {
    return row.blacklist_id ? 'bg-red-50 hover:bg-red-100/80' : ''
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    loadFilterOptions()
    table.load(filters)
})
</script>

<template>
    <Head title="Appels" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Appels' }
                ]"
            />

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard label="Aujourd'hui" :value="stats.data?.today" :loading="stats.loading" />
                <StatCard label="Cette semaine" :value="stats.data?.this_week" :loading="stats.loading" />
                <StatCard label="Ce mois" :value="stats.data?.this_month" :loading="stats.loading" />
                <StatCard
                    label="Durée moy. mois"
                    :value="stats.data?.avg_duration_month ? formatDuration(stats.data.avg_duration_month) : '-'"
                    :loading="stats.loading"
                />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :is-loading="isLoadingOptions"
                :is-clearing="isClearingCache"
                :show-clear-cache="true"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
                @clear-cache="() => clearFilterCache('/data/calls/clear-filter-cache')"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    <!-- Appelant (input libre) -->
                    <Input
                        v-model="filters.caller"
                        type="text"
                        placeholder="N° Appelant..."
                        class="h-8"
                    />

                    <!-- Appelé (input libre) -->
                    <Input
                        v-model="filters.called"
                        type="text"
                        placeholder="N° Appelé..."
                        class="h-8"
                    />

                    <!-- Marque (select avec recherche) -->
                    <FilterSelect
                        v-model="filters.brand_name"
                        :options="filterOptions.brands"
                        placeholder="Toutes les marques"
                        searchable
                        multiple
                    />

                    <!-- Agent (select avec recherche) -->
                    <FilterSelect
                        v-model="filters.agent_name"
                        :options="filterOptions.agents"
                        placeholder="Tous les agents"
                        searchable
                        multiple
                    />

                    <!-- Call Center -->
                    <FilterSelect
                        v-model="filters.callcenter_id"
                        :options="filterOptions.callcenters"
                        placeholder="Tous les centres"
                        multiple
                    />

                    <!-- Opérateur -->
                    <FilterSelect
                        v-model="filters.carrier"
                        :options="filterOptions.carriers"
                        placeholder="Tous les opérateurs"
                        multiple
                    />

                    <!-- Qui raccroche -->
                    <FilterSelect
                        v-model="filters.who_hangup"
                        :options="filterOptions.who_hangup"
                        placeholder="Qui raccroche"
                        multiple
                    />

                    <!-- Date début -->
                    <Input
                        v-model="filters.date_from"
                        type="date"
                        class="h-8"
                    />

                    <!-- Date fin -->
                    <Input
                        v-model="filters.date_to"
                        type="date"
                        class="h-8"
                    />

                    <!-- Durée min -->
                    <Input
                        v-model="filters.duration_min"
                        type="number"
                        placeholder="Durée min (s)"
                        class="h-8"
                    />

                    <!-- Durée max -->
                    <Input
                        v-model="filters.duration_max"
                        type="number"
                        placeholder="Durée max (s)"
                        class="h-8"
                    />

                    <!-- A un rating -->
                    <FilterSelect
                        v-model="filters.has_rating"
                        :options="[
                            { value: 'yes', label: 'Avec rating' },
                            { value: 'no', label: 'Sans rating' },
                        ]"
                        placeholder="Rating"
                        :searchable="false"
                    />

                    <!-- Rating reviewer -->
                    <FilterSelect
                        v-model="filters.ratings_reviewer"
                        :options="filterOptions.ratings_reviewers"
                        placeholder="Évaluateur"
                        multiple
                    />

                    <!-- Warning -->
                    <FilterSelect
                        v-model="filters.ratings_warning"
                        :options="[
                            { value: 'yes', label: 'Avec warning' },
                            { value: 'no', label: 'Sans warning' },
                        ]"
                        placeholder="Warning"
                        :searchable="false"
                    />

                    <!-- Danger -->
                    <FilterSelect
                        v-model="filters.ratings_danger"
                        :options="[
                            { value: 'yes', label: 'Avec danger' },
                            { value: 'no', label: 'Sans danger' },
                        ]"
                        placeholder="Danger"
                        :searchable="false"
                    />

                    <!-- Non noté -->
                    <FilterSelect
                        v-model="filters.ratings_not_rated"
                        :options="[
                            { value: 'yes', label: 'Non noté' },
                            { value: 'no', label: 'Noté' },
                        ]"
                        placeholder="Non noté"
                        :searchable="false"
                    />

                    <!-- Agent a raccroché -->
                    <FilterSelect
                        v-model="filters.phone_agent_hangup"
                        :options="[
                            { value: 'yes', label: 'Agent a raccroché' },
                            { value: 'no', label: 'Agent n\'a pas raccroché' },
                        ]"
                        placeholder="Agent raccroche"
                        :searchable="false"
                    />

                    <!-- Signalé par agent -->
                    <FilterSelect
                        v-model="filters.phone_reported_by_agent"
                        :options="[
                            { value: 'yes', label: 'Signalé par agent' },
                            { value: 'no', label: 'Non signalé' },
                        ]"
                        placeholder="Signalé"
                        :searchable="false"
                    />

                    <!-- Service non fourni -->
                    <FilterSelect
                        v-model="filters.phone_cant_provide_service"
                        :options="[
                            { value: 'yes', label: 'Service non fourni' },
                            { value: 'no', label: 'Service fourni' },
                        ]"
                        placeholder="Service"
                        :searchable="false"
                    />

                    <!-- Blacklist -->
                    <FilterSelect
                        v-model="filters.blacklist_id"
                        :options="[
                            { value: 'any', label: 'Avec blacklist' },
                            { value: 'none', label: 'Sans blacklist' },
                            ...filterOptions.blacklists.map(b => ({ value: b.id, label: b.phonenumber }))
                        ]"
                        placeholder="Blacklist"
                        searchable
                    />
                </div>
            </FilterBar>

            <!-- Table -->
            <div class="rounded-lg border border-gray-100 bg-white">
                <DataTable
                    :columns="columns"
                    :rows="table.data?.items ?? []"
                    :loading="table.loading"
                    :sort-key="filters.sort"
                    :sort-dir="filters.dir"
                    :row-class="getRowClass"
                    @sort="toggleSort"
                >
                    <template #id="{ value, row }">
                        <Link
                            :href="`/calls/${row.id}`"
                            class="text-blue-600 hover:underline"
                        >
                            #{{ value }}
                        </Link>
                    </template>
                    <template #called_at="{ value }">
                        <span class="text-sm">{{ formatDate(value) }}</span>
                    </template>
                    <template #total_duration="{ value }">
                        <span class="font-mono text-sm">{{ formatDuration(value) }}</span>
                    </template>
                    <template #duration_agent="{ value }">
                        <span class="font-mono text-sm">{{ formatDuration(value) }}</span>
                    </template>
                    <template #payout="{ value }">
                        <span class="font-mono text-sm">{{ value ? parseFloat(value).toFixed(2) + '€' : '-' }}</span>
                    </template>
                    <template #caller="{ value }">
                        <span class="font-mono text-xs">{{ value || '-' }}</span>
                    </template>
                    <template #called="{ value }">
                        <span class="font-mono text-xs">{{ value || '-' }}</span>
                    </template>
                </DataTable>

                <div
                    v-if="table.data?.items?.length"
                    class="flex items-center justify-between border-t border-gray-50 px-4 py-3"
                >
                    <div class="flex items-center gap-4">
                        <div class="text-sm text-gray-500">
                            <template v-if="table.data.last_page">
                                Page {{ table.data.current_page }} sur {{ table.data.last_page }}
                            </template>
                            <template v-else>
                                Page {{ table.data.current_page }}
                            </template>
                            <template v-if="hasFilters && table.data.total">
                                · {{ table.data.total.toLocaleString() }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                            </template>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Résultats par page :</span>
                            <select
                                v-model.number="filters.per_page"
                                class="rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="250">250</option>
                                <option :value="500">500</option>
                                <option :value="1000">1000</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button
                            v-if="table.data.current_page > 1"
                            @click="filters.page = (filters.page || 1) - 1"
                            class="cursor-pointer rounded border px-3 py-1 text-sm hover:bg-gray-50"
                        >
                            Précédent
                        </button>
                        <button
                            v-if="table.data.last_page ? table.data.current_page < table.data.last_page : table.data.has_more_pages"
                            @click="filters.page = (filters.page || 1) + 1"
                            class="cursor-pointer rounded border px-3 py-1 text-sm hover:bg-gray-50"
                        >
                            Suivant
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
