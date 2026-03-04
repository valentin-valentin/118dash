<script setup>
import { computed, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil } from 'lucide-vue-next'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/blacklists/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/blacklists')

const { filters, reset } = useFilters(
    {
        search: '',
        sort: 'phonenumber',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() => !!filters.search)

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
    { key: 'phonenumber', label: 'Numéro', sortable: true },
    { key: 'source', label: 'Source', sortable: true },
    { key: 'note', label: 'Note' },
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
    <Head title="Blacklists" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Blacklists' }
                ]"
            >
                <template #actions>
                    <Button as-child>
                        <Link href="/blacklists/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle blacklist
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
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
                    <!-- Recherche -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher..."
                        class="h-8"
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

                    <template #phonenumber="{ value }">
                        <span class="font-mono font-medium text-gray-900">{{ value }}</span>
                    </template>

                    <template #source="{ value }">
                        <span class="text-sm text-gray-600">{{ value || '-' }}</span>
                    </template>

                    <template #note="{ value }">
                        <span class="text-sm text-gray-600">{{ value || '-' }}</span>
                    </template>

                    <template #created_at="{ value }">
                        <span class="text-sm text-gray-600">
                            {{ value ? new Date(value).toLocaleDateString('fr-FR') : '-' }}
                        </span>
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/blacklists/${row.id}/edit`">
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
