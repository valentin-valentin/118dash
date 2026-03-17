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

// ─── Daily Breakdown ──────────────────────────────────────────────────────────
const daily = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/daily-breakdown`)

// ─── Hourly Breakdown ─────────────────────────────────────────────────────────
const showHourlyModal = ref(false)
const selectedDate = ref(null)
const hourly = useApi(`/partners/${props.sourcesParam}/${props.hash}/data/hourly-breakdown`)

const { filters, reset } = useFilters(
    {
        month: monthOptions.value[0].value,
        source_id: props.sources.length > 1 ? [] : [props.sources[0].value],
    },
    (f) => {
        daily.load(f)
    },
)

const hasFilters = computed(() =>
    (filters.month !== monthOptions.value[0].value) ||
    (Array.isArray(filters.source_id) && filters.source_id.length > 0)
)

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
})
</script>

<template>
    <Head title="Statistiques Partenaire" />

    <div class="min-h-screen bg-gray-50">
        <div class="max-w-3xl mx-auto p-6 space-y-4">
            <!-- Filtres -->
            <FilterBar
                :has-active-filters="hasFilters"
                :is-loading="false"
                @reset="reset"
            >
                <div class="grid grid-cols-2 gap-3">
                    <!-- Mois -->
                    <FilterSelect
                        v-model="filters.month"
                        :options="monthOptions"
                        placeholder="Mois"
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
                                    <div class="text-gray-900 font-bold">{{ formatCurrency(daily.data.totals.reverse) }} €</div>
                                    <div v-if="daily.data.totals.prev_reverse !== null" class="text-xs text-gray-500">
                                        {{ formatCurrency(daily.data.totals.prev_reverse) }} €
                                        <span v-if="daily.data.totals.reverse_var !== null" :class="daily.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                            ({{ daily.data.totals.reverse_var >= 0 ? '+' : '' }}{{ daily.data.totals.reverse_var }}%)
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
                                        <div :class="isSunday(row.date) ? 'font-bold text-gray-400' : 'font-bold text-gray-900'">{{ formatCurrency(row.reverse) }} €</div>
                                        <div v-if="row.prev_reverse !== null && !isSunday(row.date)" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
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
                <DialogContent class="!w-[90vw] md:!w-[70vw] lg:!w-[60vw] xl:!w-[50vw] !max-w-3xl max-h-[90vh] overflow-y-auto !p-0">
                    <div class="px-6 pt-4">
                        <DialogTitle class="text-base font-bold">
                            {{ hourly.data?.date_label || 'Détail de la journée' }}
                        </DialogTitle>
                        <p class="text-xs text-gray-500">
                            Comparaison avec {{ hourly.data?.comparison_label || '' }}
                        </p>
                    </div>

                <!-- Totals de la journée -->
                <div v-if="hourly.data?.totals" class="border-y border-gray-200 bg-gray-50 px-6 py-2 mb-0">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Appels</div>
                            <div class="text-base font-bold text-gray-900 mt-0.5">{{ formatNumber(hourly.data.totals.calls) }}</div>
                            <div v-if="hourly.data.totals.prev_calls !== null" class="text-[11px] text-gray-500">
                                {{ formatNumber(hourly.data.totals.prev_calls) }}
                                <span v-if="hourly.data.totals.calls_var !== null" :class="hourly.data.totals.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.calls_var >= 0 ? '+' : '' }}{{ hourly.data.totals.calls_var }}%)
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Reverse</div>
                            <div class="text-base font-bold text-gray-900 mt-0.5">{{ formatCurrency(hourly.data.totals.reverse) }} €</div>
                            <div v-if="hourly.data.totals.prev_reverse !== null" class="text-[11px] text-gray-500">
                                {{ formatCurrency(hourly.data.totals.prev_reverse) }} €
                                <span v-if="hourly.data.totals.reverse_var !== null" :class="hourly.data.totals.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                    ({{ hourly.data.totals.reverse_var >= 0 ? '+' : '' }}{{ hourly.data.totals.reverse_var }}%)
                                </span>
                            </div>
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
                                        <div v-if="row.prev_calls !== null" class="text-xs text-gray-500">
                                            {{ formatNumber(row.prev_calls) }}
                                            <span v-if="row.calls_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.calls_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.calls_var >= 0 ? '+' : '' }}{{ row.calls_var }}%)
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-1 text-right text-sm">
                                        <div class="font-bold text-gray-900">{{ formatCurrency(row.reverse) }} €</div>
                                        <div v-if="row.prev_reverse !== null" class="text-xs text-gray-500">
                                            {{ formatCurrency(row.prev_reverse) }} €
                                            <span v-if="row.reverse_var !== null && !(isToday(hourly.data.date) && isFutureHour(row.hour, hourly.data.current_hour))" :class="row.reverse_var >= 0 ? 'text-green-600' : 'text-red-600'">
                                                ({{ row.reverse_var >= 0 ? '+' : '' }}{{ row.reverse_var }}%)
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
        </div>
    </div>
</template>
