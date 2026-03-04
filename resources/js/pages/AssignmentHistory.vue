<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/assignment-history/stats')

// ─── Filter Options ──────────────────────────────────────────────────────────
const filterOptions = useApi('/data/assignment-history/filter-options')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/assignment-history')

const { filters, reset } = useFilters(
    {
        search: '',
        start_date: '',
        end_date: '',
        source_id: '',
        company_id: '',
        provider_id: '',
        sort: 'start_at',
        dir: 'desc',
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.search ||
    !!filters.start_date ||
    !!filters.end_date ||
    !!filters.source_id ||
    !!filters.company_id ||
    !!filters.provider_id
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
    { key: 'phonenumber', label: 'Numéro' },
    { key: 'source', label: 'Source' },
    { key: 'company', label: 'Company' },
    { key: 'provider', label: 'Provider' },
    { key: 'start_at', label: 'Début', sortable: true },
    { key: 'end_at', label: 'Fin', sortable: true },
    { key: 'endpoint', label: 'Endpoint' },
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

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    filterOptions.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Assignment History" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Assignment History', href: '/assignment-history' }
                ]"
            />

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Aujourd'hui" :value="stats.data?.today" :loading="stats.loading" />
                <StatCard label="Cette semaine" :value="stats.data?.this_week" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar :has-active-filters="hasFilters" @reset="reset">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher numéro..."
                        class="h-8"
                    />

                    <Input
                        v-model="filters.start_date"
                        type="date"
                        placeholder="Date début"
                        class="h-8"
                    />

                    <Input
                        v-model="filters.end_date"
                        type="date"
                        placeholder="Date fin"
                        class="h-8"
                    />

                    <FilterSelect
                        v-if="filterOptions.data?.sources"
                        v-model="filters.source_id"
                        :options="filterOptions.data.sources"
                        placeholder="Source"
                        searchable
                    />

                    <FilterSelect
                        v-if="filterOptions.data?.companies"
                        v-model="filters.company_id"
                        :options="filterOptions.data.companies"
                        placeholder="Company"
                        searchable
                    />

                    <FilterSelect
                        v-if="filterOptions.data?.providers"
                        v-model="filters.provider_id"
                        :options="filterOptions.data.providers"
                        placeholder="Provider"
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
                    @sort="toggleSort"
                >
                    <template #phonenumber="{ row }">
                        <span class="font-mono text-sm">{{ row.phonenumber?.phonenumber || '-' }}</span>
                    </template>

                    <template #source="{ row }">
                        <span class="text-sm">{{ row.source?.name || '-' }}</span>
                    </template>

                    <template #company="{ row }">
                        <span class="text-sm">{{ row.company?.name || '-' }}</span>
                    </template>

                    <template #provider="{ row }">
                        <span class="text-sm">{{ row.provider?.name || '-' }}</span>
                    </template>

                    <template #start_at="{ value }">
                        <span class="text-sm">{{ formatDate(value) }}</span>
                    </template>

                    <template #end_at="{ value }">
                        <span class="text-sm">{{ formatDate(value) }}</span>
                    </template>

                    <template #endpoint="{ value }">
                        <span class="font-mono text-xs text-gray-500">{{ value || '-' }}</span>
                    </template>
                </DataTable>

                <div v-if="table.data?.total" class="border-t border-gray-50 px-4 py-2 text-xs text-gray-400">
                    {{ table.data.total }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                </div>
            </div>
        </div>
    </AppLayout>
</template>
