<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps({
    brands: {
        type: Array,
        default: () => [],
    },
    totalCount: {
        type: Number,
        default: 0,
    },
    loading: {
        type: Boolean,
        default: false,
    },
})

const chartKey = ref(0)
const isReady = ref(false)
const chartRef = ref(null)

// Forcer le re-render quand les données changent
watch(() => [props.brands, props.loading], () => {
    isReady.value = false
    nextTick(() => {
        chartKey.value++
        isReady.value = true
        // Désactiver les clics après le render
        nextTick(() => {
            disableChartInteractions()
        })
    })
}, { immediate: true })

// Fonction pour désactiver complètement les interactions
const disableChartInteractions = () => {
    nextTick(() => {
        const svg = document.querySelector('.treemap-hover svg')
        if (svg) {
            svg.addEventListener('click', (e) => {
                e.preventDefault()
                e.stopPropagation()
                return false
            }, true)
            svg.addEventListener('mousedown', (e) => {
                e.preventDefault()
                e.stopPropagation()
                return false
            }, true)
        }
    })
}

const chartOptions = computed(() => ({
    chart: {
        type: 'treemap',
        height: 500,
        toolbar: {
            show: false,
        },
        animations: {
            enabled: false,
        },
        selection: {
            enabled: false,
        },
        zoom: {
            enabled: false,
        },
        events: {
            dataPointSelection: () => false,
            click: () => false,
            mouseMove: () => true,
        },
    },
    plotOptions: {
        treemap: {
            distributed: true,
            enableShades: true,
            shadeIntensity: 0.1,
            enableSelection: false,
            colorScale: {
                ranges: [{
                    from: 0,
                    to: 999999999,
                    color: '#f3f4f6'
                }]
            }
        },
    },
    states: {
        normal: {
            filter: {
                type: 'none'
            }
        },
        hover: {
            filter: {
                type: 'none'
            }
        },
        active: {
            filter: {
                type: 'none'
            }
        }
    },
    dataLabels: {
        enabled: true,
        style: {
            fontSize: '11px',
            fontWeight: '500',
            colors: ['#000000'],
        },
        formatter: (text, op) => {
            return text
        },
    },
    fill: {
        opacity: 1
    },
    tooltip: {
        custom: function({ seriesIndex, dataPointIndex, w }) {
            const brand = props.brands[dataPointIndex]
            if (!brand) return ''

            const formatNumber = (num) => {
                return new Intl.NumberFormat('fr-FR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(num)
            }

            const formatInteger = (num) => {
                return new Intl.NumberFormat('fr-FR').format(num)
            }

            const formatDuration = (seconds) => {
                const mins = Math.floor(seconds / 60)
                const secs = seconds % 60
                return `${mins}:${secs.toString().padStart(2, '0')}`
            }

            return `
                <div class="p-3 bg-white rounded shadow-lg border border-gray-200">
                    <div class="font-semibold text-gray-900 mb-2">${brand.name}</div>
                    <div class="space-y-1 text-xs text-gray-600">
                        <div>Bénéfice: <span class="font-medium">${formatNumber(brand.benefice)} €</span></div>
                        <div>CA: <span class="font-medium">${formatNumber(brand.ca || 0)} €</span></div>
                        <div>Reverse: <span class="font-medium">${formatNumber(brand.reverse || 0)} €</span></div>
                        <div class="border-t border-gray-200 my-1 pt-1">
                            <div>Appels: <span class="font-medium">${formatInteger(brand.calls)}</span></div>
                            <div>Durée totale: <span class="font-medium">${brand.total_duration_formatted || '0h00m'}</span></div>
                            <div>Durée moy: <span class="font-medium">${formatDuration(brand.avg_duration || 0)}</span></div>
                        </div>
                    </div>
                </div>
            `
        },
    },
    legend: {
        show: false,
    },
}))

const series = computed(() => {
    if (!props.brands || props.brands.length === 0) return []

    return [
        {
            data: props.brands.map(brand => ({
                x: brand.name,
                y: Math.abs(brand.benefice) || 1, // ApexCharts treemap needs positive values, minimum 1 for visibility
            })),
        },
    ]
})
</script>

<style scoped>
.treemap-hover :deep(.apexcharts-treemap-rect) {
    cursor: default !important;
    fill: #f3f4f6 !important;
}
.treemap-hover :deep(.apexcharts-treemap-rect:hover) {
    fill: #e8eaed !important;
}
.treemap-hover :deep(.apexcharts-treemap-rect:active),
.treemap-hover :deep(.apexcharts-treemap-rect:focus),
.treemap-hover :deep(.apexcharts-treemap-rect:focus-visible),
.treemap-hover :deep(.apexcharts-treemap-rect.apexcharts-active),
.treemap-hover :deep(.apexcharts-treemap-rect.apexcharts-selected) {
    fill: #f3f4f6 !important;
    stroke: none !important;
    outline: none !important;
    opacity: 1 !important;
}
.treemap-hover :deep(.apexcharts-series[selected]),
.treemap-hover :deep(.apexcharts-series.apexcharts-selected),
.treemap-hover :deep(.apexcharts-series.apexcharts-active) {
    opacity: 1 !important;
}
.treemap-hover :deep(.apexcharts-series path) {
    fill: #f3f4f6 !important;
}
.treemap-hover :deep(.apexcharts-series path:hover) {
    fill: #e8eaed !important;
}
.treemap-hover :deep(.apexcharts-series path:active),
.treemap-hover :deep(.apexcharts-series path:focus) {
    fill: #f3f4f6 !important;
}
.treemap-hover :deep(.apexcharts-data-labels) {
    pointer-events: none !important;
}
.treemap-hover :deep(svg),
.treemap-hover :deep(svg *) {
    user-select: none !important;
    -webkit-user-select: none !important;
    -webkit-tap-highlight-color: transparent !important;
    outline: none !important;
}
.treemap-hover :deep(svg *:focus),
.treemap-hover :deep(svg *:focus-visible) {
    outline: none !important;
}
</style>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-6 treemap-hover">
        <h3 class="-mb-3 text-lg font-semibold text-gray-900">
            Répartition par marques
            <span v-if="totalCount > 0" class="text-sm font-normal text-gray-500">
                ({{ totalCount }} marques)
            </span>
        </h3>

        <div v-if="loading" class="flex h-[500px] items-center justify-center">
            <div class="text-gray-500">Chargement...</div>
        </div>

        <div v-else-if="!brands || brands.length === 0" class="flex h-[500px] items-center justify-center">
            <div class="text-gray-400">Aucune donnée disponible</div>
        </div>

        <div v-else-if="isReady" :key="chartKey">
            <VueApexCharts
                type="treemap"
                height="500"
                :options="chartOptions"
                :series="series"
            />
        </div>
    </div>
</template>
