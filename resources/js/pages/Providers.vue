<script setup>
import { computed, onMounted } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil } from 'lucide-vue-next'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/providers/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/providers')

const { filters, reset } = useFilters(
    {
        search: '',
        enabled: '',
        sort: 'name',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.search ||
    !!filters.enabled
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
    { key: 'name', label: 'Nom', sortable: true },
    { key: 'driver', label: 'Driver', sortable: true },
    { key: 'payout', label: 'Payout', sortable: true },
    { key: 'enabled', label: 'Statut', sortable: true },
    { key: 'created_at', label: 'Créé le', sortable: true },
    { key: 'actions', label: 'Actions' },
]

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Providers" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Providers' }
                ]"
            >
                <template #actions>
                    <Button as-child>
                        <Link href="/providers/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouveau provider
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Actifs" :value="stats.data?.active" :loading="stats.loading" />
                <StatCard label="Inactifs" :value="stats.data?.inactive" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
                    <!-- Recherche par nom ou driver -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher par nom ou driver..."
                        class="h-8"
                    />

                    <!-- Statut -->
                    <FilterSelect
                        v-model="filters.enabled"
                        :options="[
                            { value: 'yes', label: 'Actifs' },
                            { value: 'no', label: 'Inactifs' },
                        ]"
                        placeholder="Tous les statuts"
                        :searchable="false"
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
                    <template #id="{ value }">
                        <span class="font-mono text-xs text-gray-500">#{{ value }}</span>
                    </template>

                    <template #name="{ row }">
                        <ColorBadge :color="row.color" :label="row.name" />
                    </template>

                    <template #driver="{ value }">
                        <span class="font-mono text-sm text-gray-600">{{ value }}</span>
                    </template>

                    <template #payout="{ value }">
                        <span class="text-sm text-gray-600">{{ value ? `${value} €` : '-' }}</span>
                    </template>

                    <template #created_at="{ value }">
                        <span class="text-sm text-gray-600">
                            {{ value ? new Date(value).toLocaleDateString('fr-FR') : '-' }}
                        </span>
                    </template>

                    <template #enabled="{ value }">
                        <span
                            class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                            :class="value ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'"
                        >
                            {{ value ? 'Actif' : 'Inactif' }}
                        </span>
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/providers/${row.id}/edit`">
                                <Pencil class="h-4 w-4" />
                            </Link>
                        </Button>
                    </template>
                </DataTable>

                <div
                    v-if="table.data?.total"
                    class="flex items-center justify-between border-t border-gray-50 px-4 py-3"
                >
                    <div class="text-sm text-gray-500">
                        Page {{ table.data.current_page }} sur {{ table.data.last_page }}
                        · {{ table.data.total.toLocaleString() }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="table.data.current_page > 1"
                            variant="outline"
                            size="sm"
                            @click="filters.page = (filters.page || 1) - 1"
                        >
                            Précédent
                        </Button>
                        <Button
                            v-if="table.data.current_page < table.data.last_page"
                            variant="outline"
                            size="sm"
                            @click="filters.page = (filters.page || 1) + 1"
                        >
                            Suivant
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
