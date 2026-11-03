<script setup>
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { LoaderCircle, Pause } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatDateTime, formatDuration, formatPhone } from '@/lib/calls'

/**
 * Vérifier les transferts (page Marques) : marques dont les transferts durent anormalement peu.
 * Une seule mesure : la médiane de la durée réelle des transferts, sur 7 ou 30 jours (au moins 5 transferts mesurés).
 * Seules les marques sous le seuil sont listées, la plus basse en premier ; les 10 derniers transferts s'écoutent en un clic.
 * À l'ouverture, la page mesure d'abord les transferts pas encore mesurés (taille de chaque enregistrement).
 */
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')

const days = ref(7)
const threshold = ref(60)
const THRESHOLDS = [30, 60, 120]

const data = ref(null)
const loading = ref(false)
const error = ref(null)
const measure = reactive({ running: false, done: 0, total: 0 })

async function measureAll() {
    measure.running = true
    measure.done = 0
    measure.total = 0
    try {
        for (;;) {
            const res = await fetch(`/data/brand-checks/measure?days=${days.value}`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } })
            if (!res.ok) throw new Error(`Mesure des transferts : erreur HTTP ${res.status}`)
            const r = await res.json()
            measure.done += r.measured
            measure.total = measure.done + r.remaining
            if (!r.remaining || !r.measured) break
        }
    } finally {
        measure.running = false
    }
}

let requestId = 0
async function loadData() {
    const id = ++requestId
    const seconds = Number(threshold.value)
    if (!Number.isInteger(seconds) || seconds < 1) return
    const res = await fetch(`/data/brand-checks?days=${days.value}&threshold=${seconds}`, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
    const json = await res.json()
    if (id === requestId) data.value = json
}

async function load() {
    loading.value = true
    error.value = null
    try {
        await measureAll()
        await loadData()
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}
watch(days, load)
let thresholdTimer = null
watch(threshold, () => {
    clearTimeout(thresholdTimer)
    thresholdTimer = setTimeout(() => loadData().catch((e) => (error.value = e.message)), 400)
})
onMounted(load)

function resetFilters() {
    days.value = 7
    threshold.value = 60
}

// Écoute : un seul enregistrement à la fois
const audio = typeof Audio !== 'undefined' ? new Audio() : null
const playing = ref(null) // id de l'appel en cours de lecture
audio?.addEventListener('ended', () => (playing.value = null))
audio?.addEventListener('pause', () => (playing.value = null))
function play(transfer) {
    if (!audio || !transfer.record_transfer) return
    if (playing.value === transfer.id) {
        audio.pause()
        return
    }
    audio.src = transfer.record_transfer
    audio.play().then(() => (playing.value = transfer.id)).catch(() => (playing.value = null))
}
onUnmounted(() => audio?.pause())

async function markChecked(b) {
    const res = await fetch('/data/brand-checks/check', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ name: b.name, phonenumber: b.phonenumber, checked: true }),
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) {
        error.value = json.message ?? `Erreur HTTP ${res.status}`
        return
    }
    // Masquée jusqu'à ce que de nouveaux transferts restent sous le seuil
    data.value.brands = data.value.brands.filter((x) => x.key !== b.key)
}
</script>

<template>
    <Head title="Vérifier les transferts" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Vérifier les transferts' }]" />

            <FilterBar title="Filtres" :has-active-filters="days !== 7 || threshold !== 60" @reset="resetFilters">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <div class="flex items-center gap-1.5">
                        <button
                            v-for="d in [7, 30]"
                            :key="d"
                            type="button"
                            class="h-8 rounded-md px-3 text-xs font-medium"
                            :class="days === d ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            :disabled="loading"
                            @click="days = d"
                        >
                            {{ d }} derniers jours
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-gray-600">
                        Médiane sous
                        <Input v-model.number="threshold" type="number" min="1" max="3600" class="h-8 w-20 text-right tabular-nums" aria-label="Seuil en secondes" />
                        secondes
                        <button
                            v-for="t in THRESHOLDS"
                            :key="t"
                            type="button"
                            class="ml-1 h-8 rounded-md px-2.5 font-medium"
                            :class="threshold === t ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                            @click="threshold = t"
                        >
                            {{ t }} s
                        </button>
                    </div>
                </div>
            </FilterBar>

            <p class="text-sm text-gray-600">Marques dont les transferts durent anormalement peu : écoutez les derniers pour vérifier le numéro.</p>

            <div v-if="measure.running && measure.total" class="rounded-lg border border-gray-100 bg-white px-4 py-3 text-sm text-gray-700">
                Mesure de la durée des transferts : {{ measure.done.toLocaleString('fr-FR') }} sur {{ measure.total.toLocaleString('fr-FR') }}
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full bg-gray-900 transition-all" :style="{ width: `${(measure.done / measure.total) * 100}%` }" />
                </div>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="overflow-hidden rounded-lg border border-gray-100 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 text-left text-xs text-gray-400 uppercase">
                            <tr>
                                <th class="px-4 py-2 font-medium tracking-wide">Marque</th>
                                <th class="px-2 py-2 text-right font-medium tracking-wide">Médiane</th>
                                <th class="px-2 py-2 text-right font-medium tracking-wide">Transferts mesurés</th>
                                <th class="px-2 py-2 font-medium tracking-wide">10 derniers transferts</th>
                                <th class="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody :class="loading && data && 'opacity-50'">
                            <tr v-if="!data">
                                <td colspan="5" class="py-12 text-center text-gray-400"><LoaderCircle class="mx-auto size-5 animate-spin" /></td>
                            </tr>
                            <tr v-else-if="!data.brands.length">
                                <td colspan="5" class="py-12 text-center text-gray-500">Rien à vérifier</td>
                            </tr>
                            <tr v-for="b in data?.brands ?? []" :key="b.key" class="border-t border-gray-50 align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-900">{{ b.name }}</div>
                                    <div class="text-xs text-gray-500 tabular-nums">
                                        {{ formatPhone(b.phonenumber) }}
                                        <Link v-if="b.brand_id" :href="`/brands/${b.brand_id}/edit`" class="ml-1.5 underline hover:text-gray-900">Modifier</Link>
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-right font-medium tabular-nums text-gray-900">{{ formatDuration(b.median) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-gray-700">{{ b.measured }}</td>
                                <td class="px-2 py-2.5">
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            v-for="t in b.last"
                                            :key="t.id"
                                            type="button"
                                            class="inline-flex h-6 items-center gap-1 rounded px-1.5 text-xs tabular-nums"
                                            :class="
                                                !t.record_transfer
                                                    ? 'cursor-default bg-gray-50 text-gray-400'
                                                    : playing === t.id
                                                      ? 'bg-gray-900 text-white'
                                                      : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
                                            "
                                            :title="`${formatDateTime(t.called_at)}${t.record_transfer ? ' · écouter' : ' · pas d’enregistrement'}`"
                                            :disabled="!t.record_transfer"
                                            @click="play(t)"
                                        >
                                            <Pause v-if="playing === t.id" class="size-3" />
                                            {{ formatDuration(t.seconds) }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <Button variant="outline" size="sm" @click="markChecked(b)">Marquer vérifiée</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
