<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import BrandTreemap from '@/components/BrandTreemap.vue'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { useFilterOptions } from '@/composables/useFilterOptions'

// ─── Période pour les KPIs et graphiques ──────────────────────────────────────
const period = ref('today')

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/stats')

// ─── Filter Options ───────────────────────────────────────────────────────────
const {
    options: filterOptions,
    isLoading: isLoadingOptions,
    load: loadFilterOptions,
} = useFilterOptions('/data/dashboard/filter-options', {
    brands: [],
    agents: [],
    callcenters: [],
    carriers: [],
    providers: [],
    companies: [],
    sources: [],
})

// ─── Month Selection ──────────────────────────────────────────────────────────
// Generate months from current month back to 12 months
const monthOptions = computed(() => {
    const months = []
    const now = new Date()
    for (let i = 0; i < 12; i++) {
        const date = new Date(now.getFullYear(), now.getMonth() - i, 1)
        const year = date.getFullYear()
        const month = date.getMonth() + 1
        const monthName = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
        months.push({
            value: `${year}-${month.toString().padStart(2, '0')}`,
            label: monthName.charAt(0).toUpperCase() + monthName.slice(1)
        })
    }
    return months
})

// ─── Daily Breakdown + Treemap + Filtres ─────────────────────────────────────
const daily = useApi('/data/daily-breakdown')
const brandDistribution = useApi('/data/brand-distribution')

// ─── Hourly Breakdown (détail d'un jour) ─────────────────────────────────────
const showHourlyModal = ref(false)
const selectedDate = ref(null)
const hourly = useApi('/data/hourly-breakdown')

const { filters, reset } = useFilters(
    {
        month: monthOptions.value[0].value, // Current month by default
        source_id: [],
        provider_id: [],
        company_id: [],
        brand_name: [],
        carrier: [],
        callcenter_id: [],
        agent_name: [],
    },
    (f) => {
        daily.load(f)
        brandDistribution.load(f)
    },
)

const hasFilters = computed(() =>
    (filters.month !== monthOptions.value[0].value) ||
    (Array.isArray(filters.source_id) && filters.source_id.length > 0) ||
    (Array.isArray(filters.provider_id) && filters.provider_id.length > 0) ||
    (Array.isArray(filters.company_id) && filters.company_id.length > 0) ||
    (Array.isArray(filters.brand_name) && filters.brand_name.length > 0) ||
    (Array.isArray(filters.carrier) && filters.carrier.length > 0) ||
    (Array.isArray(filters.callcenter_id) && filters.callcenter_id.length > 0) ||
    (Array.isArray(filters.agent_name) && filters.agent_name.length > 0)
)

// ─── Colonnes tableau avec comparaisons ───────────────────────────────────────
const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'calls', label: 'Appels', sortable: true, headerClass: 'text-right' },
    { key: 'total_duration', label: 'Durée totale', sortable: true, headerClass: 'text-right' },
    { key: 'avg_duration', label: 'Durée moy.', sortable: true, headerClass: 'text-right' },
    { key: 'ca', label: 'CA (€)', sortable: true, headerClass: 'text-right' },
    { key: 'reverse', label: 'Reverse (€)', sortable: true, headerClass: 'text-right' },
    { key: 'benefice', label: 'Bénéfice (€)', sortable: true, headerClass: 'text-right' },
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

// ─── No processed data needed - ApexCharts handles this ──────────────────────

// ─── Helpers stats ────────────────────────────────────────────────────────────
const compLabels = computed(() => {
    if (period.value === 'today') {
        return { comp1: 'Hier', comp1Key: 'yesterday', comp2: 'S-1', comp2Key: 'last_week' }
    } else if (period.value === 'this_week') {
        return { comp1: 'Sem. dern.', comp1Key: 'last_week', comp2: 'S-2', comp2Key: 'two_weeks_ago' }
    } else {
        return { comp1: 'Mois dern.', comp1Key: 'last_month', comp2: 'M-2', comp2Key: 'two_months_ago' }
    }
})

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDayName(dateString) {
    const date = new Date(dateString)
    const dayName = date.toLocaleDateString('fr-FR', { weekday: 'long' })
    return dayName.charAt(0).toUpperCase() + dayName.slice(1)
}

function formatDateShort(dateString) {
    const date = new Date(dateString)
    return date.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
    })
}

function isSunday(dateString) {
    const date = new Date(dateString)
    return date.getDay() === 0
}

function formatDurationTotal(seconds) {
    if (!seconds) return '-'
    const hours = Math.floor(seconds / 3600)
    const mins = Math.floor((seconds % 3600) / 60)
    const secs = seconds % 60
    return `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

function formatDuration(seconds) {
    if (!seconds) return '-'
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

function formatNumber(value) {
    if (value === null || value === undefined) return '-'
    // Format personnalisé : espace pour milliers, pas de décimales
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)
    // Remplacer les virgules par des espaces
    return formatted.replace(/,/g, ' ')
}

function formatCurrency(value) {
    if (value === null || value === undefined) return '-'
    // Format personnalisé : espace pour milliers, point pour décimales
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
    // Remplacer les virgules par des espaces
    return formatted.replace(/,/g, ' ')
}

function getVariation(current, previous) {
    // Gérer le cas où previous = 0 (dimanche fermé par exemple)
    if (!previous || previous === 0) {
        if (current === 0) return null // Les deux à 0
        return null // On affiche rien si on compare avec 0
    }
    return ((current - previous) / previous) * 100
}

function getVariationClass(variation) {
    if (variation === null) return 'text-gray-400'
    if (variation > 0) return 'text-green-600'
    if (variation < 0) return 'text-red-600'
    return 'text-gray-500'
}

function getVariationSymbol(variation) {
    if (variation === null) return '-'
    if (variation > 0) return '↗'
    if (variation < 0) return '↘'
    return '→'
}

function formatVariation(variation) {
    if (variation === null) return '-'
    return Math.abs(variation).toFixed(1) + '%'
}

// ─── Handle day click ─────────────────────────────────────────────────────────
function selectDay(date) {
    // Ne pas permettre de sélectionner un dimanche
    if (isSunday(date)) return

    selectedDate.value = date
    showHourlyModal.value = true
    // Charger les données horaires avec les mêmes filtres
    hourly.load({ ...filters, date })
}

function closeHourlyView() {
    showHourlyModal.value = false
    selectedDate.value = null
}

// ─── Check if hour is current/future (basé sur l'heure FR du backend) ────────
function isToday(dateString) {
    const date = new Date(dateString)
    const today = new Date()
    return date.toDateString() === today.toDateString()
}

function isCurrentHour(hourString, currentHourFR) {
    if (currentHourFR === undefined || currentHourFR === null) return false
    const hour = parseInt(hourString.split('h')[0])
    return hour === currentHourFR
}

function isFutureHour(hourString, currentHourFR) {
    if (currentHourFR === undefined || currentHourFR === null) return false
    const hour = parseInt(hourString.split('h')[0])
    return hour > currentHourFR
}

// ─── Watch period changes (KPIs only) ─────────────────────────────────────────
watch(period, () => {
    stats.load({ period: period.value })
})

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load({ period: period.value })
    loadFilterOptions()
    daily.load(filters)
    brandDistribution.load(filters)
})
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>

            <!-- Sélecteur de période + Hero KPIs -->
            <div class="lg:flex items-start gap-4">
                <!-- Sélecteur de période -->
                <div class="flex lg:shrink-0 lg:flex-col gap-1.5 mb-4 lg:mb-0">
                    <button
                        v-for="p in [
                            { value: 'today', label: 'Aujourd\'hui' },
                            { value: 'this_week', label: 'Cette semaine' },
                            { value: 'this_month', label: 'Ce mois' }
                        ]"
                        :key="p.value"
                        @click="period = p.value"
                        :class="[
                            'rounded-md px-3 py-2 text-xs font-medium transition-all min-w-[110px] w-full',
                            period === p.value
                                ? 'bg-gray-900 text-white'
                                : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200'
                        ]"
                    >
                        {{ p.label }}
                    </button>
                </div>

                <!-- Hero KPIs -->
                <div class="grid flex-1 grid-cols-1 gap-4 md: grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    <!-- Appels -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Appels</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatNumber(stats.data?.current.calls) }}
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatNumber(stats.data[compLabels.comp1Key].calls) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.calls, stats.data[compLabels.comp1Key].calls))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.calls, stats.data[compLabels.comp1Key].calls)) }} {{ formatVariation(getVariation(stats.data.current.calls, stats.data[compLabels.comp1Key].calls)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatNumber(stats.data[compLabels.comp2Key].calls) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.calls, stats.data[compLabels.comp2Key].calls))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.calls, stats.data[compLabels.comp2Key].calls)) }} {{ formatVariation(getVariation(stats.data.current.calls, stats.data[compLabels.comp2Key].calls)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Durée totale -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Durée totale</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatDurationTotal(stats.data?.current.total_duration) }}
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatDurationTotal(stats.data[compLabels.comp1Key].total_duration) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp1Key].total_duration))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp1Key].total_duration)) }} {{ formatVariation(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp1Key].total_duration)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatDurationTotal(stats.data[compLabels.comp2Key].total_duration) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp2Key].total_duration))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp2Key].total_duration)) }} {{ formatVariation(getVariation(stats.data.current.total_duration, stats.data[compLabels.comp2Key].total_duration)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Durée moyenne -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Durée moy.</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatDuration(stats.data?.current.avg_duration) }}
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatDuration(stats.data[compLabels.comp1Key].avg_duration) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp1Key].avg_duration))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp1Key].avg_duration)) }} {{ formatVariation(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp1Key].avg_duration)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatDuration(stats.data[compLabels.comp2Key].avg_duration) }}</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp2Key].avg_duration))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp2Key].avg_duration)) }} {{ formatVariation(getVariation(stats.data.current.avg_duration, stats.data[compLabels.comp2Key].avg_duration)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- CA -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">CA</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatCurrency(stats.data?.current.ca) }} €
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatCurrency(stats.data[compLabels.comp1Key].ca) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.ca, stats.data[compLabels.comp1Key].ca))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.ca, stats.data[compLabels.comp1Key].ca)) }} {{ formatVariation(getVariation(stats.data.current.ca, stats.data[compLabels.comp1Key].ca)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatCurrency(stats.data[compLabels.comp2Key].ca) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.ca, stats.data[compLabels.comp2Key].ca))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.ca, stats.data[compLabels.comp2Key].ca)) }} {{ formatVariation(getVariation(stats.data.current.ca, stats.data[compLabels.comp2Key].ca)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Reverse -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Reverse</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatCurrency(stats.data?.current.reverse) }} €
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatCurrency(stats.data[compLabels.comp1Key].reverse) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.reverse, stats.data[compLabels.comp1Key].reverse))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.reverse, stats.data[compLabels.comp1Key].reverse)) }} {{ formatVariation(getVariation(stats.data.current.reverse, stats.data[compLabels.comp1Key].reverse)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatCurrency(stats.data[compLabels.comp2Key].reverse) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.reverse, stats.data[compLabels.comp2Key].reverse))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.reverse, stats.data[compLabels.comp2Key].reverse)) }} {{ formatVariation(getVariation(stats.data.current.reverse, stats.data[compLabels.comp2Key].reverse)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Bénéfice -->
                    <div class="rounded-lg border border-gray-200 bg-white p-4" style="min-height: 134px;">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">Bénéfice</div>
                        <div v-if="stats.loading" class="mt-2 h-8 animate-pulse rounded bg-gray-100"></div>
                        <template v-else>
                            <div class="mt-2 text-2xl font-bold text-gray-900">
                                {{ formatCurrency(stats.data?.current.benefice) }} €
                            </div>
                            <div v-if="!stats.loading && stats.data && stats.data[compLabels.comp1Key] && stats.data[compLabels.comp2Key]" class="mt-2 space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp1 }}: {{ formatCurrency(stats.data[compLabels.comp1Key].benefice) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.benefice, stats.data[compLabels.comp1Key].benefice))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.benefice, stats.data[compLabels.comp1Key].benefice)) }} {{ formatVariation(getVariation(stats.data.current.benefice, stats.data[compLabels.comp1Key].benefice)) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500">{{ compLabels.comp2 }}: {{ formatCurrency(stats.data[compLabels.comp2Key].benefice) }} €</span>
                                    <span :class="getVariationClass(getVariation(stats.data.current.benefice, stats.data[compLabels.comp2Key].benefice))">
                                        {{ getVariationSymbol(getVariation(stats.data.current.benefice, stats.data[compLabels.comp2Key].benefice)) }} {{ formatVariation(getVariation(stats.data.current.benefice, stats.data[compLabels.comp2Key].benefice)) }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Filtres -->
            <FilterBar
                :has-active-filters="hasFilters"
                :is-loading="isLoadingOptions"
                @reset="reset"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-8">
                    <!-- 1. Mois -->
                    <FilterSelect
                        v-model="filters.month"
                        :options="monthOptions"
                        placeholder="Mois"
                        :searchable="false"
                    />

                    <!-- 2. Sources -->
                    <FilterSelect
                        v-model="filters.source_id"
                        :options="filterOptions.sources"
                        placeholder="Sources"
                        multiple
                    />

                    <!-- 3. Providers -->
                    <FilterSelect
                        v-model="filters.provider_id"
                        :options="filterOptions.providers"
                        placeholder="Providers"
                        multiple
                    />

                    <!-- 4. Companies -->
                    <FilterSelect
                        v-model="filters.company_id"
                        :options="filterOptions.companies"
                        placeholder="Companies"
                        multiple
                    />

                    <!-- 5. Marques -->
                    <FilterSelect
                        v-model="filters.brand_name"
                        :options="filterOptions.brands"
                        placeholder="Marques"
                        searchable
                        multiple
                    />

                    <!-- 6. Opérateurs -->
                    <FilterSelect
                        v-model="filters.carrier"
                        :options="filterOptions.carriers"
                        placeholder="Opérateurs"
                        multiple
                    />

                    <!-- 7. Call Centers -->
                    <FilterSelect
                        v-model="filters.callcenter_id"
                        :options="filterOptions.callcenters"
                        placeholder="Call Centers"
                        multiple
                    />

                    <!-- 8. Agents -->
                    <FilterSelect
                        v-model="filters.agent_name"
                        :options="filterOptions.agents"
                        placeholder="Agents"
                        searchable
                        multiple
                    />
                </div>
            </FilterBar>

            <!-- Tableau jour par jour -->
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th
                                    v-for="col in columns"
                                    :key="col.key"
                                    class="whitespace-nowrap px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-400"
                                    :class="[col.headerClass || 'text-left', col.sortable && 'cursor-pointer select-none hover:text-gray-700']"
                                    @click="col.sortable && toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <template v-if="col.sortable">
                                        <span v-if="sortKey === col.key && sortDir === 'asc'">↑</span>
                                        <span v-else-if="sortKey === col.key && sortDir === 'desc'">↓</span>
                                        <span v-else class="opacity-20">⇅</span>
                                    </template>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <!-- Total en haut -->
                            <tr v-if="daily.data?.totals" class="border-t border-b border-gray-200 bg-gray-50">
                                <td class="px-3 py-2 text-left font-bold text-gray-900">Total</td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatNumber(daily.data.totals.calls) }}</div>
                                    <div v-if="daily.data.totals.prev_calls !== null" class="text-xs text-gray-500">
                                        {{ formatNumber(daily.data.totals.prev_calls) }}
                                        <span v-if="daily.data.totals.calls_var !== null" :class="daily.data.totals.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.calls_var >= 0 ? '+' : '' }}{{ daily.data.totals.calls_var }}%)
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatDurationTotal(daily.data.totals.total_duration) }}</div>
                                    <div v-if="daily.data.totals.prev_total_duration !== null" class="text-xs text-gray-500">
                                        {{ formatDurationTotal(daily.data.totals.prev_total_duration) }}
                                        <span v-if="daily.data.totals.total_duration_var !== null" :class="daily.data.totals.total_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.total_duration_var >= 0 ? '+' : '' }}{{ daily.data.totals.total_duration_var }}%)
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatDuration(daily.data.totals.avg_duration) }}</div>
                                    <div v-if="daily.data.totals.prev_avg_duration !== null" class="text-xs text-gray-500">
                                        {{ formatDuration(daily.data.totals.prev_avg_duration) }}
                                        <span v-if="daily.data.totals.avg_duration_var !== null" :class="daily.data.totals.avg_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.avg_duration_var >= 0 ? '+' : '' }}{{ daily.data.totals.avg_duration_var }}%)
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatCurrency(daily.data.totals.ca) }} €</div>
                                    <div v-if="daily.data.totals.prev_ca !== null" class="text-xs text-gray-500">
                                        {{ formatCurrency(daily.data.totals.prev_ca) }} €
                                        <span v-if="daily.data.totals.ca_var !== null" :class="daily.data.totals.ca_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.ca_var >= 0 ? '+' : '' }}{{ daily.data.totals.ca_var }}%)
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatCurrency(daily.data.totals.reverse) }} €</div>
                                    <div v-if="daily.data.totals.prev_reverse !== null" class="text-xs text-gray-500">
                                        {{ formatCurrency(daily.data.totals.prev_reverse) }} €
                                        <span v-if="daily.data.totals.reverse_var !== null" :class="daily.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.reverse_var >= 0 ? '+' : '' }}{{ daily.data.totals.reverse_var }}%)
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-bold">{{ formatCurrency(daily.data.totals.benefice) }} €</div>
                                    <div v-if="daily.data.totals.prev_benefice !== null" class="text-xs text-gray-500">
                                        {{ formatCurrency(daily.data.totals.prev_benefice) }} €
                                        <span v-if="daily.data.totals.benefice_var !== null" :class="daily.data.totals.benefice_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.benefice_var >= 0 ? '+' : '' }}{{ daily.data.totals.benefice_var }}%)
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- Skeleton loading -->
                            <template v-if="daily.loading">
                                <tr v-for="n in 8" :key="n" class="border-b border-gray-50">
                                    <td v-for="col in columns" :key="col.key" class="px-3 py-2">
                                        <div class="h-4 animate-pulse rounded bg-gray-50" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes -->
                            <template v-else-if="sortedRows.length > 0">
                                <tr
                                    v-for="(row, i) in sortedRows"
                                    :key="i"
                                    :class="[
                                        'border-b border-gray-50 transition-colors',
                                        isSunday(row.date)
                                            ? 'bg-gray-50'
                                            : 'hover:bg-gray-100/80 cursor-pointer'
                                    ]"
                                    @click="!isSunday(row.date) && selectDay(row.date)"
                                >
                                    <td class="px-3 py-1.5 text-sm">
                                        <div :class="isSunday(row.date) ? 'font-medium text-gray-400' : 'font-medium text-gray-900'">{{ row.date_label }}</div>
                                        <div :class="isSunday(row.date) ? 'text-xs text-gray-400' : 'text-xs text-gray-500'">{{ row.comparison_label }}</div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatNumber(row.calls) }}</div>
                                        <div v-if="row.prev_calls !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatNumber(row.prev_calls) }}
                                            <span :class="row.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.calls_var >= 0 ? '+' : '' }}{{ row.calls_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatDurationTotal(row.total_duration) }}</div>
                                        <div v-if="row.prev_total_duration !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatDurationTotal(row.prev_total_duration) }}
                                            <span :class="row.total_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.total_duration_var >= 0 ? '+' : '' }}{{ row.total_duration_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatDuration(row.avg_duration) }}</div>
                                        <div v-if="row.prev_avg_duration !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatDuration(row.prev_avg_duration) }}
                                            <span :class="row.avg_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.avg_duration_var >= 0 ? '+' : '' }}{{ row.avg_duration_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatCurrency(row.ca) }} €</div>
                                        <div v-if="row.prev_ca !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_ca) }} €
                                            <span :class="row.ca_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.ca_var >= 0 ? '+' : '' }}{{ row.ca_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatCurrency(row.reverse) }} €</div>
                                        <div v-if="row.prev_reverse !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'font-bold text-gray-400' : 'font-bold text-gray-900'">{{ formatCurrency(row.benefice) }} €</div>
                                        <div v-if="row.prev_benefice !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_benefice) }} €
                                            <span :class="row.benefice_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.benefice_var >= 0 ? '+' : '' }}{{ row.benefice_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <!-- Vide -->
                            <template v-else>
                                <tr>
                                    <td :colspan="columns.length" class="px-3 py-8 text-center text-sm text-gray-300">
                                        Aucune donnée
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal de détail heure par heure -->
            <Dialog :open="showHourlyModal" @update:open="closeHourlyView">
                <DialogContent class="!w-[95vw] md:!w-[90vw] lg:!w-[85vw] xl:!w-[80vw] !max-w-none max-h-[90vh] overflow-y-auto">
                    <DialogHeader class="pb-4">
                        <DialogTitle class="text-xl">
                            {{ hourly.data?.date_label || 'Détail de la journée' }}
                        </DialogTitle>
                        <p class="text-sm text-gray-500 mt-1">
                            Comparaison avec {{ hourly.data?.comparison_label || '' }}
                        </p>
                    </DialogHeader>

                <!-- Totals de la journée -->
                <div v-if="hourly.data?.totals" class="border-y border-gray-200 bg-gray-50 px-6 py-4 -mx-6 mb-6">
                    <div class="grid grid-cols-2 gap-6 md:grid-cols-3 lg:grid-cols-6">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Appels</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatNumber(hourly.data.totals.calls) }}</div>
                            <div v-if="hourly.data.totals.prev_calls !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatNumber(hourly.data.totals.prev_calls) }}
                                <span v-if="hourly.data.totals.calls_var !== null" :class="hourly.data.totals.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.calls_var >= 0 ? '+' : '' }}{{ hourly.data.totals.calls_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Durée totale</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatDurationTotal(hourly.data.totals.total_duration) }}</div>
                            <div v-if="hourly.data.totals.prev_total_duration !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatDurationTotal(hourly.data.totals.prev_total_duration) }}
                                <span v-if="hourly.data.totals.total_duration_var !== null" :class="hourly.data.totals.total_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.total_duration_var >= 0 ? '+' : '' }}{{ hourly.data.totals.total_duration_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Durée moy.</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatDuration(hourly.data.totals.avg_duration) }}</div>
                            <div v-if="hourly.data.totals.prev_avg_duration !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatDuration(hourly.data.totals.prev_avg_duration) }}
                                <span v-if="hourly.data.totals.avg_duration_var !== null" :class="hourly.data.totals.avg_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.avg_duration_var >= 0 ? '+' : '' }}{{ hourly.data.totals.avg_duration_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">CA</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatCurrency(hourly.data.totals.ca) }} €</div>
                            <div v-if="hourly.data.totals.prev_ca !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatCurrency(hourly.data.totals.prev_ca) }} €
                                <span v-if="hourly.data.totals.ca_var !== null" :class="hourly.data.totals.ca_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.ca_var >= 0 ? '+' : '' }}{{ hourly.data.totals.ca_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Reverse</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatCurrency(hourly.data.totals.reverse) }} €</div>
                            <div v-if="hourly.data.totals.prev_reverse !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatCurrency(hourly.data.totals.prev_reverse) }} €
                                <span v-if="hourly.data.totals.reverse_var !== null" :class="hourly.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.reverse_var >= 0 ? '+' : '' }}{{ hourly.data.totals.reverse_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Bénéfice</div>
                            <div class="text-xl font-bold text-gray-900">{{ formatCurrency(hourly.data.totals.benefice) }} €</div>
                            <div v-if="hourly.data.totals.prev_benefice !== null" class="text-xs text-gray-500 mt-1">
                                {{ formatCurrency(hourly.data.totals.prev_benefice) }} €
                                <span v-if="hourly.data.totals.benefice_var !== null" :class="hourly.data.totals.benefice_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.benefice_var >= 0 ? '+' : '' }}{{ hourly.data.totals.benefice_var }}%)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table heure par heure -->
                <div class="overflow-x-auto -mx-6">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="border-y border-gray-200">
                                <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Heure</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Appels</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Durée totale</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Durée moy.</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">CA (€)</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Reverse (€)</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Bénéfice (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Loading -->
                            <template v-if="hourly.loading">
                                <tr v-for="n in 12" :key="n" class="border-b border-gray-100">
                                    <td v-for="col in 7" :key="col" class="px-6 py-3">
                                        <div class="h-4 animate-pulse rounded bg-gray-100" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes horaires -->
                            <template v-else-if="hourly.data?.items && hourly.data.items.length > 0">
                                <tr
                                    v-for="(row, i) in hourly.data.items"
                                    :key="i"
                                    :class="[
                                        'border-b border-gray-100 transition-colors',
                                        isToday(hourly.data.date) && isCurrentHour(row.hour, hourly.data.current_hour)
                                            ? 'bg-blue-50 hover:bg-blue-100'
                                            : isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour)
                                            ? 'bg-gray-50 opacity-50'
                                            : 'hover:bg-gray-50'
                                    ]"
                                >
                                    <td class="px-6 py-3 text-sm font-semibold" :class="isToday(hourly.data.date) && isCurrentHour(row.hour, hourly.data.current_hour) ? 'text-blue-900' : 'text-gray-900'">
                                        {{ row.hour }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <div class="text-gray-900">{{ formatNumber(row.calls) }}</div>
                                        <div v-if="row.prev_calls !== null" class="text-xs text-gray-500">
                                            {{ formatNumber(row.prev_calls) }}
                                            <span v-if="row.calls_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.calls_var >= 0 ? '+' : '' }}{{ row.calls_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <div class="text-gray-900">{{ formatDurationTotal(row.total_duration) }}</div>
                                        <div v-if="row.prev_total_duration !== null" class="text-xs text-gray-500">
                                            {{ formatDurationTotal(row.prev_total_duration) }}
                                            <span v-if="row.total_duration_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.total_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.total_duration_var >= 0 ? '+' : '' }}{{ row.total_duration_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <div class="text-gray-900">{{ formatDuration(row.avg_duration) }}</div>
                                        <div v-if="row.prev_avg_duration !== null" class="text-xs text-gray-500">
                                            {{ formatDuration(row.prev_avg_duration) }}
                                            <span v-if="row.avg_duration_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.avg_duration_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.avg_duration_var >= 0 ? '+' : '' }}{{ row.avg_duration_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <div class="text-gray-900">{{ formatCurrency(row.ca) }} €</div>
                                        <div v-if="row.prev_ca !== null" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_ca) }} €
                                            <span v-if="row.ca_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.ca_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.ca_var >= 0 ? '+' : '' }}{{ row.ca_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <div class="text-gray-900">{{ formatCurrency(row.reverse) }} €</div>
                                        <div v-if="row.prev_reverse !== null" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span v-if="row.reverse_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-right text-sm">
                                        <div class="font-bold text-gray-900">{{ formatCurrency(row.benefice) }} €</div>
                                        <div v-if="row.prev_benefice !== null" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_benefice) }} €
                                            <span v-if="row.benefice_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.benefice_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.benefice_var >= 0 ? '+' : '' }}{{ row.benefice_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                </DialogContent>
            </Dialog>

            <!-- Treemap des marques (après le tableau) -->
            <BrandTreemap
                :brands="brandDistribution.data?.brands || []"
                :total-count="brandDistribution.data?.total_count || 0"
                :loading="brandDistribution.loading"
            />
        </div>
    </AppLayout>
</template>
