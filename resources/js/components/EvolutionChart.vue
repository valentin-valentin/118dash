<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps({
    currentData: {
        type: Array,
        default: () => [],
    },
    previousData: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
})

const chartKey = ref(0)
const isReady = ref(false)

watch(() => [props.currentData, props.previousData, props.loading], () => {
    isReady.value = false
    nextTick(() => {
        chartKey.value++
        isReady.value = true
    })
}, { immediate: true })

const chartOptions = computed(() => ({
    chart: {
        type: 'line',
        height: 450,
        stacked: false,
        toolbar: {
            show: true,
            tools: {
                download: true,
                zoom: true,
                pan: true,
                reset: true,
            },
        },
        zoom: {
            enabled: true,
            type: 'x',
        },
    },
    colors: [
        '#1e40af', // CA actuel (bleu foncé)
        '#16a34a', // Bénéfice actuel (vert)
        '#7c3aed', // Appels (violet)
        '#ea580c', // Durée totale (orange)
        '#dc2626', // Durée moy (rouge)
        '#93c5fd', // CA précédent (bleu clair)
        '#86efac', // Bénéfice précédent (vert clair)
    ],
    dataLabels: {
        enabled: false,
    },
    stroke: {
        width: [0, 0, 3, 3, 3, 2, 2],
        curve: 'smooth',
        dashArray: [0, 0, 0, 0, 0, 5, 5],
    },
    plotOptions: {
        bar: {
            columnWidth: '50%',
        },
    },
    xaxis: {
        type: 'category',
        categories: props.currentData.map(d => {
            const date = new Date(d.date)
            return `${date.getDate()}/${date.getMonth() + 1}`
        }),
        title: {
            text: 'Jour du mois',
        },
        labels: {
            rotate: -45,
        },
    },
    yaxis: [
        // Axe 1 (gauche) : CA / Bénéfice (€)
        {
            seriesName: 'CA (actuel)',
            title: {
                text: 'CA / Bénéfice (€)',
                style: {
                    color: '#1e40af',
                },
            },
            labels: {
                formatter: (val) => val ? val.toFixed(0) + ' €' : '0 €',
                style: {
                    colors: '#1e40af',
                },
            },
        },
        // Axe 2 (masqué pour bénéfice, utilise axe 1)
        {
            seriesName: 'CA (actuel)',
            show: false,
        },
        // Axe 3 (droite 1) : Appels / Durée totale
        {
            opposite: true,
            seriesName: 'Appels (actuel)',
            title: {
                text: 'Appels / Durée totale (h)',
                style: {
                    color: '#7c3aed',
                },
            },
            labels: {
                formatter: (val) => val ? val.toFixed(0) : '0',
                style: {
                    colors: '#7c3aed',
                },
            },
        },
        // Axe 4 (masqué pour durée totale, utilise axe 3)
        {
            opposite: true,
            seriesName: 'Appels (actuel)',
            show: false,
        },
        // Axe 5 (droite 2) : Durée moyenne
        {
            opposite: true,
            seriesName: 'Durée moy. (actuel)',
            title: {
                text: 'Durée moy. (min)',
                style: {
                    color: '#dc2626',
                },
            },
            labels: {
                formatter: (val) => val ? val.toFixed(1) : '0',
                style: {
                    colors: '#dc2626',
                },
            },
            axisBorder: {
                show: true,
                color: '#dc2626',
            },
        },
        // Axes 6 et 7 (masqués pour CA/Bénéfice précédent, utilisent axe 1)
        {
            seriesName: 'CA (actuel)',
            show: false,
        },
        {
            seriesName: 'CA (actuel)',
            show: false,
        },
    ],
    tooltip: {
        shared: true,
        intersect: false,
        x: {
            format: 'dd/MM',
        },
        y: {
            formatter: (val, { seriesIndex }) => {
                if (!val) return '0'
                // CA et Bénéfice (actuel + précédent)
                if (seriesIndex === 0 || seriesIndex === 1 || seriesIndex === 5 || seriesIndex === 6) {
                    return val.toFixed(2) + ' €'
                }
                // Appels
                if (seriesIndex === 2) {
                    return val.toFixed(0) + ' appels'
                }
                // Durée totale
                if (seriesIndex === 3) {
                    return val.toFixed(1) + ' h'
                }
                // Durée moyenne
                if (seriesIndex === 4) {
                    return val.toFixed(1) + ' min'
                }
                return val
            },
        },
    },
    legend: {
        position: 'top',
        horizontalAlign: 'center',
        floating: false,
        fontSize: '11px',
        markers: {
            width: 10,
            height: 10,
        },
    },
    grid: {
        borderColor: '#e5e7eb',
    },
}))

const series = computed(() => {
    if (!props.currentData || props.currentData.length === 0) return []

    // Map des données précédentes par jour du mois
    const previousByDay = {}
    if (props.previousData) {
        props.previousData.forEach(d => {
            const date = new Date(d.date)
            const day = date.getDate()
            previousByDay[day] = d
        })
    }

    return [
        // Barres - Mois actuel
        {
            name: 'CA (actuel)',
            type: 'column',
            data: props.currentData.map(d => d.ca),
        },
        {
            name: 'Bénéfice (actuel)',
            type: 'column',
            data: props.currentData.map(d => d.benefice),
        },
        // Lignes - Mois actuel
        {
            name: 'Appels (actuel)',
            type: 'line',
            data: props.currentData.map(d => d.calls),
        },
        {
            name: 'Durée totale (actuel)',
            type: 'line',
            data: props.currentData.map(d => d.total_duration_hours),
        },
        {
            name: 'Durée moy. (actuel)',
            type: 'line',
            data: props.currentData.map(d => d.avg_duration_minutes),
        },
        // Lignes pointillées - Mois précédent
        {
            name: 'CA (mois préc.)',
            type: 'line',
            data: props.currentData.map((d) => {
                const day = new Date(d.date).getDate()
                return previousByDay[day]?.ca || null
            }),
        },
        {
            name: 'Bénéfice (mois préc.)',
            type: 'line',
            data: props.currentData.map((d) => {
                const day = new Date(d.date).getDate()
                return previousByDay[day]?.benefice || null
            }),
        },
    ]
})
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-900">
            Évolution (mois en cours vs mois précédent)
        </h3>

        <div v-if="loading" class="flex h-[450px] items-center justify-center">
            <div class="text-gray-500">Chargement...</div>
        </div>

        <div v-else-if="!currentData || currentData.length === 0" class="flex h-[450px] items-center justify-center">
            <div class="text-gray-400">Aucune donnée disponible</div>
        </div>

        <div v-else-if="isReady" :key="chartKey">
            <VueApexCharts
                type="line"
                height="450"
                :options="chartOptions"
                :series="series"
            />
        </div>
    </div>
</template>
