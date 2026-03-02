<script setup>
import { computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/example')

const { filters, reset } = useFilters(
    { search: '', status: '', sort: 'id', dir: 'desc' },
    (f) => table.load(f),
)

const hasFilters = computed(() => !!filters.search || !!filters.status)

function toggleSort(key) {
    if (filters.sort === key) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.sort = key
        filters.dir = 'asc'
    }
}

const columns = [
    { key: 'id',         label: 'ID',      sortable: true },
    { key: 'name',       label: 'Nom',     sortable: true },
    { key: 'status',     label: 'Statut' },
    { key: 'created_at', label: 'Créé le', sortable: true },
]

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader title="Dashboard" description="Vue d'ensemble">
                <template #actions>
                    <!-- Boutons d'action ici -->
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard label="Total"  :value="stats.data?.total"  :loading="stats.loading" />
                <StatCard label="Actifs" :value="stats.data?.active" :loading="stats.loading" />
                <StatCard label="Aujourd'hui" :value="stats.data?.today" :loading="stats.loading" />
                <StatCard label="Placeholder" value="—" />
            </div>

            <!-- Table -->
            <div class="rounded-lg border border-gray-100 bg-white">
                <FilterBar :has-active-filters="hasFilters" @reset="reset">
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher..."
                        class="h-8 rounded border border-gray-200 px-3 text-sm focus:border-gray-400 focus:outline-none"
                    />
                    <select
                        v-model="filters.status"
                        class="h-8 rounded border border-gray-200 px-3 text-sm focus:border-gray-400 focus:outline-none"
                    >
                        <option value="">Tous les statuts</option>
                        <option value="active">Actif</option>
                        <option value="inactive">Inactif</option>
                    </select>
                </FilterBar>

                <DataTable
                    :columns="columns"
                    :rows="table.data?.items ?? []"
                    :loading="table.loading"
                    :sort-key="filters.sort"
                    :sort-dir="filters.dir"
                    @sort="toggleSort"
                >
                    <!-- Slot custom par colonne : surcharge n'importe quelle colonne par son key -->
                    <template #status="{ value }">
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="value === 'active'
                                ? 'bg-green-50 text-green-700'
                                : 'bg-gray-100 text-gray-500'"
                        >
                            {{ value }}
                        </span>
                    </template>
                </DataTable>

                <div v-if="table.data?.total" class="border-t border-gray-50 px-4 py-2 text-xs text-gray-400">
                    {{ table.data.total }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                </div>
            </div>
        </div>
    </AppLayout>
</template>
