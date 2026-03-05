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

// ─── Grouping Logic - Une ligne de résumé par job ────────────────────────────
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

    // 1. Grouper par job_id
    const groups = new Map()
    items.forEach(item => {
        const jobId = item.job_id || `single_${item.id}`
        if (!groups.has(jobId)) {
            groups.set(jobId, [])
        }
        groups.get(jobId).push(item)
    })

    // 2. Créer les données de chaque groupe
    const groupsData = []
    groups.forEach((attempts, jobId) => {
        // Trier les tentatives par numéro
        attempts.sort((a, b) => (a.attempt || 0) - (b.attempt || 0))

        // Trouver la dernière tentative (la plus récente)
        const lastAttempt = attempts[attempts.length - 1]
        const firstAttempt = attempts[0]

        // Calculer le statut global
        const totalAttempts = attempts.length
        const finalSuccess = !lastAttempt.is_error
        const successAttempt = finalSuccess ? lastAttempt.attempt : null
        const allFailed = attempts.every(a => a.is_error)

        groupsData.push({
            jobId,
            attempts,
            latestDate: new Date(lastAttempt.created_at),
            summary: {
                // Données pour la ligne de résumé
                id: lastAttempt.id, // ID de la dernière tentative pour les détails
                job_id: jobId,
                phonenumber: firstAttempt.phonenumber,
                source: firstAttempt.source,
                company: firstAttempt.company,
                provider: lastAttempt.provider, // Provider qui a finalement géré
                endpoint: lastAttempt.endpoint,
                status: lastAttempt.status,
                is_error: lastAttempt.is_error,
                created_at: lastAttempt.created_at,
                duration_ms: lastAttempt.duration_ms,
                // Métadonnées du groupe
                totalAttempts,
                finalSuccess,
                successAttempt,
                allFailed,
                firstAttemptDate: firstAttempt.created_at,
            }
        })
    })

    // 3. Trier les groupes par date de dernière tentative DESC
    groupsData.sort((a, b) => b.latestDate - a.latestDate)

    // 4. Construire le tableau d'affichage
    const result = []
    groupsData.forEach(({ jobId, attempts, summary }) => {
        const isExpanded = expandedGroups.value.has(jobId)
        const hasRetries = attempts.length > 1

        // Ligne de résumé (toujours affichée)
        result.push({
            ...summary,
            _meta: {
                type: 'summary',
                groupId: jobId,
                hasRetries,
                isExpanded,
                attemptsCount: attempts.length,
            }
        })

        // Lignes de détail (affichées si expanded)
        if (isExpanded && hasRetries) {
            attempts.forEach((attempt, index) => {
                result.push({
                    ...attempt,
                    _meta: {
                        type: 'detail',
                        groupId: jobId,
                        attemptIndex: index + 1,
                        totalAttempts: attempts.length,
                    }
                })
            })
        }
    })

    return result
})

// Style conditionnel pour les lignes
function getRowClass(row) {
    if (!row._meta) return ''

    if (row._meta.type === 'summary') {
        // Ligne de résumé
        if (row._meta.hasRetries) {
            if (row.finalSuccess) {
                // A réussi après retries
                return 'bg-green-50/30 hover:bg-green-50/50'
            } else if (row.allFailed) {
                // Tous les retries ont échoué
                return 'bg-red-50/40 hover:bg-red-50/60'
            }
        }
        return ''
    }

    if (row._meta.type === 'detail') {
        // Ligne de détail d'une tentative
        if (row.is_error) {
            return 'bg-red-50/20 border-l-4 border-l-red-400'
        } else {
            return 'bg-green-50/20 border-l-4 border-l-green-400'
        }
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
                            <!-- Chevron pour expand/collapse (seulement sur summary avec retries) -->
                            <button
                                v-if="row._meta?.type === 'summary' && row._meta.hasRetries"
                                type="button"
                                class="flex-shrink-0 rounded p-0.5 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-800"
                                @click.stop="toggleGroup(row._meta.groupId)"
                                :title="row._meta.isExpanded ? 'Masquer les détails' : 'Voir toutes les tentatives'"
                            >
                                <ChevronDown v-if="row._meta.isExpanded" class="h-4 w-4" />
                                <ChevronRight v-else class="h-4 w-4" />
                            </button>

                            <!-- Espaceur pour aligner les lignes sans chevron -->
                            <span v-else-if="row._meta?.type === 'summary'" class="w-5"></span>

                            <!-- Indicateur visuel pour les lignes de détail -->
                            <div
                                v-if="row._meta?.type === 'detail'"
                                class="flex items-center gap-1.5 pl-1"
                            >
                                <div class="flex h-4 items-center">
                                    <div class="h-px w-4 bg-gray-300"></div>
                                </div>
                                <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                                    #{{ row._meta.attemptIndex }}
                                </span>
                            </div>

                            <span
                                class="font-mono text-xs"
                                :class="row._meta?.type === 'detail' ? 'text-gray-500 ml-2' : 'text-gray-700 font-medium'"
                            >
                                #{{ value }}
                            </span>
                        </div>
                    </template>

                    <template #attempt="{ row, value }">
                        <!-- Ligne de résumé : afficher "X/Y tentatives" avec statut -->
                        <div v-if="row._meta?.type === 'summary'" class="flex items-center gap-2">
                            <!-- Badge résultat -->
                            <div
                                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-bold"
                                :class="row.finalSuccess
                                    ? 'bg-green-500 text-white'
                                    : 'bg-red-500 text-white'"
                            >
                                <span class="text-base">{{ row.finalSuccess ? '✓' : '✗' }}</span>
                                <span v-if="row.finalSuccess">
                                    {{ row.successAttempt }}/{{ row.totalAttempts }}
                                </span>
                                <span v-else>
                                    {{ row.totalAttempts }} {{ row.totalAttempts > 1 ? 'échecs' : 'échec' }}
                                </span>
                            </div>
                        </div>

                        <!-- Ligne de détail : numéro de tentative simple -->
                        <div v-else-if="row._meta?.type === 'detail'" class="flex items-center gap-2">
                            <div
                                class="flex h-7 w-7 items-center justify-center rounded-md text-xs font-bold"
                                :class="row.is_error
                                    ? 'bg-red-100 text-red-700'
                                    : 'bg-green-100 text-green-700'"
                            >
                                {{ value || row._meta.attemptIndex }}
                            </div>
                            <span class="text-xs text-gray-500">
                                / {{ row._meta.totalAttempts }}
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
                            :class="row._meta?.type === 'detail' ? 'text-gray-500' : 'text-gray-700'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #phonenumber="{ row, value }">
                        <span
                            class="font-mono"
                            :class="row._meta?.type === 'detail' ? 'text-xs text-gray-600' : 'text-sm font-medium'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #source="{ row, value }">
                        <span
                            :class="row._meta?.type === 'detail' ? 'text-xs text-gray-600' : 'text-sm'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #company="{ row, value }">
                        <span
                            :class="row._meta?.type === 'detail' ? 'text-xs text-gray-600' : 'text-sm'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #provider="{ row, value }">
                        <span
                            :class="row._meta?.type === 'detail' ? 'text-xs text-gray-600' : 'text-sm'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #endpoint="{ row, value }">
                        <span
                            class="font-mono text-xs"
                            :class="row._meta?.type === 'detail' ? 'text-gray-500' : 'text-gray-600'"
                        >
                            {{ value || '-' }}
                        </span>
                    </template>

                    <template #duration_ms="{ row, value }">
                        <span class="text-sm tabular-nums">
                            {{ value !== null ? value + 'ms' : '-' }}
                        </span>
                    </template>

                    <template #created_at="{ row, value }">
                        <span
                            :class="row._meta?.type === 'detail' ? 'text-xs text-gray-500' : 'text-sm'"
                        >
                            {{ formatDate(value) }}
                        </span>
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
            <DialogContent class="w-[92vw] max-w-4xl sm:max-w-4xl max-h-[88vh] overflow-y-auto">
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
