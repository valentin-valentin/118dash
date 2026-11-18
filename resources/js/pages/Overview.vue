<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import AudioQualityPanel from '@/components/AudioQualityPanel.vue'
import BrandRequestsNotice from '@/components/BrandRequestsNotice.vue'
import BrandTreemap from '@/components/BrandTreemap.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { useFilterOptions } from '@/composables/useFilterOptions'
import { QUALITY_SCALE, formatQuality, qualityClass } from '@/lib/quality'

/**
 * Dashboard : appels de 10 s et plus seulement, sans les montants payout / reverse (dashboard complet : /dashboard-full).
 *   Jour par jour : appels, qualité audio, attente avant décroché, perdus, durée affichée moyenne, revenu par appel
 *   (durée affichée moyenne × 0,45 € la minute ; durée affichée = durée × 0,85 en novembre 2026, × 0,75 les autres mois) ; comparés à J-7, le total au mois précédent à jour égal
 *   Qualité audio du mois, répartition par marque (nombre d'appels)
 * Mêmes filtres que le dashboard complet, sans les cases à cocher (colonnes toujours affichées, 10 s et plus toujours).
 */

// ─── Filtres ──────────────────────────────────────────────────────────────────
const { options: filterOptions, isLoading: isLoadingOptions, load: loadFilterOptions } = useFilterOptions('/data/dashboard/filter-options', {
    brands: [],
    agents: [],
    callcenters: [],
    carriers: [],
    providers: [],
    companies: [],
    sources: [],
})
// Mois : le mois courant et les 11 précédents
const monthOptions = computed(() =>
    Array.from({ length: 12 }, (_, i) => {
        const now = new Date()
        const date = new Date(now.getFullYear(), now.getMonth() - i, 1)
        const name = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
        return { value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`, label: name.charAt(0).toUpperCase() + name.slice(1) }
    }),
)

const daily = useApi('/data/overview/daily')
const audioQuality = useApi('/data/audio-quality')
const brands = useApi('/data/overview/brands')
// Qualité audio : appels de 10 s et plus, comme le reste de la page
const qualityFilters = computed(() => ({ ...filters, min_duration: 'true' }))

const { filters, reset } = useFilters(
    {
        month: monthOptions.value[0].value,
        source_id: [],
        provider_id: [],
        company_id: [],
        brand_name: [],
        carrier: [],
        callcenter_id: [],
        agent_name: [],
        // Plateforme : '' = toutes, voxnode, selfhosted (notre serveur)
        platform: '',
    },
    loadAll,
)
function loadAll(f) {
    daily.load(f)
    audioQuality.load({ ...f, min_duration: 'true' })
    brands.load(f)
}
const hasFilters = computed(
    () => filters.month !== monthOptions.value[0].value || !!filters.platform || ['source_id', 'provider_id', 'company_id', 'brand_name', 'carrier', 'callcenter_id', 'agent_name'].some((k) => filters[k]?.length),
)
onMounted(() => {
    loadFilterOptions()
    loadAll({ ...filters })
})

// ─── Tableau jour par jour ────────────────────────────────────────────────────
// compare : valeur de J-7 (du mois précédent pour le total) et écart ; worseUp : une hausse est en rouge (attente)
const COLUMNS = [
    { key: 'date', label: 'Date', align: 'text-left' },
    { key: 'calls', label: 'Appels', format: (v) => formatNumber(v), compare: true },
    { key: 'quality_mos', label: 'Qualité audio', scale: true },
    { key: 'avg_wait', label: 'Attente', format: (v) => formatDuration(v), compare: true, worseUp: true },
    { key: 'lost_pct', label: 'Perdus' },
    // Durée affichée moyenne : le multiplicateur du mois (× 0,85 en novembre 2026, × 0,75 sinon) est appliqué par le serveur
    { key: 'displayed_avg', label: 'Durée moy.', format: (v) => formatDuration(v), compare: true },
    { key: 'revenue', label: 'Revenu par appel', format: (v) => (v == null ? '-' : `${formatCurrency(v)} €`), compare: true },
]
const SCALE_LINES = QUALITY_SCALE.split('\n')

const sortKey = ref('date')
const sortDir = ref('desc')
function toggleSort(key) {
    if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    else {
        sortKey.value = key
        sortDir.value = 'desc'
    }
}
const sortedRows = computed(() => {
    const rows = [...(daily.data?.items ?? [])]
    return rows.sort((a, b) => {
        const [x, y] = [a[sortKey.value], b[sortKey.value]]
        // Jours sans valeur (ex. qualité non mesurée) toujours en bas
        if (x === null || x === undefined) return 1
        if (y === null || y === undefined) return -1
        const order = sortKey.value === 'date' ? String(x).localeCompare(String(y)) : x - y
        return sortDir.value === 'asc' ? order : -order
    })
})

// ─── Formats (mêmes que le dashboard complet) ─────────────────────────────────
const isSunday = (date) => new Date(date).getDay() === 0
function formatDuration(seconds) {
    if (!seconds) return '-'
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
}
const formatNumber = (v) => (v == null ? '-' : new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '))
const formatCurrency = (v) => (v == null ? '-' : new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v).replace(/,/g, ' '))
const lostRate = (r) => (r?.calls ? `${Number(r.lost_pct).toFixed(1).replace('.', ',')} %` : '—')
const measuredText = (n, degraded) => `${formatNumber(n)} mesuré${n > 1 ? 's' : ''} · ${formatNumber(degraded)} dégradé${degraded > 1 ? 's' : ''}`
const variationClass = (col, v) => ((v >= 0) !== !!col.worseUp ? 'text-green-600' : 'text-red-600')
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard' }]">
                <!-- Navigation : le dashboard d'avant, avec les montants, les companies et le détail heure par heure -->
                <template #actions>
                    <Button variant="outline" as-child><Link href="/dashboard-full">Dashboard complet</Link></Button>
                </template>
            </PageHeader>

            <BrandRequestsNotice />

            <!-- Filtres : ceux du dashboard complet, sans les cases à cocher -->
            <FilterBar :has-active-filters="hasFilters" :is-loading="isLoadingOptions" @reset="reset">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-8">
                    <!-- Mois : deux colonnes, pour que « Septembre 2026 » tienne en entier -->
                    <FilterSelect v-model="filters.month" class="lg:col-span-2" :options="monthOptions" placeholder="Mois" :searchable="false" />
                    <FilterSelect v-model="filters.source_id" :options="filterOptions.sources" placeholder="Sources" multiple />
                    <FilterSelect v-model="filters.provider_id" :options="filterOptions.providers" placeholder="Providers" multiple />
                    <FilterSelect v-model="filters.company_id" :options="filterOptions.companies" placeholder="Companies" multiple />
                    <FilterSelect v-model="filters.brand_name" :options="filterOptions.brands" placeholder="Marques" searchable multiple />
                    <FilterSelect v-model="filters.carrier" :options="filterOptions.carriers" placeholder="Opérateurs" multiple />
                    <FilterSelect v-model="filters.callcenter_id" :options="filterOptions.callcenters" placeholder="Call Centers" multiple />
                    <FilterSelect v-model="filters.agent_name" :options="filterOptions.agents" placeholder="Agents" searchable multiple />
                    <FilterSelect
                        v-model="filters.platform"
                        :options="[
                            { value: 'voxnode', label: 'Voxnode' },
                            { value: 'selfhosted', label: 'Notre serveur' },
                        ]"
                        placeholder="Plateforme"
                        :searchable="false"
                    />
                </div>
            </FilterBar>

            <!-- Jour par jour -->
            <section>
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th
                                        v-for="col in COLUMNS"
                                        :key="col.key"
                                        class="cursor-pointer px-1.5 py-1.5 text-xs font-medium tracking-wide text-gray-400 uppercase select-none hover:text-gray-700"
                                        :class="col.align || 'text-right'"
                                        @click="toggleSort(col.key)"
                                    >
                                        <Tooltip v-if="col.scale">
                                            <TooltipTrigger as-child>
                                                <span class="cursor-help underline decoration-dotted underline-offset-2" @click.stop>{{ col.label }}</span>
                                            </TooltipTrigger>
                                            <TooltipContent class="max-w-xs text-left tracking-normal normal-case">
                                                <p v-for="line in SCALE_LINES" :key="line" class="leading-relaxed">{{ line }}</p>
                                            </TooltipContent>
                                        </Tooltip>
                                        <template v-else>{{ col.label }}</template>
                                        <span v-if="sortKey === col.key">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                                        <span v-else class="opacity-20">⇅</span>
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <!-- Total du mois, comparé au mois précédent à jour égal -->
                                <tr v-if="daily.data?.totals" class="border-y border-gray-200 bg-gray-50">
                                    <td class="px-1.5 py-2 text-left font-bold text-gray-900">Total</td>
                                    <td v-for="col in COLUMNS.slice(1)" :key="col.key" class="px-1.5 py-2 text-right text-sm">
                                        <template v-if="col.compare">
                                            <div class="font-semibold text-gray-900">{{ col.format(daily.data.totals[col.key]) }}</div>
                                            <div v-if="daily.data.totals[`prev_${col.key}`] != null" class="text-xs text-gray-500">
                                                {{ col.format(daily.data.totals[`prev_${col.key}`]) }}
                                                <span v-if="daily.data.totals[`${col.key}_var`] != null" :class="variationClass(col, daily.data.totals[`${col.key}_var`])">
                                                    ({{ daily.data.totals[`${col.key}_var`] >= 0 ? '+' : '' }}{{ daily.data.totals[`${col.key}_var`] }}%)
                                                </span>
                                            </div>
                                        </template>
                                        <template v-else-if="col.key === 'quality_mos'">
                                            <div class="font-semibold" :class="qualityClass(daily.data.totals.quality_mos) || 'text-gray-900'">{{ formatQuality(daily.data.totals.quality_mos) }}</div>
                                            <div v-if="daily.data.totals.quality_calls" class="text-xs text-gray-500">{{ measuredText(daily.data.totals.quality_calls, daily.data.totals.quality_degraded) }}</div>
                                        </template>
                                        <template v-else>
                                            <div class="font-semibold text-gray-900">{{ lostRate(daily.data.totals) }}</div>
                                            <div class="text-xs text-gray-500">{{ formatNumber(daily.data.totals.lost_calls) }} appels</div>
                                        </template>
                                    </td>
                                </tr>

                                <template v-if="daily.loading && !daily.data">
                                    <tr v-for="n in 8" :key="n" class="border-b border-gray-50">
                                        <td v-for="col in COLUMNS" :key="col.key" class="px-1.5 py-2"><div class="h-4 w-2/3 animate-pulse rounded bg-gray-50" /></td>
                                    </tr>
                                </template>

                                <tr
                                    v-for="row in sortedRows"
                                    :key="row.date"
                                    class="border-b border-gray-50"
                                    :class="isSunday(row.date) ? 'bg-gray-50' : 'hover:bg-gray-100/80'"
                                >
                                    <td class="px-1.5 py-1.5 text-sm">
                                        <div class="font-medium" :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ row.date_label }}</div>
                                        <div class="text-xs" :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-500'">{{ row.comparison_label }}</div>
                                    </td>
                                    <td v-for="col in COLUMNS.slice(1)" :key="col.key" class="px-1.5 py-1.5 text-right text-sm">
                                        <template v-if="col.compare">
                                            <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ col.format(row[col.key]) }}</div>
                                            <div v-if="row[`prev_${col.key}`] != null && !isSunday(row.date)" class="text-xs text-gray-500">
                                                {{ col.format(row[`prev_${col.key}`]) }}
                                                <span v-if="row[`${col.key}_var`] != null" :class="variationClass(col, row[`${col.key}_var`])">
                                                    ({{ row[`${col.key}_var`] >= 0 ? '+' : '' }}{{ row[`${col.key}_var`] }}%)
                                                </span>
                                            </div>
                                        </template>
                                        <template v-else-if="col.key === 'quality_mos'">
                                            <div :class="isSunday(row.date) ? 'text-gray-400' : qualityClass(row.quality_mos) || 'text-gray-900'">{{ formatQuality(row.quality_mos) }}</div>
                                            <div v-if="row.quality_calls && !isSunday(row.date)" class="text-xs text-gray-500">{{ measuredText(row.quality_calls, row.quality_degraded) }}</div>
                                        </template>
                                        <template v-else>
                                            <div :class="isSunday(row.date) ? 'text-gray-400' : 'text-gray-900'">{{ lostRate(row) }}</div>
                                            <div v-if="row.calls && !isSunday(row.date)" class="text-xs text-gray-500">{{ formatNumber(row.lost_calls) }} appels</div>
                                        </template>
                                    </td>
                                </tr>

                                <tr v-if="!daily.loading && !sortedRows.length">
                                    <td :colspan="COLUMNS.length" class="px-1.5 py-8 text-center text-sm text-gray-300">Aucune donnée</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Qualité audio du mois : appels de 10 s et plus, mêmes filtres que le tableau -->
            <AudioQualityPanel :report="audioQuality.data" :loading="audioQuality.loading" :filters="qualityFilters" />

            <!-- Répartition par marque : nombre d'appels (taille et infobulle) -->
            <BrandTreemap :brands="brands.data?.brands || []" :total-count="brands.data?.total_count || 0" :loading="brands.loading" by-calls />
        </div>
    </AppLayout>
</template>
