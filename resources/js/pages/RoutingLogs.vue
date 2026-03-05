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

// ─── Grouping by job_id avec collapse/expand ─────────────────────────────────
const expandedGroups = ref(new Set())

function toggleGroup(jobId) {
    if (expandedGroups.value.has(jobId)) {
        expandedGroups.value.delete(jobId)
    } else {
        expandedGroups.value.add(jobId)
    }
}

const groupedRows = computed(() => {
    const items = table.data?.items ?? []
    if (items.length === 0) return []

    // Group by job_id
    const groups = {}
    items.forEach(row => {
        const jobId = row.job_id || `single_${row.id}`
        if (!groups[jobId]) {
            groups[jobId] = []
        }
        groups[jobId].push(row)
    })

    // Pour chaque groupe, trier par tentative ASC (1, 2, 3...)
    Object.keys(groups).forEach(jobId => {
        groups[jobId].sort((a, b) => (a.attempt || 0) - (b.attempt || 0))
    })

    // Ordre d'affichage des groupes basé sur created_at DESC de la première tentative
    const groupOrder = Object.keys(groups).sort((a, b) => {
        const firstA = groups[a][0]
        const firstB = groups[b][0]
        return new Date(firstB.created_at) - new Date(firstA.created_at)
    })

    // Construire le résultat final
    const result = []
    groupOrder.forEach(jobId => {
        const rows = groups[jobId]

        if (rows.length === 1) {
            // Une seule tentative - affichage normal
            result.push({
                ...rows[0],
                _groupId: jobId,
                _isFirstAttempt: true,
                _hasRetries: false,
                _retryCount: 0,
                _isExpanded: false,
            })
        } else {
            // Plusieurs tentatives
            const [firstAttempt, ...retries] = rows
            const isExpanded = expandedGroups.value.has(jobId)

            // Tentative 1 - toujours visible
            result.push({
                ...firstAttempt,
                _groupId: jobId,
                _isFirstAttempt: true,
                _hasRetries: true,
                _retryCount: retries.length,
                _isExpanded: isExpanded,
                _allRetriesSuccess: retries.every(r => !r.is_error),
            })

            // Tentatives 2, 3, 4... - visible seulement si expanded
            if (isExpanded) {
                retries.forEach(retry => {
                    result.push({
                        ...retry,
                        _groupId: jobId,
                        _isFirstAttempt: false,
                        _hasRetries: false,
                        _retryCount: 0,
                        _isExpanded: false,
                    })
                })
            }
        }
    })

    return result
})

// Style conditionnel pour les lignes
function getRowClass(row) {
    if (!row._isFirstAttempt) {
        // Ligne de retry - fond bleu clair avec bordure gauche épaisse
        return 'bg-blue-50/60 border-l-[6px] border-l-blue-500 hover:bg-blue-100/60'
    }
    return ''
}

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
                    :row-class="getRowClass"
                    @sort="toggleSort"
                >
                    <template #id="{ row, value }">
                        <div class="flex items-center gap-2">
                            <!-- Expand/Collapse button pour les groupes -->
                            <button
                                v-if="row._hasRetries"
                                type="button"
                                class="flex-shrink-0 rounded p-1 text-gray-400 transition-all hover:bg-gray-100 hover:text-gray-700"
                                @click.stop="toggleGroup(row._groupId)"
                                :title="row._isExpanded ? 'Masquer les retries' : 'Afficher les retries'"
                            >
                                <ChevronDown v-if="row._isExpanded" class="h-4 w-4" />
                                <ChevronRight v-else class="h-4 w-4" />
                            </button>

                            <!-- Indicateur visuel pour retry -->
                            <div
                                v-if="!row._isFirstAttempt"
                                class="flex items-center gap-1.5 pl-1"
                            >
                                <svg class="h-3 w-3 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                                <span class="text-xs font-medium text-blue-600">RETRY</span>
                            </div>

                            <span
                                class="font-mono text-xs"
                                :class="!row._isFirstAttempt ? 'text-blue-600 font-semibold' : 'text-gray-500'"
                            >
                                #{{ value }}
                            </span>
                        </div>
                    </template>

                    <template #attempt="{ row, value }">
                        <div class="flex items-center gap-2">
                            <!-- Numéro de tentative -->
                            <span
                                class="flex h-6 w-6 items-center justify-center rounded text-sm font-semibold"
                                :class="!row._isFirstAttempt
                                    ? 'bg-blue-100 text-blue-700'
                                    : 'text-gray-700'"
                            >
                                {{ value || '1' }}
                            </span>

                            <!-- Badge pour montrer les retries avec animation -->
                            <span
                                v-if="row._hasRetries"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-bold shadow-sm ring-1"
                                :class="row._allRetriesSuccess
                                    ? 'bg-green-50 text-green-700 ring-green-600/20'
                                    : 'bg-red-50 text-red-700 ring-red-600/20'"
                            >
                                <span class="relative flex h-2 w-2">
                                    <span
                                        v-if="!row._allRetriesSuccess"
                                        class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                                        :class="row._allRetriesSuccess ? 'bg-green-400' : 'bg-red-400'"
                                    ></span>
                                    <span
                                        class="relative inline-flex h-2 w-2 rounded-full"
                                        :class="row._allRetriesSuccess ? 'bg-green-500' : 'bg-red-500'"
                                    ></span>
                                </span>
                                +{{ row._retryCount }} {{ row._retryCount > 1 ? 'retries' : 'retry' }}
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

                    <template #job_id="{ row, value }">
                        <span
                            class="font-mono text-xs"
                            :class="!row._isFirstAttempt ? 'text-blue-600' : 'text-gray-600'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #phonenumber="{ row, value }">
                        <span
                            class="font-mono text-sm"
                            :class="!row._isFirstAttempt ? 'text-gray-500' : ''"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #source="{ row, value }">
                        <span
                            class="text-sm"
                            :class="!row._isFirstAttempt ? 'text-gray-500 text-xs' : ''"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #company="{ row, value }">
                        <span
                            class="text-sm"
                            :class="!row._isFirstAttempt ? 'text-gray-500 text-xs' : ''"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #provider="{ row, value }">
                        <span
                            class="text-sm"
                            :class="!row._isFirstAttempt ? 'text-gray-500 text-xs' : ''"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #endpoint="{ row, value }">
                        <span
                            class="font-mono text-xs"
                            :class="!row._isFirstAttempt ? 'text-blue-500' : 'text-gray-500'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #duration_ms="{ row, value }">
                        <span
                            class="text-sm font-medium"
                            :class="!row._isFirstAttempt ? 'text-blue-600' : ''"
                        >
                            {{ value !== null ? value + 'ms' : '-' }}
                        </span>
                    </template>

                    <template #created_at="{ row, value }">
                        <span
                            class="text-sm"
                            :class="!row._isFirstAttempt ? 'text-xs text-gray-500' : ''"
                        >
                            {{ formatDate(value) }}
                        </span>
                    </template>

                    <template #actions="{ row }">
                        <Button
                            variant="ghost"
                            :size="!row._isFirstAttempt ? 'sm' : 'sm'"
                            @click="openDetails(row.id)"
                        >
                            {{ !row._isFirstAttempt ? 'Voir' : 'Détails' }}
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
            <DialogContent class="w-[95vw] max-w-5xl sm:max-w-5xl max-h-[85vh] overflow-y-auto">
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
