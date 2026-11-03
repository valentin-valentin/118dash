<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { ChevronRight, LoaderCircle } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { agentLabel, formatDateTime, formatDuration } from '@/lib/calls'

/**
 * Marques à vérifier : marques dont le numéro de transfert ne répond peut-être plus normalement.
 * Pour chaque marque (nom + numéro de transfert), sur 7 ou 30 jours :
 *   - durée réelle des mises en relation (sans l'attente Voxnode avant la mise en relation) ;
 *   - part de mises en relation de moins de 15 s (message « numéro non attribué », répondeur « fermé », occupé…) ;
 *   - part sans enregistrement, part des clients qui rappellent dans l'heure ;
 *   - part de moins de 15 s sur les 7 derniers jours et les 7 jours d'avant (un numéro qui change se voit là).
 * À l'ouverture, la page mesure d'abord les transferts pas encore mesurés (taille de chaque enregistrement).
 */
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')

const days = ref(7)
const search = ref('')
const minTransfers = ref(true)
const showChecked = ref(false)
const sort = reactive({ key: 'short_pct', dir: 'desc' })

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
            const res = await fetch(`/data/brand-checks/measure?days=${days.value}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            })
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

async function load() {
    loading.value = true
    error.value = null
    try {
        await measureAll()
        const res = await fetch(`/data/brand-checks?days=${days.value}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}
watch(days, () => {
    open.value = null
    load()
})
onMounted(load)

// Une marque vérifiée il y a moins de 7 jours est masquée (sauf si on demande à la voir)
const recentlyChecked = (b) => b.checked_at && Date.now() - new Date(b.checked_at).getTime() < 7 * 86400 * 1000

function resetFilters() {
    search.value = ''
    minTransfers.value = true
    showChecked.value = false
}

const rows = computed(() => {
    const q = search.value.trim().toLowerCase()
    const list = (data.value?.brands ?? []).filter(
        (b) =>
            (!minTransfers.value || b.transfers >= 10) &&
            (showChecked.value || !recentlyChecked(b)) &&
            (!q || b.name.toLowerCase().includes(q) || b.phonenumber.includes(q)),
    )
    const value = (b) => (sort.key === 'trend' ? trend(b) : b[sort.key])
    return list.sort((a, b) => {
        const va = value(a)
        const vb = value(b)
        if (va === null || va === undefined) return 1
        if (vb === null || vb === undefined) return -1
        const cmp = typeof va === 'string' ? va.localeCompare(vb) : va - vb
        return sort.dir === 'asc' ? cmp : -cmp
    })
})
const hiddenChecked = computed(() => (showChecked.value ? 0 : (data.value?.brands ?? []).filter(recentlyChecked).length))

// Écart, en points, entre les 7 derniers jours et les 7 jours d'avant
const trend = (b) => (b.week_short_pct === null || b.prev_short_pct === null ? null : Math.round((b.week_short_pct - b.prev_short_pct) * 10) / 10)

function toggleSort(key) {
    if (sort.key === key) sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'
    else Object.assign(sort, { key, dir: key === 'name' ? 'asc' : 'desc' })
}

const pct = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')} %`)
const phone = (n) => (n?.startsWith('+33') ? `0${n.slice(3)}`.replace(/(\d{2})(?=\d)/g, '$1 ') : n)

// Transferts les plus courts d'une marque, à écouter
const open = ref(null)
const shortest = reactive({ key: null, rows: [], loading: false, error: null })
async function toggle(b) {
    if (open.value === b.key) {
        open.value = null
        return
    }
    open.value = b.key
    Object.assign(shortest, { key: b.key, rows: [], loading: true, error: null })
    try {
        const res = await fetch(`/data/brand-checks/shortest?${new URLSearchParams({ days: days.value, name: b.name, phonenumber: b.phonenumber })}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        if (shortest.key === b.key) shortest.rows = await res.json()
    } catch (e) {
        if (shortest.key === b.key) shortest.error = e.message
    } finally {
        if (shortest.key === b.key) shortest.loading = false
    }
}

async function setChecked(b, checked) {
    const res = await fetch('/data/brand-checks/check', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ name: b.name, phonenumber: b.phonenumber, checked }),
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) {
        error.value = json.message ?? `Erreur HTTP ${res.status}`
        return
    }
    b.checked_at = json.checked_at
}

// Un seul enregistrement à la fois
function pauseOthers(e) {
    document.querySelectorAll('audio').forEach((a) => a !== e.target && a.pause())
}

const COLUMNS = [
    { key: 'name', label: 'Marque' },
    { key: 'transfers', label: 'Transferts', right: true },
    { key: 'median', label: 'Durée réelle médiane', right: true },
    { key: 'short_pct', label: 'Moins de 15 s', right: true },
    { key: 'no_recording_pct', label: 'Sans enregistrement', right: true },
    { key: 'called_back_pct', label: "Rappellent dans l'heure", right: true },
    { key: 'week_short_pct', label: '< 15 s, 7 derniers jours', right: true },
    { key: 'prev_short_pct', label: '< 15 s, 7 jours d’avant', right: true },
    { key: 'trend', label: 'Écart', right: true },
]
</script>

<template>
    <Head title="Marques à vérifier" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques à vérifier' }]" />

            <FilterBar title="Filtres" :has-active-filters="!!search || !minTransfers || showChecked" @reset="resetFilters">
                <div class="flex flex-wrap items-center gap-3">
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
                    <Input v-model="search" type="text" placeholder="Marque ou numéro..." class="h-8 w-56" />
                    <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-700">
                        <Checkbox :model-value="minTransfers" @update:model-value="(v) => (minTransfers = !!v)" />
                        Au moins 10 transferts
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-700">
                        <Checkbox :model-value="showChecked" @update:model-value="(v) => (showChecked = !!v)" />
                        Afficher les marques vérifiées cette semaine{{ hiddenChecked ? ` (${hiddenChecked})` : '' }}
                    </label>
                </div>
            </FilterBar>

            <p class="text-xs text-gray-500">
                Durée réelle : durée de la mise en relation avec la marque, sans l'attente Voxnode qui la précède.
                Moins de 15 s : le plus souvent un message « numéro non attribué », un répondeur « fermé » ou occupé.
                Rappellent dans l'heure : même numéro qui rappelle dans l'heure suivant la fin du transfert.
            </p>

            <!-- Mesure des transferts pas encore mesurés -->
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
                        <thead class="border-b border-gray-100 text-xs text-gray-400">
                            <tr>
                                <th class="w-8" />
                                <th
                                    v-for="col in COLUMNS"
                                    :key="col.key"
                                    class="cursor-pointer px-2 py-2 font-medium uppercase tracking-wide select-none hover:text-gray-700"
                                    :class="col.right ? 'text-right' : 'text-left'"
                                    @click="toggleSort(col.key)"
                                >
                                    {{ col.label }}<template v-if="sort.key === col.key"> {{ sort.dir === 'asc' ? '↑' : '↓' }}</template>
                                </th>
                                <th class="px-2 py-2 pr-4 text-right font-medium uppercase tracking-wide">Vérification</th>
                            </tr>
                        </thead>
                        <tbody :class="loading && data && 'opacity-50'">
                            <tr v-if="loading && !data">
                                <td :colspan="COLUMNS.length + 2" class="py-12 text-center text-gray-400"><LoaderCircle class="mx-auto size-5 animate-spin" /></td>
                            </tr>
                            <tr v-else-if="!rows.length">
                                <td :colspan="COLUMNS.length + 2" class="py-12 text-center text-gray-400">—</td>
                            </tr>
                            <template v-for="b in rows" :key="b.key">
                                <tr class="cursor-pointer border-t border-gray-50 hover:bg-gray-50" :class="open === b.key && 'bg-gray-50'" @click="toggle(b)">
                                    <td class="pl-3 text-gray-400"><ChevronRight class="size-4 transition-transform" :class="open === b.key && 'rotate-90'" /></td>
                                    <td class="px-2 py-2">
                                        <div class="font-medium text-gray-900">{{ b.name }}</div>
                                        <div class="text-xs text-gray-500 tabular-nums">{{ phone(b.phonenumber) }}</div>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums text-gray-900">{{ b.transfers.toLocaleString('fr-FR') }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums text-gray-900">{{ formatDuration(b.median) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">
                                        <span class="text-gray-900">{{ pct(b.short_pct) }}</span>
                                        <div class="text-xs text-gray-400">{{ b.short }} sur {{ b.measured }}</div>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums text-gray-900">{{ pct(b.no_recording_pct) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums text-gray-900">{{ pct(b.called_back_pct) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">
                                        <span class="text-gray-900">{{ pct(b.week_short_pct) }}</span>
                                        <div class="text-xs text-gray-400">{{ b.week_measured }} transferts</div>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums">
                                        <span class="text-gray-900">{{ pct(b.prev_short_pct) }}</span>
                                        <div class="text-xs text-gray-400">{{ b.prev_measured }} transferts</div>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums" :class="trend(b) >= 20 ? 'font-medium text-red-700' : 'text-gray-700'">
                                        {{ trend(b) === null ? '—' : `${trend(b) > 0 ? '+' : ''}${String(trend(b)).replace('.', ',')} pts` }}
                                    </td>
                                    <td class="px-2 py-2 pr-4 text-right whitespace-nowrap" @click.stop>
                                        <template v-if="b.checked_at">
                                            <span class="text-xs text-gray-500">Vérifiée le {{ new Date(b.checked_at).toLocaleDateString('fr-FR') }}</span>
                                            <button type="button" class="ml-2 text-xs text-gray-400 underline hover:text-gray-700" @click="setChecked(b, false)">Annuler</button>
                                        </template>
                                        <Button v-else variant="outline" size="sm" @click="setChecked(b, true)">Marquer vérifiée</Button>
                                    </td>
                                </tr>

                                <!-- Transferts les plus courts : écouter ce que répond le numéro -->
                                <tr v-if="open === b.key" class="bg-gray-50">
                                    <td />
                                    <td :colspan="COLUMNS.length + 1" class="px-2 pt-1 pb-4">
                                        <p class="mb-2 text-xs font-medium text-gray-500">Les 10 mises en relation les plus courtes de la période</p>
                                        <p v-if="shortest.loading" class="text-gray-400"><LoaderCircle class="size-4 animate-spin" /></p>
                                        <p v-else-if="shortest.error" class="text-sm text-red-600">{{ shortest.error }}</p>
                                        <p v-else-if="!shortest.rows.length" class="text-sm text-gray-500">—</p>
                                        <ul v-else class="space-y-1.5">
                                            <li v-for="t in shortest.rows" :key="t.id" class="flex flex-wrap items-center gap-3 rounded-md border border-gray-100 bg-white px-3 py-1.5 text-xs">
                                                <span class="w-32 tabular-nums text-gray-700">{{ formatDateTime(t.called_at) }}</span>
                                                <span class="w-28 tabular-nums text-gray-600">{{ phone(t.caller) }}</span>
                                                <span class="w-24 text-gray-600">{{ agentLabel(t.agent_name) ?? '-' }}</span>
                                                <span class="w-36 text-gray-900">
                                                    Réelle {{ formatDuration(t.real) }}
                                                    <span v-if="t.platform !== 'selfhosted'" class="text-gray-400">· Voxnode {{ formatDuration(t.duration_transfer) }}</span>
                                                </span>
                                                <audio v-if="t.record_transfer" :src="t.record_transfer" controls preload="none" class="h-8 min-w-0 flex-1" @play="pauseOthers" />
                                                <span v-else class="text-gray-400">Pas d'enregistrement</span>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
