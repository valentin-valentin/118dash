<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'

const props = defineProps({
    sources: Array,
    sourceIds: Array,
    sourcesParam: String,
    hash: String,
})

// ─── View mode ────────────────────────────────────────────────────────────────
const viewMode = ref('month') // 'month' | 'year'

// ─── Month Selection ──────────────────────────────────────────────────────────
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

// ─── Year Selection ───────────────────────────────────────────────────────────
const yearOptions = computed(() => {
    const years = []
    const currentYear = new Date().getFullYear()
    for (let i = 0; i < 3; i++) {
        years.push({ value: String(currentYear - i), label: String(currentYear - i) })
    }
    return years
})

// ─── Daily Breakdown ──────────────────────────────────────────────────────────
const daily = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/daily-breakdown`)

// ─── Monthly Breakdown ────────────────────────────────────────────────────────
const monthly = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/monthly-breakdown`)

// ─── Hourly Breakdown ─────────────────────────────────────────────────────────
const showHourlyModal = ref(false)
const selectedDate = ref(null)
const hourly = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/hourly-breakdown`)

// ─── Soldes & paiements ──────────────────────────────────────────────────────
const balances = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/balances`)
const payments = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/payments`)
const paymentsPage = ref(1)

// ─── Réconciliation (chargement à la demande) ────────────────────────────────
const reconcile = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/reconcile`)
const showReconcileModal = ref(false)

function openReconcileModal() {
    showReconcileModal.value = true
    reconcile.load({ source_id: filters.source_id })
}

function closeReconcileModal() {
    showReconcileModal.value = false
}

function loadBalancesAndPayments(sourceFilter) {
    const payload = { source_id: sourceFilter }
    balances.load(payload)
    payments.load({ ...payload, page: paymentsPage.value, per_page: 20 })
}

const { filters, reset } = useFilters(
    {
        month: monthOptions.value[0].value,
        year: String(new Date().getFullYear()),
        source_id: props.sources.length > 1 ? [] : [props.sources[0].value],
    },
    (f) => {
        if (viewMode.value === 'month') {
            daily.load(f)
        } else {
            monthly.load(f)
        }
        paymentsPage.value = 1
        loadBalancesAndPayments(f.source_id)
    },
)

watch(paymentsPage, () => {
    payments.load({
        source_id: filters.source_id,
        page: paymentsPage.value,
        per_page: 20,
    })
})

watch(viewMode, (mode) => {
    if (mode === 'month') {
        daily.load(filters)
    } else {
        monthly.load(filters)
    }
})

function resetAll() {
    viewMode.value = 'month'
    reset()
}

const hasFilters = computed(() => {
    if (viewMode.value === 'month') {
        return (filters.month !== monthOptions.value[0].value) ||
            (Array.isArray(filters.source_id) && filters.source_id.length > 0)
    } else {
        return (filters.year !== String(new Date().getFullYear())) ||
            (Array.isArray(filters.source_id) && filters.source_id.length > 0)
    }
})

// ─── Colonnes tableau ─────────────────────────────────────────────────────────
const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'calls', label: 'Appels', sortable: true, headerClass: 'text-right' },
    { key: 'reverse', label: 'Reverse (€)', sortable: true, headerClass: 'text-right' },
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

function formatNumber(value) {
    if (value === null || value === undefined) return '-'
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)
    return formatted.replace(/,/g, ' ')
}

function formatCurrency(value) {
    if (value === null || value === undefined) return '-'
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
    return formatted.replace(/,/g, ' ')
}

function formatPaymentDate(value) {
    if (!value) return '-'
    const d = new Date(value)
    return d.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    })
}

function getVariation(current, previous) {
    if (!previous || previous === 0) {
        if (current === 0) return null
        return null
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
    if (isSunday(date)) return

    selectedDate.value = date
    showHourlyModal.value = true
    hourly.load({ ...filters, date })
}

function closeHourlyView() {
    showHourlyModal.value = false
    selectedDate.value = null
}

// ─── Source title ─────────────────────────────────────────────────────────────
const sourceTitle = computed(() => {
    if (props.sources.length === 1) {
        return props.sources[0].label
    }
    return props.sources.map(s => s.label).join(', ')
})

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    daily.load(filters)
    loadBalancesAndPayments(filters.source_id)
})

// ─── Monthly sorted rows ──────────────────────────────────────────────────────
const sortedMonthlyRows = computed(() => {
    if (!monthly.data?.items) return []
    return [...monthly.data.items]
})
</script>

<template>
    <Head title="Statistiques Partenaire" />

    <div class="min-h-screen bg-gray-50">
        <div class="max-w-2xl mx-auto p-6 space-y-4">
            <!-- Titre -->
            <div class="text-center">
                <h1 class="text-2xl font-bold text-gray-900">{{ sourceTitle }}</h1>
                <p class="text-sm text-gray-500 mt-1">Statistiques partenaire</p>
            </div>

            <!-- Soldes -->
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-2">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ balances.data?.items && balances.data.items.length > 1 ? 'Soldes' : 'Solde' }}
                    </h2>
                    <button
                        type="button"
                        class="rounded border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:bg-gray-100"
                        @click="openReconcileModal"
                    >
                        Réconciliation
                    </button>
                </div>
                <template v-if="balances.loading">
                    <div class="px-4 py-3">
                        <div class="h-5 w-32 animate-pulse rounded bg-gray-100" />
                    </div>
                </template>
                <template v-else-if="balances.data?.items && balances.data.items.length > 0">
                    <ul class="divide-y divide-gray-100">
                        <li
                            v-for="item in balances.data.items"
                            :key="item.id"
                            class="flex items-center justify-between px-4 py-2"
                        >
                            <span class="text-sm text-gray-700">{{ item.name }}</span>
                            <span class="font-mono text-sm font-semibold tabular-nums text-gray-900">
                                {{ formatCurrency(item.solde) }} €
                            </span>
                        </li>
                        <li
                            v-if="balances.data.items.length > 1"
                            class="flex items-center justify-between bg-gray-50 px-4 py-2"
                        >
                            <span class="text-sm font-bold text-gray-900">Total</span>
                            <span class="font-mono text-sm font-bold tabular-nums text-gray-900">
                                {{ formatCurrency(balances.data.total) }} €
                            </span>
                        </li>
                    </ul>
                </template>
                <template v-else>
                    <div class="px-4 py-3 text-sm text-gray-400">Aucun solde</div>
                </template>
            </div>

            <!-- Filtres -->
            <FilterBar
                :has-active-filters="hasFilters"
                :is-loading="false"
                @reset="resetAll"
            >
                <div class="flex flex-col gap-3">
                    <!-- Toggle Mois / Année -->
                    <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
                        <button
                            class="flex-1 px-3 py-1.5 transition-colors"
                            :class="viewMode === 'month' ? 'bg-gray-900 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                            @click="viewMode = 'month'"
                        >Par mois</button>
                        <button
                            class="flex-1 px-3 py-1.5 transition-colors border-l border-gray-200"
                            :class="viewMode === 'year' ? 'bg-gray-900 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                            @click="viewMode = 'year'"
                        >Par année</button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <!-- Mois ou Année -->
                        <FilterSelect
                            v-if="viewMode === 'month'"
                            v-model="filters.month"
                            :options="monthOptions"
                            placeholder="Mois"
                            :searchable="false"
                        />
                        <FilterSelect
                            v-else
                            v-model="filters.year"
                            :options="yearOptions"
                            placeholder="Année"
                            :searchable="false"
                        />

                        <!-- Sources (si plusieurs) -->
                        <FilterSelect
                            v-if="sources.length > 1"
                            v-model="filters.source_id"
                            :options="sources"
                            placeholder="Sources"
                            multiple
                        />
                    </div>
                </div>
            </FilterBar>

            <!-- Tableau jour par jour -->
            <div v-if="viewMode === 'month'" class="rounded-lg border border-gray-200 bg-white overflow-hidden">
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
                                    <!-- <div v-if="daily.data.totals.prev_calls !== null" class="text-xs text-gray-500">
                                        {{ formatNumber(daily.data.totals.prev_calls) }}
                                        <span v-if="daily.data.totals.calls_var !== null" :class="daily.data.totals.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.calls_var >= 0 ? '+' : '' }}{{ daily.data.totals.calls_var }}%)
                                        </span>
                                    </div> -->
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-bold">{{ formatCurrency(daily.data.totals.reverse) }} €</div>
                                    <!-- <div v-if="daily.data.totals.prev_reverse !== null" class="text-xs text-gray-500">
                                        {{ formatCurrency(daily.data.totals.prev_reverse) }} €
                                        <span v-if="daily.data.totals.reverse_var !== null" :class="daily.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.reverse_var >= 0 ? '+' : '' }}{{ daily.data.totals.reverse_var }}%)
                                        </span>
                                    </div> -->
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
                                        <!-- <div :class="isSunday(row.date) ? 'text-xs text-gray-400' : 'text-xs text-gray-500'">{{ row.comparison_label }}</div> -->
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ formatNumber(row.calls) }}</div>
                                        <!-- <div v-if="row.prev_calls !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatNumber(row.prev_calls) }}
                                            <span :class="row.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.calls_var >= 0 ? '+' : '' }}{{ row.calls_var }}%)
                                            </span>
                                        </div> -->
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm">
                                        <div :class="isSunday(row.date) ? 'font-bold text-gray-400' : 'font-bold text-gray-900'">{{ formatCurrency(row.reverse) }} €</div>
                                        <!-- <div v-if="row.prev_reverse !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
                                            </span>
                                        </div> -->
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

            <!-- Tableau mois par mois (vue annuelle) -->
            <div v-if="viewMode === 'year'" class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="whitespace-nowrap px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-400 text-left">Mois</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-400 text-right">Appels</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-400 text-right">Reverse (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Total en haut -->
                            <tr v-if="monthly.data?.totals" class="border-t border-b border-gray-200 bg-gray-50">
                                <td class="px-3 py-2 text-left font-bold text-gray-900">Total</td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-semibold">{{ formatNumber(monthly.data.totals.calls) }}</div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <div class="text-gray-900 font-bold">{{ formatCurrency(monthly.data.totals.reverse) }} €</div>
                                </td>
                            </tr>

                            <!-- Skeleton loading -->
                            <template v-if="monthly.loading">
                                <tr v-for="n in 6" :key="n" class="border-b border-gray-50">
                                    <td v-for="col in 3" :key="col" class="px-3 py-2">
                                        <div class="h-4 animate-pulse rounded bg-gray-50" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes -->
                            <template v-else-if="sortedMonthlyRows.length > 0">
                                <tr
                                    v-for="(row, i) in sortedMonthlyRows"
                                    :key="i"
                                    class="border-b border-gray-50"
                                >
                                    <td class="px-3 py-1.5 text-sm font-medium text-gray-900">{{ row.month_label }}</td>
                                    <td class="px-3 py-1.5 text-right text-sm text-gray-900">{{ formatNumber(row.calls) }}</td>
                                    <td class="px-3 py-1.5 text-right text-sm font-bold text-gray-900">{{ formatCurrency(row.reverse) }} €</td>
                                </tr>
                            </template>

                            <!-- Vide -->
                            <template v-else>
                                <tr>
                                    <td colspan="3" class="px-3 py-8 text-center text-sm text-gray-300">
                                        Aucune donnée
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Historique des paiements -->
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Historique des paiements
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="whitespace-nowrap px-3 py-1.5 text-left text-xs font-medium uppercase tracking-wide text-gray-400">Date</th>
                                <th
                                    v-if="sources.length > 1"
                                    class="whitespace-nowrap px-3 py-1.5 text-left text-xs font-medium uppercase tracking-wide text-gray-400"
                                >Source</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-left text-xs font-medium uppercase tracking-wide text-gray-400">Description</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-right text-xs font-medium uppercase tracking-wide text-gray-400">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Skeleton -->
                            <template v-if="payments.loading">
                                <tr v-for="n in 5" :key="n" class="border-b border-gray-50">
                                    <td v-for="col in (sources.length > 1 ? 4 : 3)" :key="col" class="px-3 py-2">
                                        <div class="h-4 animate-pulse rounded bg-gray-50" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes -->
                            <template v-else-if="payments.data?.items && payments.data.items.length > 0">
                                <tr
                                    v-for="row in payments.data.items"
                                    :key="row.id"
                                    class="border-b border-gray-50"
                                >
                                    <td class="px-3 py-1.5 text-sm text-gray-700 whitespace-nowrap">
                                        {{ formatPaymentDate(row.created_at) }}
                                    </td>
                                    <td v-if="sources.length > 1" class="px-3 py-1.5 text-sm text-gray-700">
                                        {{ row.source?.name || '-' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-sm text-gray-700">
                                        {{ row.description || '-' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-sm font-semibold tabular-nums whitespace-nowrap"
                                        :class="row.type === 'credit' ? 'text-green-700' : 'text-red-700'"
                                    >
                                        {{ row.type === 'credit' ? '+' : '−' }}{{ formatCurrency(row.amount) }} €
                                    </td>
                                </tr>
                            </template>

                            <!-- Vide -->
                            <template v-else>
                                <tr>
                                    <td :colspan="sources.length > 1 ? 4 : 3" class="px-3 py-8 text-center text-sm text-gray-300">
                                        Aucun paiement
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="payments.data?.last_page > 1"
                    class="flex items-center justify-between border-t border-gray-100 px-4 py-2 text-xs text-gray-500"
                >
                    <span>
                        Page {{ payments.data.current_page }} / {{ payments.data.last_page }}
                        · {{ payments.data.total }} paiement{{ payments.data.total !== 1 ? 's' : '' }}
                    </span>
                    <div class="flex gap-1">
                        <button
                            class="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 disabled:opacity-40 hover:bg-gray-50"
                            :disabled="payments.data.current_page <= 1"
                            @click="paymentsPage = paymentsPage - 1"
                        >Précédent</button>
                        <button
                            class="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 disabled:opacity-40 hover:bg-gray-50"
                            :disabled="payments.data.current_page >= payments.data.last_page"
                            @click="paymentsPage = paymentsPage + 1"
                        >Suivant</button>
                    </div>
                </div>
            </div>

            <!-- Modal de détail heure par heure -->
            <Dialog :open="showHourlyModal" @update:open="closeHourlyView">
                <DialogContent class="!w-[90vw] md:!w-[70vw] lg:!w-[60vw] xl:!w-[50vw] !max-w-2xl max-h-[90vh] overflow-y-auto !p-0">
                    <div class="px-6 pt-4">
                        <DialogTitle class="text-base font-bold">
                            {{ hourly.data?.date_label || 'Détail de la journée' }}
                        </DialogTitle>
                        <!-- <p class="text-xs text-gray-500">
                            Comparaison avec {{ hourly.data?.comparison_label || '' }}
                        </p> -->
                    </div>

                <!-- Totals de la journée -->
                <div v-if="hourly.data?.totals" class="border-y border-gray-200 bg-gray-50 px-6 py-2 mb-0">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Appels</div>
                            <div class="text-base font-bold text-gray-900 mt-0.5">{{ formatNumber(hourly.data.totals.calls) }}</div>
                            <!-- <div v-if="hourly.data.totals.prev_calls !== null" class="text-[11px] text-gray-500">
                                {{ formatNumber(hourly.data.totals.prev_calls) }}
                                <span v-if="hourly.data.totals.calls_var !== null" :class="hourly.data.totals.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.calls_var >= 0 ? '+' : '' }}{{ hourly.data.totals.calls_var }}%)
                                </span>
                            </div> -->
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Reverse</div>
                            <div class="text-base font-bold text-gray-900 mt-0.5">{{ formatCurrency(hourly.data.totals.reverse) }} €</div>
                            <!-- <div v-if="hourly.data.totals.prev_reverse !== null" class="text-[11px] text-gray-500">
                                {{ formatCurrency(hourly.data.totals.prev_reverse) }} €
                                <span v-if="hourly.data.totals.reverse_var !== null" :class="hourly.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.reverse_var >= 0 ? '+' : '' }}{{ hourly.data.totals.reverse_var }}%)
                                </span>
                            </div> -->
                        </div>
                    </div>
                </div>

                <!-- Table heure par heure -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <th class="whitespace-nowrap px-6 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Heure</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Appels</th>
                                <th class="whitespace-nowrap px-6 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Reverse (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Loading -->
                            <template v-if="hourly.loading">
                                <tr v-for="n in 12" :key="n" class="border-b border-gray-50">
                                    <td v-for="col in 3" :key="col" class="px-4 py-1">
                                        <div class="h-3 animate-pulse rounded bg-gray-100" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes horaires -->
                            <template v-else-if="hourly.data?.items && hourly.data.items.length > 0">
                                <tr
                                    v-for="(row, i) in hourly.data.items"
                                    :key="i"
                                    :class="[
                                        'border-b border-gray-50 transition-colors',
                                        isToday(hourly.data.date) && isCurrentHour(row.hour, hourly.data.current_hour)
                                            ? 'bg-blue-50 hover:bg-blue-100'
                                            : isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour)
                                            ? 'bg-gray-50 opacity-50'
                                            : 'hover:bg-gray-100/80'
                                    ]"
                                >
                                    <td class="px-6 py-1 text-sm font-semibold" :class="isToday(hourly.data.date) && isCurrentHour(row.hour, hourly.data.current_hour) ? 'text-blue-900' : 'text-gray-900'">
                                        {{ row.hour }}
                                    </td>
                                    <td class="px-3 py-1 text-right text-sm">
                                        <div class="text-gray-900">{{ formatNumber(row.calls) }}</div>
                                        <!-- <div v-if="row.prev_calls !== null" class="text-xs text-gray-500">
                                            {{ formatNumber(row.prev_calls) }}
                                            <span v-if="row.calls_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.calls_var >= 0 ? '+' : '' }}{{ row.calls_var }}%)
                                            </span>
                                        </div> -->
                                    </td>
                                    <td class="px-6 py-1 text-right text-sm">
                                        <div class="font-bold text-gray-900">{{ formatCurrency(row.reverse) }} €</div>
                                        <!-- <div v-if="row.prev_reverse !== null" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span v-if="row.reverse_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
                                            </span>
                                        </div> -->
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                </DialogContent>
            </Dialog>

            <!-- Modal Réconciliation -->
            <Dialog :open="showReconcileModal" @update:open="closeReconcileModal">
                <DialogContent class="!w-[90vw] md:!w-[70vw] lg:!w-[60vw] xl:!w-[50vw] !max-w-2xl max-h-[90vh] overflow-y-auto !p-0">
                    <div class="px-6 pt-4 pb-2">
                        <DialogTitle class="text-base font-bold">Réconciliation des soldes</DialogTitle>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Solde théorique = Σ payout des appels + crédits − débits
                        </p>
                    </div>

                    <!-- Loader -->
                    <div v-if="reconcile.loading" class="flex flex-col items-center justify-center py-10">
                        <svg class="h-7 w-7 animate-spin text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="mt-3 text-sm text-gray-600">Calcul en cours…</p>
                        <p class="mt-1 text-xs text-gray-400">Agrégation de tous les appels depuis le début, ça peut prendre quelques secondes.</p>
                    </div>

                    <!-- Résultat -->
                    <div v-else-if="reconcile.data?.items" class="px-6 pb-6 space-y-3">
                        <div
                            v-for="item in reconcile.data.items"
                            :key="item.id"
                            class="rounded-lg border p-3"
                            :class="item.matches ? 'border-green-200 bg-green-50/30' : 'border-red-200 bg-red-50/30'"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold text-gray-900">{{ item.name }}</span>
                                <span
                                    v-if="item.matches"
                                    class="text-xs font-medium text-green-700"
                                >✓ Conforme</span>
                                <span
                                    v-else
                                    class="font-mono text-xs font-bold text-red-700"
                                >
                                    Δ {{ item.difference > 0 ? '+' : '' }}{{ formatCurrency(item.difference) }} €
                                </span>
                            </div>
                            <div class="space-y-1 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">+ Σ appels (payout)</span>
                                    <span class="font-mono tabular-nums text-gray-900">{{ formatCurrency(item.total_payout_calls) }} €</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">+ Crédits</span>
                                    <span class="font-mono tabular-nums text-green-700">+ {{ formatCurrency(item.total_credits) }} €</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">− Débits</span>
                                    <span class="font-mono tabular-nums text-red-700">− {{ formatCurrency(item.total_debits) }} €</span>
                                </div>
                                <div class="flex items-center justify-between border-t border-gray-200 pt-1 mt-1">
                                    <span class="font-medium text-gray-900">= Solde théorique</span>
                                    <span class="font-mono font-semibold tabular-nums text-gray-900">{{ formatCurrency(item.expected_solde) }} €</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">Solde actuel</span>
                                    <span class="font-mono tabular-nums text-gray-700">{{ formatCurrency(item.current_solde) }} €</span>
                                </div>
                            </div>
                        </div>

                        <!-- Totaux (si plusieurs sources) -->
                        <div
                            v-if="reconcile.data.items.length > 1"
                            class="rounded-lg border p-3"
                            :class="reconcile.data.totals.matches ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50'"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-bold text-gray-900">Total</span>
                                <span
                                    v-if="reconcile.data.totals.matches"
                                    class="text-xs font-medium text-green-700"
                                >✓ Conforme</span>
                                <span
                                    v-else
                                    class="font-mono text-xs font-bold text-red-700"
                                >
                                    Δ {{ reconcile.data.totals.difference > 0 ? '+' : '' }}{{ formatCurrency(reconcile.data.totals.difference) }} €
                                </span>
                            </div>
                            <div class="space-y-1 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-900">Solde théorique cumulé</span>
                                    <span class="font-mono font-semibold tabular-nums text-gray-900">{{ formatCurrency(reconcile.data.totals.expected_solde) }} €</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-700">Solde actuel cumulé</span>
                                    <span class="font-mono tabular-nums text-gray-700">{{ formatCurrency(reconcile.data.totals.current_solde) }} €</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="reconcile.data.items.length === 0" class="text-center text-sm text-gray-400 py-6">
                            Aucune source à réconcilier
                        </div>
                    </div>

                    <!-- Erreur -->
                    <div v-else-if="reconcile.error" class="px-6 pb-6 text-sm text-red-600">
                        Erreur lors du calcul de la réconciliation
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
