<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { useFilterOptions } from '@/composables/useFilterOptions'
import { User, PhoneForwarded } from 'lucide-vue-next'

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
    { key: 'called_at', label: 'Date', sortable: true },
    { key: 'caller', label: 'Appelant' },
    { key: 'called', label: 'Appelé' },
    { key: 'provider', label: 'Provider' },
    { key: 'company', label: 'Company' },
    { key: 'source', label: 'Source' },
    { key: 'brand_name', label: 'Marque', sortable: true },
    { key: 'agent_name', label: 'Agent', sortable: true },
    { key: 'carrier', label: 'Opé.', sortable: true },
    { key: 'duration', label: 'Durée', sortable: true },
    { key: 'payout', label: 'Payout', sortable: true },
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

function formatNumber(value) {
    if (value === null || value === undefined) return '-'
    // Format personnalisé : espace pour milliers, pas de décimales
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)
    // Remplacer les virgules par des espaces
    return formatted.replace(/,/g, ' ')
}

function formatCurrency(value) {
    if (value === null || value === undefined) return '-'
    // Format personnalisé : espace pour milliers, point pour décimales
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
    // Remplacer les virgules par des espaces
    return formatted.replace(/,/g, ' ')
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

// Mapping des carriers
const carrierMapping = {
    'BOUY': 'Bouygues',
    'FREE': 'Free',
    'FRMO': 'Free',
    'FRTE': 'Orange',
    'SFR0': 'SFR',
}

function getCarrierDisplayName(code) {
    return carrierMapping[code] || code
}

function formatAgentName(agentName) {
    if (!agentName) return '-'
    // Si format +33118500999@68.183.243.180, extraire "118500"
    const match = agentName.match(/^\+33(\d{6})\d*@/)
    if (match) {
        return match[1]
    }
    return agentName
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
                    <template #called_at="{ value, row }">
                        <Link
                            :href="`/calls/${row.id}`"
                            class="text-sm text-blue-600 hover:underline"
                        >
                            {{ formatDate(value) }}
                        </Link>
                    </template>
                    <template #duration="{ row }">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-gray-700">{{ formatDuration(row.total_duration) }}</span>
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <div class="flex items-center gap-0.5">
                                    <User class="h-3 w-3" />
                                    <span>{{ formatDuration(row.duration_agent) }}</span>
                                </div>
                                <div class="flex items-center gap-0.5">
                                    <PhoneForwarded class="h-3 w-3" />
                                    <span>{{ formatDuration(row.duration_transfer) }}</span>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template #payout="{ row }">
                        <div v-if="row.payout !== null && row.payout !== undefined" class="text-sm">
                            <span class="text-gray-500 text-xs">{{ formatCurrency(parseFloat(row.payout)) }} - {{ formatCurrency(parseFloat(row.payout_source || 0)) }} = </span>
                            <span class="">{{ formatCurrency(parseFloat(row.payout) - parseFloat(row.payout_source || 0)) }}</span>
                        </div>
                        <span v-else class="text-sm text-gray-400">-</span>
                    </template>
                    <template #caller="{ value }">
                        <span class="text-sm text-gray-700">{{ value || '-' }}</span>
                    </template>
                    <template #called="{ value }">
                        <span class="text-sm text-gray-700">{{ value || '-' }}</span>
                    </template>
                    <template #provider="{ row }">
                        <ColorBadge
                            v-if="row.phonenumber?.provider"
                            :color="row.phonenumber.provider.color"
                            :label="row.phonenumber.provider.name"
                        />
                        <span v-else class="text-xs text-gray-400">-</span>
                    </template>
                    <template #company="{ row }">
                        <ColorBadge
                            v-if="row.phonenumber?.company"
                            :color="row.phonenumber.company.color"
                            :label="row.phonenumber.company.name"
                        />
                        <span v-else class="text-xs text-gray-400">-</span>
                    </template>
                    <template #source="{ row }">
                        <ColorBadge
                            v-if="row.source"
                            :color="row.source.color"
                            :label="row.source.name"
                        />
                        <ColorBadge
                            v-else-if="row.phonenumber?.source"
                            :color="row.phonenumber.source.color"
                            :label="row.phonenumber.source.name"
                        />
                        <span v-else class="text-xs text-gray-400">-</span>
                    </template>
                    <template #brand_name="{ value }">
                        <span class="text-sm text-gray-900">{{ value || '-' }}</span>
                    </template>
                    <template #agent_name="{ value }">
                        <span class="text-xs text-gray-900">{{ formatAgentName(value) }}</span>
                    </template>
                    <template #carrier="{ value }">
                        <span class="text-xs text-gray-900">{{ getCarrierDisplayName(value) }}</span>
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
                                · {{ formatNumber(table.data.total) }} résultat{{ table.data.total !== 1 ? 's' : '' }}
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
