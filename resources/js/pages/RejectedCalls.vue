<script setup>
import { Head } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog'
import { SidebarTrigger } from '@/components/ui/sidebar'
import { useApi } from '@/composables/useApi'
import { useFilterOptions } from '@/composables/useFilterOptions'
import { useFilters } from '@/composables/useFilters'
import AppLayout from '@/layouts/AppLayout.vue'

// ─── Raisons de rejet ─────────────────────────────────────────────────────────
const REASONS = [
    { key: 'expired_lt_5', short: '< 5 mn', long: 'Numéro expiré il y a moins de 5 mn' },
    { key: 'expired_5_10', short: '5-10 mn', long: 'Numéro expiré entre 5 et 10 mn' },
    { key: 'expired_10_15', short: '10-15 mn', long: 'Numéro expiré entre 10 et 15 mn' },
    { key: 'expired_15_20', short: '15-20 mn', long: 'Numéro expiré entre 15 et 20 mn' },
    { key: 'expired_20_30', short: '20-30 mn', long: 'Numéro expiré entre 20 et 30 mn' },
    { key: 'expired_gt_30', short: '> 30 mn', long: 'Numéro expiré il y a plus de 30 mn' },
    { key: 'not_assigned_today', short: 'Pas assigné auj.', long: "Numéro pas assigné aujourd'hui" },
    { key: 'active_assignment', short: 'Anomalie', long: "Numéro assigné au moment de l'appel (anomalie)" },
]

const reasonLabels = Object.fromEntries(REASONS.map(r => [r.key, r.long]))

// ─── Filter Options ───────────────────────────────────────────────────────────
const {
    options: filterOptions,
    isLoading: isLoadingOptions,
    load: loadFilterOptions,
} = useFilterOptions('/data/dashboard/filter-options', {
    providers: [],
    companies: [],
    sources: [],
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

// ─── Data ─────────────────────────────────────────────────────────────────────
const daily = useApi('/data/rejected-calls/daily-breakdown')
const details = useApi('/data/rejected-calls/details')

const { filters, reset } = useFilters(
    {
        month: monthOptions.value[0].value,
        source_id: [],
        company_id: [],
        provider_id: [],
    },
    (f) => {
        daily.load(f)
    },
)

const hasFilters = computed(() =>
    (filters.month !== monthOptions.value[0].value) ||
    (Array.isArray(filters.source_id) && filters.source_id.length > 0) ||
    (Array.isArray(filters.company_id) && filters.company_id.length > 0) ||
    (Array.isArray(filters.provider_id) && filters.provider_id.length > 0)
)

// La colonne Anomalie ne s'affiche que s'il y en a
const visibleReasons = computed(() =>
    REASONS.filter(r => r.key !== 'active_assignment' || (daily.data?.totals?.active_assignment ?? 0) > 0)
)

// ─── Détail d'un jour ─────────────────────────────────────────────────────────
const showDetailsModal = ref(false)
const selectedDate = ref(null)

function selectDay(row) {
    if (row.total === 0) return
    selectedDate.value = row.date
    showDetailsModal.value = true
    details.load({ ...filters, date: row.date })
}

function closeDetailsModal() {
    showDetailsModal.value = false
    selectedDate.value = null
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function isSunday(dateString) {
    return new Date(dateString).getDay() === 0
}

function formatNumber(value) {
    if (value === null || value === undefined) return '-'
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)
    return formatted.replace(/,/g, ' ')
}

function percentOfTotal(count) {
    const total = daily.data?.totals?.total
    if (!total || !count) return null
    return ((count / total) * 100).toFixed(1)
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    loadFilterOptions()
    daily.load(filters)
})
</script>

<template>
    <Head title="Appels rejetés" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <div class="flex items-center gap-3">
                <SidebarTrigger class="lg:hidden" />
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Appels rejetés</h1>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Appels arrivés sur un numéro désactivé (8h-20h, hors dimanche, retries opérateurs dédupliqués).
                        Le délai est mesuré entre la fin réelle de la dernière assignation du numéro et l'appel.
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <FilterBar
                :has-active-filters="hasFilters"
                :is-loading="isLoadingOptions"
                @reset="reset"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <FilterSelect
                        v-model="filters.month"
                        :options="monthOptions"
                        placeholder="Mois"
                        :searchable="false"
                    />
                    <FilterSelect
                        v-model="filters.source_id"
                        :options="filterOptions.sources"
                        placeholder="Sources"
                        multiple
                    />
                    <FilterSelect
                        v-model="filters.company_id"
                        :options="filterOptions.companies"
                        placeholder="Companies"
                        multiple
                    />
                    <FilterSelect
                        v-model="filters.provider_id"
                        :options="filterOptions.providers"
                        placeholder="Providers"
                        multiple
                    />
                </div>
            </FilterBar>

            <!-- Tableau quotidien -->
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="whitespace-nowrap px-3 py-1.5 text-left text-xs font-medium uppercase tracking-wide text-gray-400">Date</th>
                                <th class="whitespace-nowrap px-3 py-1.5 text-right text-xs font-medium uppercase tracking-wide text-gray-400">Rejetés</th>
                                <th
                                    v-for="reason in visibleReasons"
                                    :key="reason.key"
                                    class="whitespace-nowrap px-3 py-1.5 text-right text-xs font-medium uppercase tracking-wide text-gray-400"
                                    :title="reason.long"
                                >{{ reason.short }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            <!-- Total en haut -->
                            <tr v-if="daily.data?.totals" class="border-t border-b border-gray-200 bg-gray-50">
                                <td class="px-3 py-2 text-left font-bold text-gray-900">Total</td>
                                <td class="px-3 py-2 text-right">
                                    <div class="font-bold text-gray-900">{{ formatNumber(daily.data.totals.total) }}</div>
                                </td>
                                <td
                                    v-for="reason in visibleReasons"
                                    :key="reason.key"
                                    class="px-3 py-2 text-right"
                                >
                                    <div class="font-semibold text-gray-900">{{ formatNumber(daily.data.totals[reason.key]) }}</div>
                                    <div v-if="percentOfTotal(daily.data.totals[reason.key]) !== null" class="text-xs text-gray-500">
                                        {{ percentOfTotal(daily.data.totals[reason.key]) }}%
                                    </div>
                                </td>
                            </tr>

                            <!-- Skeleton loading -->
                            <template v-if="daily.loading">
                                <tr v-for="n in 8" :key="n" class="border-b border-gray-50">
                                    <td v-for="col in (visibleReasons.length + 2)" :key="col" class="px-3 py-2">
                                        <div class="h-4 animate-pulse rounded bg-gray-50" style="width: 65%" />
                                    </td>
                                </tr>
                            </template>

                            <!-- Lignes -->
                            <template v-else-if="daily.data?.items && daily.data.items.length > 0">
                                <tr
                                    v-for="row in daily.data.items"
                                    :key="row.date"
                                    :class="[
                                        'border-b border-gray-50 transition-colors',
                                        isSunday(row.date)
                                            ? 'bg-gray-50'
                                            : row.total > 0
                                            ? 'hover:bg-gray-100/80 cursor-pointer'
                                            : ''
                                    ]"
                                    @click="!isSunday(row.date) && selectDay(row)"
                                >
                                    <td class="px-3 py-1.5">
                                        <div :class="isSunday(row.date) ? 'font-medium text-gray-400' : 'font-medium text-gray-900'">{{ row.date_label }}</div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        <div :class="isSunday(row.date) ? 'text-gray-400' : 'font-bold text-gray-900'">{{ formatNumber(row.total) }}</div>
                                    </td>
                                    <td
                                        v-for="reason in visibleReasons"
                                        :key="reason.key"
                                        class="px-3 py-1.5 text-right"
                                    >
                                        <div :class="row[reason.key] === 0 || isSunday(row.date) ? 'text-gray-300' : 'text-gray-900'">
                                            {{ formatNumber(row[reason.key]) }}
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <!-- Vide -->
                            <template v-else>
                                <tr>
                                    <td :colspan="visibleReasons.length + 2" class="px-3 py-8 text-center text-sm text-gray-300">
                                        Aucun appel rejeté
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal détail d'un jour -->
            <Dialog :open="showDetailsModal" @update:open="closeDetailsModal">
                <DialogContent class="!w-[95vw] md:!w-[85vw] lg:!w-[75vw] !max-w-4xl max-h-[90vh] overflow-y-auto !p-0">
                    <div class="px-6 pt-4 pb-2">
                        <DialogTitle class="text-base font-bold">
                            Rejets du {{ details.data?.date_label || '' }}
                        </DialogTitle>
                        <p class="text-xs text-gray-500">
                            {{ details.data?.items?.length ?? 0 }} appel{{ (details.data?.items?.length ?? 0) !== 1 ? 's' : '' }} rejeté{{ (details.data?.items?.length ?? 0) !== 1 ? 's' : '' }}
                        </p>
                    </div>

                    <!-- Loader -->
                    <div v-if="details.loading" class="flex items-center justify-center py-10">
                        <svg class="h-7 w-7 animate-spin text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <!-- Table détail -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 border-t border-gray-200">
                                <tr>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Heure</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Appelant</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Numéro appelé</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Source</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Company</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Raison</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Délai</th>
                                    <th class="whitespace-nowrap px-4 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Retries</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, i) in details.data?.items || []"
                                    :key="i"
                                    class="border-b border-gray-50"
                                >
                                    <td class="px-4 py-1 font-mono text-xs text-gray-700">{{ row.time }}</td>
                                    <td class="px-4 py-1 font-mono text-xs text-gray-700">{{ row.from }}</td>
                                    <td class="px-4 py-1 font-mono text-xs text-gray-900">{{ row.phonenumber || row.to }}</td>
                                    <td class="px-4 py-1 text-xs text-gray-700">{{ row.source_name || '-' }}</td>
                                    <td class="px-4 py-1 text-xs text-gray-700">{{ row.company_name || '-' }}</td>
                                    <td class="px-4 py-1 text-xs" :class="row.reason === 'active_assignment' ? 'font-semibold text-red-700' : 'text-gray-700'">
                                        {{ reasonLabels[row.reason] || row.reason }}
                                    </td>
                                    <td class="px-4 py-1 text-right text-xs tabular-nums text-gray-700">
                                        {{ row.delay_minutes !== null ? row.delay_minutes + ' mn' : '-' }}
                                    </td>
                                    <td class="px-4 py-1 text-right text-xs tabular-nums" :class="row.retries > 0 ? 'text-gray-700' : 'text-gray-300'">
                                        {{ row.retries }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
