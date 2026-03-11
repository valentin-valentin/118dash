<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/routing-logs/stats')

// ─── Filter Options ──────────────────────────────────────────────────────────
const filterOptions = useApi('/data/routing-logs/filter-options')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/routing-logs')

const { filters, reset } = useFilters(
    {
        search: '',
        start_date: '',
        end_date: '',
        status: '',
        source_id: '',
        company_id: '',
        provider_id: '',
        sort: 'created_at',
        dir: 'desc',
        page: 1,
        per_page: 50,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.search ||
    !!filters.start_date ||
    !!filters.end_date ||
    !!filters.status ||
    !!filters.source_id ||
    !!filters.company_id ||
    !!filters.provider_id
)

// Reset to page 1 when filters change
watch(
    () => [filters.search, filters.start_date, filters.end_date, filters.status, filters.source_id, filters.company_id, filters.provider_id],
    () => {
        if (filters.page !== 1) {
            filters.page = 1
        }
    }
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
    { key: 'status', label: 'Status' },
    { key: 'job_id', label: 'Job ID' },
    { key: 'attempt', label: 'Attempt' },
    { key: 'phonenumber', label: 'Numéro' },
    { key: 'source', label: 'Source' },
    { key: 'company', label: 'Company' },
    { key: 'provider', label: 'Provider' },
    { key: 'endpoint', label: 'Endpoint' },
    { key: 'duration_ms', label: 'Durée (ms)', sortable: true },
    { key: 'created_at', label: 'Date', sortable: true },
    { key: 'actions', label: '' },
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

// ─── Details Modal ────────────────────────────────────────────────────────────
const showDetails = ref(false)
const selectedLog = ref(null)
const loadingDetails = ref(false)

async function openDetails(logId) {
    loadingDetails.value = true
    showDetails.value = true

    try {
        const res = await fetch(`/routing-logs/${logId}`)
        selectedLog.value = await res.json()
    } catch (error) {
        console.error('Error loading log details:', error)
    } finally {
        loadingDetails.value = false
    }
}

function closeDetails() {
    showDetails.value = false
    selectedLog.value = null
}

function formatJSON(obj) {
    return JSON.stringify(obj, null, 2)
}

// Pas de groupement - juste les données brutes

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    filterOptions.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Routing Logs" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Routing Logs', href: '/routing-logs' }
                ]"
            />

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Erreurs" :value="stats.data?.errors" :loading="stats.loading" variant="danger" />
                <StatCard label="Succès" :value="stats.data?.success" :loading="stats.loading" variant="success" />
                <StatCard label="Aujourd'hui" :value="stats.data?.today" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar :has-active-filters="hasFilters" @reset="reset">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-7">
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher..."
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
                        v-if="filterOptions.data?.statuses"
                        v-model="filters.status"
                        :options="filterOptions.data.statuses"
                        placeholder="Status"
                        :searchable="false"
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
                    <template #status="{ row, value }">
                        <Badge
                            :variant="row.is_error ? 'destructive' : 'default'"
                            :class="row.is_error ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                        >
                            {{ value || '-' }}
                        </Badge>
                    </template>

                    <template #job_id="{ value }">
                        <span class="font-mono text-xs text-gray-600">{{ value || '-' }}</span>
                    </template>

                    <template #attempt="{ value }">
                        <span class="text-sm text-gray-700">{{ value || '-' }}</span>
                    </template>

                    <template #phonenumber="{ value }">
                        <span class="font-mono text-sm">{{ value || '-' }}</span>
                    </template>

                    <template #source="{ value }">
                        <span class="text-sm">{{ value || '-' }}</span>
                    </template>

                    <template #company="{ value }">
                        <span class="text-sm">{{ value || '-' }}</span>
                    </template>

                    <template #provider="{ value }">
                        <span class="text-sm">{{ value || '-' }}</span>
                    </template>

                    <template #endpoint="{ value }">
                        <span class="font-mono text-xs text-gray-500">{{ value || '-' }}</span>
                    </template>

                    <template #duration_ms="{ value }">
                        <span class="text-sm">{{ value !== null ? value + 'ms' : '-' }}</span>
                    </template>

                    <template #created_at="{ value }">
                        <span class="text-sm">{{ formatDate(value) }}</span>
                    </template>

                    <template #actions="{ row }">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="openDetails(row.id)"
                        >
                            Détails
                        </Button>
                    </template>
                </DataTable>

                <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3">
                    <div class="flex items-center gap-4">
                        <div class="text-sm text-gray-600">
                            <template v-if="!hasFilters">
                                Page {{ table.data?.current_page || 1 }}
                            </template>
                            <template v-else>
                                Page {{ table.data?.current_page || 1 }}
                            </template>
                            <template v-if="table.data?.total">
                                · {{ table.data.total }} résultat{{ table.data.total !== 1 ? 's' : '' }}
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
                            v-if="table.data?.current_page > 1"
                            @click="filters.page = (filters.page || 1) - 1"
                            class="cursor-pointer rounded border px-3 py-1 text-sm hover:bg-gray-50"
                        >
                            Précédent
                        </button>
                        <button
                            v-if="table.data?.last_page ? table.data.current_page < table.data.last_page : table.data?.has_more_pages"
                            @click="filters.page = (filters.page || 1) + 1"
                            class="cursor-pointer rounded border px-3 py-1 text-sm hover:bg-gray-50"
                        >
                            Suivant
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Modal -->
        <Dialog :open="showDetails" @update:open="closeDetails">
            <DialogContent class="w-[90vw] max-w-3xl sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Détails du Log #{{ selectedLog?.id }}</DialogTitle>
                </DialogHeader>

                <div v-if="loadingDetails" class="flex items-center justify-center py-8">
                    <div class="text-sm text-gray-500">Chargement...</div>
                </div>

                <div v-else-if="selectedLog" class="max-h-[70vh] space-y-4 overflow-y-auto pr-2">
                    <!-- Status & Info -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Status</p>
                            <Badge
                                :variant="selectedLog.is_error ? 'destructive' : 'default'"
                                :class="selectedLog.is_error ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                            >
                                {{ selectedLog.status }}
                            </Badge>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Job ID</p>
                            <p class="font-mono text-sm">{{ selectedLog.job_id || '-' }}</p>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Numéro</p>
                            <p class="font-mono text-sm">{{ selectedLog.phonenumber?.phonenumber || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Source</p>
                            <p class="text-sm">{{ selectedLog.source?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Company</p>
                            <p class="text-sm">{{ selectedLog.company?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Provider</p>
                            <p class="text-sm">{{ selectedLog.provider?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Endpoint</p>
                            <p class="font-mono text-xs">{{ selectedLog.endpoint || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Durée</p>
                            <p class="text-sm">{{ selectedLog.duration_ms !== null ? selectedLog.duration_ms + 'ms' : '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Tentative</p>
                            <p class="text-sm">{{ selectedLog.attempt || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">Date</p>
                            <p class="text-sm">{{ formatDate(selectedLog.created_at) }}</p>
                        </div>
                    </div>

                    <!-- Reason & Message -->
                    <div v-if="selectedLog.reason" class="space-y-2">
                        <p class="text-xs font-medium text-gray-500">Raison</p>
                        <div class="rounded-lg bg-gray-50 p-3 text-sm">
                            {{ selectedLog.reason }}
                        </div>
                    </div>

                    <div v-if="selectedLog.message" class="space-y-2">
                        <p class="text-xs font-medium text-gray-500">Message</p>
                        <div
                            class="rounded-lg p-3 text-sm"
                            :class="selectedLog.is_error ? 'bg-red-50 text-red-900' : 'bg-gray-50'"
                        >
                            {{ selectedLog.message }}
                        </div>
                    </div>

                    <!-- Request Data -->
                    <div v-if="selectedLog.request_data" class="space-y-2">
                        <p class="text-xs font-medium text-gray-500">Request Data</p>
                        <pre class="max-h-64 overflow-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>{{ formatJSON(selectedLog.request_data) }}</code></pre>
                    </div>

                    <!-- Response Data -->
                    <div v-if="selectedLog.response_data" class="space-y-2">
                        <p class="text-xs font-medium text-gray-500">Response Data</p>
                        <pre class="max-h-64 overflow-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>{{ formatJSON(selectedLog.response_data) }}</code></pre>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
