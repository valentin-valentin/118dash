<script setup>
import { computed, ref, watch } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

/**
 * Courbes d'un seul indicateur (un seul axe), une courbe par série.
 *
 * @prop {Array}    categories  Libellés de l'axe X
 * @prop {Array}    titles      Titres du tooltip (libellé complet de chaque point), facultatif
 * @prop {Array}    series      [{ name, color, data: [number|null] }]
 * @prop {Function} format      Formate une valeur (axe + tooltip)
 * @prop {Number}   height
 * @prop {Boolean}  legend      Légende Apex (désactivée quand la légende est faite à côté en HTML)
 * @prop {String}   fontFamily  Police des axes (par défaut celle de la page)
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    titles: { type: Array, default: null },
    series: { type: Array, default: () => [] },
    format: { type: Function, default: (v) => v },
    height: { type: Number, default: 300 },
    legend: { type: Boolean, default: true },
    fontFamily: { type: String, default: 'inherit' },
})

// ApexCharts perd les formateurs de l'axe lors d'une mise à jour : on recrée le graphique
const renderKey = ref(0)
watch(() => [props.categories, props.series, props.format, props.titles], () => renderKey.value++)

const options = computed(() => ({
    chart: {
        type: 'line',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: false },
        fontFamily: props.fontFamily,
    },
    colors: props.series.map((s) => s.color),
    stroke: { width: 2, curve: 'straight' },
    markers: {
        size: props.categories.length <= 31 ? 3 : 0,
        strokeWidth: 2,
        strokeColors: '#ffffff',
        hover: { size: 5 },
    },
    dataLabels: { enabled: false },
    grid: {
        borderColor: '#f3f4f6',
        strokeDashArray: 0,
        xaxis: { lines: { show: false } },
        padding: { left: 8, right: 8 },
    },
    xaxis: {
        categories: props.categories,
        tickAmount: Math.min(props.categories.length, 12),
        labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: '#6b7280', fontSize: '11px' } },
        axisBorder: { color: '#e5e7eb' },
        axisTicks: { show: false },
        tooltip: { enabled: false },
    },
    // Un seul axe : chaque série s'y rattache (seriesName), seul le premier est affiché
    yaxis: (props.series.length ? props.series : [{}]).map((_, i) => ({
        seriesName: props.series[0]?.name,
        show: i === 0,
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (v) => (v === null || v === undefined ? '' : props.format(v)),
            style: { colors: '#6b7280', fontSize: '11px' },
        },
    })),
    legend: {
        show: props.legend && props.series.length > 1,
        position: 'top',
        horizontalAlign: 'left',
        fontSize: '12px',
        labels: { colors: '#374151' },
        markers: { size: 5, shape: 'circle' },
        itemMargin: { horizontal: 10 },
    },
    tooltip: {
        shared: true,
        intersect: false,
        x: { formatter: (v, { dataPointIndex }) => props.titles?.[dataPointIndex] ?? v },
        y: { formatter: (v) => (v === null || v === undefined ? '—' : props.format(v)) },
    },
}))
</script>

<template>
    <VueApexCharts :key="renderKey" type="line" :height="height" :options="options" :series="series" />
</template>
