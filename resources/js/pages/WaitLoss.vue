<script setup>
import { computed, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import FilterBar from '@/components/FilterBar.vue'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { addDays, iso } from '@/lib/performance'

/**
 * Attente et appels perdus, Voxnode et notre serveur côte à côte.
 *   1. Vue d'ensemble : appels, décrochés, perdus, attentes médianes
 *   2. Notre serveur : à quelle étape les appels sont perdus (accueil, attente forcée, faute d'agent)
 *   3. Quand les clients raccrochent : abandons par tranche d'attente
 *   4. Par heure, puis jour par jour
 * Les attentes sont comptées depuis le début de l'appel : accueil et attente forcée compris.
 */
const PRESETS = [
    { key: 'today', label: "Aujourd'hui" },
    { key: 'yesterday', label: 'Hier' },
    { key: '7d', label: '7 derniers jours' },
    { key: 'this_month', label: 'Mois en cours' },
    { key: 'last_month', label: 'Mois précédent' },
    { key: 'custom', label: 'Personnalisée' },
]
const preset = ref('7d')
const customFrom = ref(iso(addDays(new Date(), -30)))
const customTo = ref(iso(new Date()))

const period = computed(() => {
    const today = new Date()
    const y = today.getFullYear()
    const m = today.getMonth()
    switch (preset.value) {
        case 'today':
            return { from: iso(today), to: iso(today) }
        case 'yesterday':
            return { from: iso(addDays(today, -1)), to: iso(addDays(today, -1)) }
        case '7d':
            return { from: iso(addDays(today, -6)), to: iso(today) }
        case 'this_month':
            return { from: iso(new Date(y, m, 1)), to: iso(today) }
        case 'last_month':
            return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) }
        default:
            return { from: customFrom.value, to: customTo.value }
    }
})

const data = ref(null)
const loading = ref(false)
const error = ref(null)
let requestId = 0
async function load() {
    const id = ++requestId
    loading.value = true
    error.value = null
    try {
        const res = await fetch(`/data/wait-loss?${new URLSearchParams(period.value)}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const json = await res.json()
        if (id === requestId) data.value = json
    } catch (e) {
        if (id === requestId) error.value = e.message
    } finally {
        if (id === requestId) loading.value = false
    }
}
watch(period, load, { immediate: true, deep: true })

// ─── Formats ──────────────────────────────────────────────────────────────────
const nf = new Intl.NumberFormat('fr-FR')
const int = (v) => (v === null || v === undefined ? '—' : nf.format(v).replace(/ /g, ' '))
const pct = (part, total, digits = 1) =>
    total ? `${new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format((part / total) * 100)} %` : '—'
function dur(s) {
    if (s === null || s === undefined) return '—'
    return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')}`
}
const lossRate = (g) => (g?.calls ? g.lost / g.calls : null)

const PLATFORMS = [
    { key: 'voxnode', label: 'Voxnode', color: 'bg-gray-300' },
    { key: 'selfhosted', label: 'Notre serveur', color: 'bg-gray-900' },
]
const OVERVIEW = computed(() =>
    data.value ? [{ key: 'all', label: 'Ensemble', total: true }, ...PLATFORMS].map((p) => ({ ...p, ...data.value.platforms[p.key] })) : [],
)

// ─── Tranches d'attente avant abandon ─────────────────────────────────────────
const BUCKET_LABELS = { 0: 'Moins de 30 s', 30: '30 s – 1 min', 60: '1 – 2 min', 120: '2 – 3 min', 180: '3 – 5 min', 300: 'Plus de 5 min' }
const bucketLabel = (b) => BUCKET_LABELS[b.from] ?? `${dur(b.from)} et plus`
const lostTotal = (key) => data.value?.platforms?.[key]?.lost ?? 0
const bucketMax = computed(() =>
    Math.max(0.0001, ...(data.value?.buckets ?? []).flatMap((b) => PLATFORMS.map((p) => (lostTotal(p.key) ? b[p.key] / lostTotal(p.key) : 0)))),
)

// ─── Notre serveur : où les appels sont perdus ────────────────────────────────
const detail = computed(() => data.value?.selfhosted_detail ?? null)
const LOSS_STAGES = [
    { key: 'lost_welcome', label: "Pendant l'accueil", hint: "Raccroché avant la fin de l'accueil", color: '#d1d5db' },
    { key: 'lost_forced', label: "Pendant l'attente forcée", hint: "Raccroché pendant l'attente que nous imposons", color: '#f59e0b' },
    { key: 'lost_queue', label: "Faute d'agent", hint: "Raccroché pendant la vraie attente, aucun agent libre", color: '#dc2626' },
]
const detailLost = computed(() => (detail.value ? LOSS_STAGES.reduce((n, s) => n + detail.value[s.key], 0) : 0))

// ─── Par heure et par jour ────────────────────────────────────────────────────
// Heures utiles : au moins 1 % de l'heure la plus chargée (la nuit, quelques appels isolés)
const hours = computed(() => {
    const rows = data.value?.hours ?? []
    const volume = (r) => (r.voxnode?.calls ?? 0) + (r.selfhosted?.calls ?? 0)
    const peak = Math.max(0, ...rows.map(volume))
    return rows.filter((r) => volume(r) >= peak * 0.01)
})
const days = computed(() => [...(data.value?.days ?? [])].reverse())
const hourLabel = (h) => `${String(h).padStart(2, '0')} h – ${String(h + 1).padStart(2, '0')} h`
const dayLabel = (d) => {
    const label = new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: '2-digit' })
    return label.charAt(0).toUpperCase() + label.slice(1)
}
// Barre du taux de perte : même échelle pour toutes les lignes d'un tableau
const maxLoss = (rows) => Math.max(0.0001, ...rows.flatMap((r) => PLATFORMS.map((p) => lossRate(r[p.key]) ?? 0)))
const hourMax = computed(() => maxLoss(hours.value))
const dayMax = computed(() => maxLoss(days.value))
</script>

<template>
    <Head title="Attente et pertes" />

    <AppLayout>
        <div class="space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Attente et pertes' }]" />

            <FilterBar title="Période" :has-active-filters="preset !== '7d'" @reset="preset = '7d'">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="p in PRESETS"
                        :key="p.key"
                        type="button"
                        class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                        :class="preset === p.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="preset = p.key"
                    >
                        {{ p.label }}
                    </button>
                    <template v-if="preset === 'custom'">
                        <Input v-model="customFrom" type="date" class="ml-1.5 h-8 w-40" />
                        <span class="text-xs text-gray-400">→</span>
                        <Input v-model="customTo" type="date" class="h-8 w-40" />
                    </template>
                </div>
            </FilterBar>

            <p class="text-xs text-gray-500">
                Perdu : le client a raccroché avant qu'un agent décroche. Les attentes sont comptées depuis le début de l'appel, accueil et attente forcée compris.
                Appels de test vers les agents IA exclus.
            </p>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">Chargement impossible : {{ error }}</p>

            <!-- ─── 1. Vue d'ensemble ─────────────────────────────────────── -->
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th class="px-4 pb-2 pt-3 text-left align-bottom font-normal leading-4" />
                                <th class="px-4 pb-2 pt-3 text-right align-bottom font-normal leading-4">Appels</th>
                                <th class="px-4 pb-2 pt-3 text-right align-bottom font-normal leading-4">Décrochés</th>
                                <th class="px-4 pb-2 pt-3 text-right align-bottom font-normal leading-4">Perdus</th>
                                <th class="px-4 pb-2 pt-3 text-right align-bottom font-normal leading-4">Attente médiane avant décroché</th>
                                <th class="px-4 pb-2 pt-3 text-right align-bottom font-normal leading-4">Attente médiane avant abandon</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading && !data"><td colspan="6" class="px-4 py-3"><div class="h-16 animate-pulse rounded bg-gray-50" /></td></tr>
                            <tr
                                v-for="r in OVERVIEW"
                                :key="r.key"
                                :class="[r.total && 'border-b border-t border-b-gray-100 border-t-gray-200 bg-gray-50/70', loading && 'opacity-60']"
                            >
                                <td class="px-4 py-2.5 text-gray-900" :class="r.total ? 'font-semibold' : 'font-medium'">
                                    <span class="flex items-center gap-2">
                                        <span v-if="!r.total" class="h-2.5 w-2.5 rounded-[2px]" :class="r.color" />
                                        {{ r.label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-900">{{ int(r.calls) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ int(r.answered) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    <span class="font-semibold text-gray-900">{{ pct(r.lost, r.calls) }}</span>
                                    <span class="ml-1.5 inline-block w-16 text-gray-400">{{ int(r.lost) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-900">{{ dur(r.answer_wait_median) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-900">{{ dur(r.abandon_wait_median) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
                <!-- ─── 2. Notre serveur : où les appels sont perdus ─────── -->
                <section class="min-w-0 rounded-lg border border-gray-200 bg-white">
                    <header class="border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Notre serveur : à quel moment les appels sont perdus</h2>
                        <p class="text-xs text-gray-500">Mesuré à chaque étape de l'appel, seulement sur notre serveur.</p>
                    </header>
                    <p v-if="!detail" class="px-4 py-4 text-sm text-gray-400">Aucun appel de notre serveur avec ce détail sur la période.</p>
                    <div v-else class="space-y-4 px-4 py-4">
                        <div>
                            <div class="flex h-5 gap-px overflow-hidden rounded-sm bg-gray-100">
                                <Tooltip v-for="s in LOSS_STAGES.filter((s) => detail[s.key])" :key="s.key">
                                    <TooltipTrigger as-child>
                                        <div class="h-full cursor-help" :style="{ width: `${(detail[s.key] / detailLost) * 100}%`, background: s.color, minWidth: '3px' }" />
                                    </TooltipTrigger>
                                    <TooltipContent>{{ s.hint }} : {{ int(detail[s.key]) }} appels</TooltipContent>
                                </Tooltip>
                            </div>
                            <table class="mt-3 w-full text-sm">
                                <tbody>
                                    <tr v-for="s in LOSS_STAGES" :key="s.key" class="border-b border-gray-50 last:border-b-0">
                                        <td class="py-1.5">
                                            <span class="flex items-center gap-2 text-gray-700">
                                                <span class="h-2.5 w-2.5 rounded-[2px]" :style="{ background: s.color }" />
                                                {{ s.label }}
                                            </span>
                                        </td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900">{{ int(detail[s.key]) }}</td>
                                        <td class="w-32 whitespace-nowrap py-1.5 pl-3 text-right tabular-nums text-gray-400">{{ pct(detail[s.key], detail.calls) }} des appels</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100 text-sm sm:grid-cols-3">
                            <div class="bg-white px-3 py-2.5">
                                <dt class="text-xs text-gray-500">Vraie attente médiane</dt>
                                <dd class="text-base font-semibold tabular-nums text-gray-900">{{ dur(detail.queue_wait_median) }}</dd>
                                <dd class="text-xs text-gray-400">avant qu'un agent décroche</dd>
                            </div>
                            <div class="bg-white px-3 py-2.5">
                                <dt class="text-xs text-gray-500">Décrochés en moins de 30 s</dt>
                                <dd class="text-base font-semibold tabular-nums text-gray-900">{{ pct(detail.answered_within_30, detail.answered, 0) }}</dd>
                                <dd class="text-xs text-gray-400">{{ pct(detail.answered_within_60, detail.answered, 0) }} en moins d'1 min</dd>
                            </div>
                            <div class="bg-white px-3 py-2.5">
                                <dt class="text-xs text-gray-500">Attente forcée médiane</dt>
                                <dd class="text-base font-semibold tabular-nums text-gray-900">{{ dur(detail.forced_wait_median) }}</dd>
                                <dd class="text-xs text-gray-400">hors appelants prioritaires</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <!-- ─── 3. Quand les clients raccrochent ──────────────────── -->
                <section class="min-w-0 rounded-lg border border-gray-200 bg-white">
                    <header class="border-b border-gray-100 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Quand les clients raccrochent</h2>
                        <p class="text-xs text-gray-500">Appels perdus selon le temps passé depuis le début de l'appel, en part des appels perdus de chaque plateforme.</p>
                    </header>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th class="px-4 pb-1.5 pt-3 text-left align-bottom font-normal leading-4">Raccroché après</th>
                                <th v-for="p in PLATFORMS" :key="p.key" class="w-2/5 px-4 pb-1.5 pt-3 text-left align-bottom font-normal leading-4">
                                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[2px]" :class="p.color" />{{ p.label }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in data?.buckets ?? []" :key="b.from" class="border-t border-gray-50">
                                <td class="whitespace-nowrap px-4 py-2 text-gray-700">{{ bucketLabel(b) }}</td>
                                <td v-for="p in PLATFORMS" :key="p.key" class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="h-3 flex-1 overflow-hidden rounded-sm bg-gray-50">
                                            <div
                                                class="h-full rounded-sm"
                                                :class="p.color"
                                                :style="{ width: `${lostTotal(p.key) ? (b[p.key] / lostTotal(p.key) / bucketMax) * 100 : 0}%` }"
                                            />
                                        </div>
                                        <span class="w-24 text-right tabular-nums text-gray-900">
                                            {{ pct(b[p.key], lostTotal(p.key), 0) }} <span class="text-gray-400">{{ int(b[p.key]) }}</span>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <!-- ─── 4. Par heure, jour par jour ───────────────────────────── -->
            <section
                v-for="t in [
                    { title: 'Par heure de la journée', rows: hours, key: 'hour', label: (r) => hourLabel(r.hour), max: hourMax },
                    { title: 'Jour par jour', rows: days, key: 'date', label: (r) => dayLabel(r.date), max: dayMax },
                ]"
                :key="t.title"
                class="rounded-lg border border-gray-200 bg-white"
            >
                <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">{{ t.title }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th rowspan="2" class="px-4 pb-2 pt-3 text-left align-bottom font-normal" />
                                <th v-for="p in PLATFORMS" :key="p.key" colspan="3" class="border-l border-gray-100 px-4 pt-3 text-left font-medium text-gray-700">
                                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[2px]" :class="p.color" />{{ p.label }}</span>
                                </th>
                            </tr>
                            <tr class="text-xs text-gray-500">
                                <template v-for="p in PLATFORMS" :key="p.key">
                                    <th class="border-l border-gray-100 px-4 pb-2 pt-1 text-right font-normal">Appels</th>
                                    <th class="px-4 pb-2 pt-1 text-left font-normal">Perdus</th>
                                    <th class="px-4 pb-2 pt-1 text-right font-normal">Attente méd. avant décroché</th>
                                </template>
                            </tr>
                        </thead>
                        <tbody :class="loading && 'opacity-60'">
                            <tr v-for="r in t.rows" :key="r[t.key]" class="border-t border-gray-50 hover:bg-gray-50/60">
                                <td class="whitespace-nowrap px-4 py-1.5 text-gray-700">{{ t.label(r) }}</td>
                                <template v-for="p in PLATFORMS" :key="p.key">
                                    <td class="border-l border-gray-100 px-4 py-1.5 text-right tabular-nums text-gray-700">{{ int(r[p.key]?.calls ?? 0) }}</td>
                                    <td class="px-4 py-1.5">
                                        <div v-if="r[p.key]?.calls" class="flex items-center gap-2">
                                            <span class="w-14 text-right tabular-nums text-gray-900">{{ pct(r[p.key].lost, r[p.key].calls, 0) }}</span>
                                            <div class="h-2 w-20 overflow-hidden rounded-sm bg-gray-50">
                                                <div class="h-full rounded-sm" :class="p.color" :style="{ width: `${(lossRate(r[p.key]) / t.max) * 100}%` }" />
                                            </div>
                                        </div>
                                        <span v-else class="text-gray-300">—</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-right tabular-nums text-gray-700">{{ dur(r[p.key]?.answer_wait_median) }}</td>
                                </template>
                            </tr>
                            <tr v-if="!loading && !t.rows.length">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400">Aucun appel sur la période</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
