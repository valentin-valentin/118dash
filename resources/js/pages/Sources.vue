<script setup>
import { computed, onMounted } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil, Check, X } from 'lucide-vue-next'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/sources/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/sources')

const { filters, reset } = useFilters(
    {
        search: '',
        sort: 'name',
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
    { key: 'name', label: 'Nom', sortable: true },
    { key: 'api_key', label: 'Clé API', sortable: true },
    { key: 'fingerprint', label: 'Fingerprint' },
    { key: 'only_dedicated_phonenumber', label: 'Numéro dédié' },
    { key: 'associations', label: 'Répartition' },
    { key: 'created_at', label: 'Créé le', sortable: true },
    { key: 'actions', label: 'Actions' },
]

// Calculer le total des weights et les pourcentages
function getAssociationStats(associations) {
    const total = associations.reduce((sum, assoc) => sum + (assoc.weight || 0), 0)
    return associations.map(assoc => ({
        ...assoc,
        percentage: total > 0 ? ((assoc.weight / total) * 100).toFixed(1) : 0,
    }))
}

function getColor(index) {
    const colors = [
        'bg-blue-100 text-blue-700',
        'bg-green-100 text-green-700',
        'bg-yellow-100 text-yellow-700',
        'bg-purple-100 text-purple-700',
        'bg-pink-100 text-pink-700',
        'bg-indigo-100 text-indigo-700',
        'bg-red-100 text-red-700',
        'bg-orange-100 text-orange-700',
    ]
    return colors[index % colors.length]
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Sources" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Sources' }
                ]"
            >
                <template #actions>
                    <Button as-child>
                        <Link href="/sources/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle source
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Avec Fingerprint" :value="stats.data?.with_fingerprint" :loading="stats.loading" />
                <StatCard label="Numéro dédié" :value="stats.data?.with_dedicated_phonenumber" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
            >
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <!-- Recherche par nom ou API key -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher par nom ou clé API..."
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

                    <template #name="{ row }">
                        <ColorBadge :color="row.color" :label="row.name" />
                    </template>

                    <template #api_key="{ value }">
                        <span class="font-mono text-xs text-gray-600">{{ value }}</span>
                    </template>

                    <template #fingerprint="{ value }">
                        <div class="flex justify-center">
                            <Check v-if="value" class="h-4 w-4 text-green-600" />
                            <X v-else class="h-4 w-4 text-gray-400" />
                        </div>
                    </template>

                    <template #only_dedicated_phonenumber="{ value }">
                        <div class="flex justify-center">
                            <Check v-if="value" class="h-4 w-4 text-green-600" />
                            <X v-else class="h-4 w-4 text-gray-400" />
                        </div>
                    </template>

                    <template #associations="{ row }">
                        <div v-if="row.source_provider_companies && row.source_provider_companies.length > 0" class="space-y-1">
                            <div
                                v-for="(assoc, index) in getAssociationStats(row.source_provider_companies)"
                                :key="assoc.id"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium"
                                    :class="getColor(index)"
                                >
                                    {{ assoc.provider_company?.provider?.name }} - {{ assoc.provider_company?.company?.name }}
                                </span>
                                <span class="text-xs font-medium text-gray-900">
                                    {{ assoc.percentage }}%
                                </span>
                                <span class="text-xs text-gray-500">
                                    (w: {{ assoc.weight }})
                                </span>
                                <span class="text-xs font-medium text-blue-600">
                                    {{ assoc.assignable_count || 0 }} num.
                                </span>
                            </div>
                        </div>
                        <span v-else class="text-sm text-gray-400">Aucune répartition</span>
                    </template>

                    <template #created_at="{ value }">
                        <span class="text-sm text-gray-600">
                            {{ value ? new Date(value).toLocaleDateString('fr-FR') : '-' }}
                        </span>
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/sources/${row.id}/edit`">
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
