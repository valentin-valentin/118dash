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
    { key: 'fingerprint', label: 'Fingerprint' },
    { key: 'only_dedicated_phonenumber', label: 'Numéro dédié' },
    { key: 'display_duration_minutes', label: 'Durée affichage' },
    { key: 'real_duration_minutes', label: 'Durée réelle' },
    { key: 'total_assignable', label: 'Numéros assignables' },
    { key: 'associations', label: 'Répartition' },
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

// Formater les minutes en format lisible
function formatDuration(minutes) {
    if (!minutes) return '-'

    const days = Math.floor(minutes / 1440)
    const hours = Math.floor((minutes % 1440) / 60)
    const mins = minutes % 60

    const parts = []
    if (days > 0) parts.push(`${days}j`)
    if (hours > 0) parts.push(`${hours}h`)
    if (mins > 0 && days === 0) parts.push(`${mins}m`)

    return parts.length > 0 ? parts.join(' ') : '-'
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
                    <!-- Recherche par nom -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher par nom..."
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

                    <template #fingerprint="{ value }">
                        <Check v-if="value" class="h-4 w-4 text-green-600" />
                        <X v-else class="h-4 w-4 text-gray-400" />
                    </template>

                    <template #only_dedicated_phonenumber="{ value }">
                        <Check v-if="value" class="h-4 w-4 text-green-600" />
                        <X v-else class="h-4 w-4 text-gray-400" />
                    </template>

                    <template #display_duration_minutes="{ value }">
                        <span class="text-sm text-gray-700">
                            {{ formatDuration(value) }}
                        </span>
                    </template>

                    <template #real_duration_minutes="{ value }">
                        <span class="text-sm text-gray-700">
                            {{ formatDuration(value) }}
                        </span>
                    </template>

                    <template #total_assignable="{ row }">
                        <span class="text-sm font-medium text-gray-900">
                            {{ row.total_assignable || 0 }} numéros
                        </span>
                    </template>

                    <template #associations="{ row }">
                        <div v-if="row.source_provider_companies && row.source_provider_companies.length > 0" class="space-y-1">
                            <div
                                v-for="assoc in getAssociationStats(row.source_provider_companies)"
                                :key="assoc.id"
                                class="flex items-center gap-2"
                            >
                                <ColorBadge
                                    v-if="assoc.provider_company?.provider"
                                    :color="assoc.provider_company.provider.color"
                                    :label="assoc.provider_company.provider.name"
                                />
                                <ColorBadge
                                    v-if="assoc.provider_company?.company"
                                    :color="assoc.provider_company.company.color"
                                    :label="assoc.provider_company.company.name"
                                />
                                <span class="text-xs font-medium text-gray-900">
                                    {{ assoc.percentage }}%
                                </span>
                                <span class="text-xs text-gray-500">
                                    (w: {{ assoc.weight }})
                                </span>
                                <span class="text-xs font-medium text-gray-600">
                                    {{ assoc.assignable_count || 0 }} num.
                                </span>
                            </div>
                        </div>
                        <span v-else class="text-sm text-gray-400">Aucune répartition</span>
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
