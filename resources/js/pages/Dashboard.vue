<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useApi } from '@/composables/useApi'

// ─── Période ──────────────────────────────────────────────────────────────────
const period = ref('today')

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/stats')

// ─── Daily Breakdown ──────────────────────────────────────────────────────────
const daily = useApi('/data/daily-breakdown')

// ─── Colonnes tableau ─────────────────────────────────────────────────────────
const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'calls', label: 'Appels', sortable: true },
    { key: 'ca', label: 'CA (€)', sortable: true },
    { key: 'reverse', label: 'Reverse (€)', sortable: true },
    { key: 'benefice', label: 'Bénéfice (€)', sortable: true },
    { key: 'total_duration', label: 'Durée totale', sortable: true },
    { key: 'avg_duration', label: 'Durée moy.', sortable: true },
]

const sortKey = ref('date')
const sortDir = ref('desc')

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDir.value = 'asc'
    }
}

const sortedRows = computed(() => {
    if (!daily.data?.items) return []

    const rows = [...daily.data.items]
    rows.sort((a, b) => {
        let aVal = a[sortKey.value]
        let bVal = b[sortKey.value]

        if (sortKey.value === 'date') {
            aVal = new Date(aVal).getTime()
            bVal = new Date(bVal).getTime()
        }

        if (sortDir.value === 'asc') {
            return aVal > bVal ? 1 : -1
        } else {
            return aVal < bVal ? 1 : -1
        }
    })

    return rows
})

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(dateString) {
    const date = new Date(dateString)
    return date.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    })
}

function formatDuration(seconds) {
    if (!seconds) return '-'
    const hours = Math.floor(seconds / 3600)
    const mins = Math.floor((seconds % 3600) / 60)
    const secs = seconds % 60
    return `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

function formatCurrency(value) {
    if (!value && value !== 0) return '-'
    return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' €'
}

function getVariation(current, previous) {
    if (!previous || previous === 0) return 0
    return ((current - previous) / previous) * 100
}

function getVariationClass(variation) {
    if (variation > 0) return 'text-green-600'
    if (variation < 0) return 'text-red-600'
    return 'text-gray-500'
}

function getVariationSymbol(variation) {
    if (variation > 0) return '↗'
    if (variation < 0) return '↘'
    return '→'
}

// ─── Watch period changes ─────────────────────────────────────────────────────
watch(period, () => {
    stats.load({ period: period.value })
    daily.load({ period: period.value })
})

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load({ period: period.value })
    daily.load({ period: period.value })
})
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard' }
                ]"
            >
                <template #actions>
                    <!-- Sélecteur de période -->
                    <div class="flex gap-2 rounded-lg border border-gray-200 bg-white p-1">
                        <button
                            v-for="p in ['today', 'this_week', 'this_month']"
                            :key="p"
                            @click="period = p"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                period === p
                                    ? 'bg-blue-600 text-white'
                                    : 'text-gray-600 hover:bg-gray-100'
                            ]"
                        >
                            {{ p === 'today' ? 'Aujourd\'hui' : p === 'this_week' ? 'Cette semaine' : 'Ce mois' }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <!-- Hero KPIs -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                <!-- Appels -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Appels</div>
                    <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                    <template v-else>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ stats.data?.current.calls?.toLocaleString() ?? '-' }}
                        </div>
                        <div v-if="stats.data" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="getVariationClass(getVariation(stats.data.current.calls, stats.data.previous.calls))">
                            <span>{{ getVariationSymbol(getVariation(stats.data.current.calls, stats.data.previous.calls)) }}</span>
                            <span>{{ Math.abs(getVariation(stats.data.current.calls, stats.data.previous.calls)).toFixed(1) }}%</span>
                        </div>
                    </template>
                </div>

                <!-- CA -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">CA</div>
                    <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                    <template v-else>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ formatCurrency(stats.data?.current.ca) }}
                        </div>
                        <div v-if="stats.data" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="getVariationClass(getVariation(stats.data.current.ca, stats.data.previous.ca))">
                            <span>{{ getVariationSymbol(getVariation(stats.data.current.ca, stats.data.previous.ca)) }}</span>
                            <span>{{ Math.abs(getVariation(stats.data.current.ca, stats.data.previous.ca)).toFixed(1) }}%</span>
                        </div>
                    </template>
                </div>

                <!-- Reverse -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Reverse</div>
                    <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                    <template v-else>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ formatCurrency(stats.data?.current.reverse) }}
                        </div>
                        <div v-if="stats.data" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="getVariationClass(getVariation(stats.data.current.reverse, stats.data.previous.reverse))">
                            <span>{{ getVariationSymbol(getVariation(stats.data.current.reverse, stats.data.previous.reverse)) }}</span>
                            <span>{{ Math.abs(getVariation(stats.data.current.reverse, stats.data.previous.reverse)).toFixed(1) }}%</span>
                        </div>
                    </template>
                </div>

                <!-- Bénéfice -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Bénéfice</div>
                    <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                    <template v-else>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ formatCurrency(stats.data?.current.benefice) }}
                        </div>
                        <div v-if="stats.data" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="getVariationClass(getVariation(stats.data.current.benefice, stats.data.previous.benefice))">
                            <span>{{ getVariationSymbol(getVariation(stats.data.current.benefice, stats.data.previous.benefice)) }}</span>
                            <span>{{ Math.abs(getVariation(stats.data.current.benefice, stats.data.previous.benefice)).toFixed(1) }}%</span>
                        </div>
                    </template>
                </div>

                <!-- Durée moyenne -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Durée moy.</div>
                    <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                    <template v-else>
                        <div class="mt-2 font-mono text-2xl font-semibold text-gray-900">
                            {{ formatDuration(stats.data?.current.avg_duration) }}
                        </div>
                        <div v-if="stats.data" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="getVariationClass(getVariation(stats.data.current.avg_duration, stats.data.previous.avg_duration))">
                            <span>{{ getVariationSymbol(getVariation(stats.data.current.avg_duration, stats.data.previous.avg_duration)) }}</span>
                            <span>{{ Math.abs(getVariation(stats.data.current.avg_duration, stats.data.previous.avg_duration)).toFixed(1) }}%</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Tableau jour par jour -->
            <div class="rounded-lg border border-gray-100 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h3 class="text-sm font-medium text-gray-700">Détail jour par jour</h3>
                </div>

                <DataTable
                    :columns="columns"
                    :rows="sortedRows"
                    :loading="daily.loading"
                    :sort-key="sortKey"
                    :sort-dir="sortDir"
                    @sort="toggleSort"
                >
                    <template #date="{ value }">
                        <span class="text-sm font-medium text-gray-900">{{ formatDate(value) }}</span>
                    </template>
                    <template #calls="{ value }">
                        <span class="text-sm text-gray-900">{{ value?.toLocaleString() ?? '-' }}</span>
                    </template>
                    <template #ca="{ value }">
                        <span class="font-mono text-sm text-gray-900">{{ formatCurrency(value) }}</span>
                    </template>
                    <template #reverse="{ value }">
                        <span class="font-mono text-sm text-gray-900">{{ formatCurrency(value) }}</span>
                    </template>
                    <template #benefice="{ value }">
                        <span class="font-mono text-sm font-medium" :class="value >= 0 ? 'text-green-700' : 'text-red-700'">
                            {{ formatCurrency(value) }}
                        </span>
                    </template>
                    <template #total_duration="{ value }">
                        <span class="font-mono text-sm text-gray-900">{{ formatDuration(value) }}</span>
                    </template>
                    <template #avg_duration="{ value }">
                        <span class="font-mono text-sm text-gray-900">{{ formatDuration(value) }}</span>
                    </template>
                </DataTable>

                <!-- Totaux -->
                <div v-if="daily.data?.totals" class="border-t-2 border-gray-300 bg-gray-50 px-3 py-2">
                    <div class="flex items-center justify-between text-sm font-semibold text-gray-900">
                        <div class="w-32">TOTAL</div>
                        <div class="flex flex-1 items-center justify-between">
                            <div class="flex-1 text-center">{{ daily.data.totals.calls.toLocaleString() }}</div>
                            <div class="flex-1 text-center font-mono">{{ formatCurrency(daily.data.totals.ca) }}</div>
                            <div class="flex-1 text-center font-mono">{{ formatCurrency(daily.data.totals.reverse) }}</div>
                            <div class="flex-1 text-center font-mono" :class="daily.data.totals.benefice >= 0 ? 'text-green-700' : 'text-red-700'">
                                {{ formatCurrency(daily.data.totals.benefice) }}
                            </div>
                            <div class="flex-1 text-center font-mono">{{ formatDuration(daily.data.totals.total_duration) }}</div>
                            <div class="flex-1 text-center font-mono">{{ formatDuration(daily.data.totals.avg_duration) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
