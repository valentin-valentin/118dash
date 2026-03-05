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
import { X, ChevronRight, ChevronDown } from 'lucide-vue-next'

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
    { key: 'attempt', label: 'Tentative' },
    { key: 'job_id', label: 'Job ID' },
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

// ─── Grouping by job_id ───────────────────────────────────────────────────────
const expandedJobIds = ref(new Set())

function toggleJobGroup(jobId) {
    if (expandedJobIds.value.has(jobId)) {
        expandedJobIds.value.delete(jobId)
    } else {
        expandedJobIds.value.add(jobId)
    }
}

// Group rows by job_id and mark retry attempts
const groupedRows = computed(() => {
    const items = table.data?.items ?? []

    // Group by job_id
    const groups = items.reduce((acc, row) => {
        const jobId = row.job_id || `single_${row.id}`
        if (!acc[jobId]) {
            acc[jobId] = []
        }
        acc[jobId].push(row)
        return acc
    }, {})

    // Flatten groups with metadata
    const result = []
    Object.entries(groups).forEach(([jobId, rows]) => {
        // Sort by attempt within each group
        const sortedRows = rows.sort((a, b) => (a.attempt || 0) - (b.attempt || 0))

        if (sortedRows.length === 1) {
            // Single attempt - no grouping needed
            result.push({ ...sortedRows[0], _isParent: false, _hasRetries: false })
        } else {
            // Multiple attempts - show first as parent
            const [firstAttempt, ...retries] = sortedRows
            result.push({
                ...firstAttempt,
                _isParent: true,
                _hasRetries: true,
                _retryCount: retries.length,
                _jobId: jobId,
                _expanded: expandedJobIds.value.has(jobId)
            })

            // Only show retries if expanded
            if (expandedJobIds.value.has(jobId)) {
                retries.forEach(retry => {
                    result.push({ ...retry, _isRetry: true, _jobId: jobId })
                })
            }
        }
    })

    return result
})

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
                    :rows="groupedRows"
                    :loading="table.loading"
                    :sort-key="filters.sort"
                    :sort-dir="filters.dir"
                    @sort="toggleSort"
                >
                    <template #id="{ row, value }">
                        <div class="flex items-center gap-2">
                            <!-- Expand/collapse button for grouped attempts -->
                            <button
                                v-if="row._hasRetries"
                                type="button"
                                class="text-gray-400 hover:text-gray-600"
                                @click="toggleJobGroup(row._jobId)"
                            >
                                <ChevronDown v-if="row._expanded" class="h-4 w-4" />
                                <ChevronRight v-else class="h-4 w-4" />
                            </button>
                            <span
                                class="font-mono text-xs text-gray-500"
                                :class="{ 'ml-6': row._isRetry }"
                            >
                                #{{ value }}
                            </span>
                        </div>
                    </template>

                    <template #attempt="{ row, value }">
                        <div class="flex items-center gap-2">
                            <span class="text-sm">{{ value || '-' }}</span>
                            <span
                                v-if="row._hasRetries"
                                class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600"
                            >
                                +{{ row._retryCount }}
                            </span>
                        </div>
                    </template>

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

                <div v-if="table.data?.total" class="border-t border-gray-50 px-4 py-2 text-xs text-gray-400">
                    {{ table.data.total }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                </div>
            </div>
        </div>

        <!-- Details Modal -->
        <Dialog :open="showDetails" @update:open="closeDetails">
            <DialogContent class="max-w-7xl w-[90vw] max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle class="flex items-center justify-between">
                        <span>Détails du Log #{{ selectedLog?.id }}</span>
                        <Button variant="ghost" size="sm" @click="closeDetails">
                            <X class="h-4 w-4" />
                        </Button>
                    </DialogTitle>
                </DialogHeader>

                <div v-if="loadingDetails" class="flex items-center justify-center py-8">
                    <div class="text-sm text-gray-500">Chargement...</div>
                </div>

                <div v-else-if="selectedLog" class="space-y-4">
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
                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>{{ formatJSON(selectedLog.request_data) }}</code></pre>
                    </div>

                    <!-- Response Data -->
                    <div v-if="selectedLog.response_data" class="space-y-2">
                        <p class="text-xs font-medium text-gray-500">Response Data</p>
                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>{{ formatJSON(selectedLog.response_data) }}</code></pre>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
