<script setup>
import { computed, ref } from 'vue'
import DegradedCallsDialog from '@/components/DegradedCallsDialog.vue'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { QUALITY_SCALE, formatQuality, qualityClass } from '@/lib/quality'

/**
 * Qualité audio du mois, sous le tableau jour par jour du tableau de bord (toujours affichée).
 *   - par plateforme : appels mesurés / mesurables, répartition en 5 niveaux, appels dégradés
 *   - derniers appels dégradés avec leur cause réseau (pertes, gigue, latence)
 *   - agents les plus touchés
 *
 * @prop {Object}  report   réponse de /data/audio-quality
 * @prop {Boolean} loading
 * @prop {Object}  filters  filtres du tableau de bord, repris par la fenêtre des appels dégradés
 */
const props = defineProps({
    report: Object,
    loading: Boolean,
    filters: { type: Object, default: () => ({}) },
})

// Fenêtre des appels dégradés : ouverte sur un appel, sur un agent, ou sur tout le mois
const dialogOpen = ref(false)
const dialogInitial = ref({})
function openDegraded(initial = {}) {
    dialogInitial.value = initial
    dialogOpen.value = true
}

const PLATFORMS = [
    { key: 'voxnode', label: 'Voxnode' },
    { key: 'selfhosted', label: 'Notre serveur' },
]
// Du meilleur au pire : neutre quand c'est bon, orange puis rouge quand ça se dégrade
const LEVELS = [
    { key: 'excellent', label: 'Excellent', range: '≥ 4,3', color: '#374151' },
    { key: 'very_good', label: 'Très bon', range: '4,0 – 4,3', color: '#6b7280' },
    { key: 'fair', label: 'Correct', range: '3,6 – 4,0', color: '#d1d5db' },
    { key: 'poor', label: 'Médiocre', range: '3,1 – 3,6', color: '#f59e0b' },
    { key: 'bad', label: 'Mauvais', range: '< 3,1', color: '#dc2626' },
]
const SCALE_LINES = QUALITY_SCALE.split('\n')

const nf = new Intl.NumberFormat('fr-FR')
const int = (v) => nf.format(v ?? 0).replace(/ /g, ' ')
const pct = (part, total) => (total ? `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: part / total < 0.1 ? 1 : 0 }).format((part / total) * 100)} %` : '—')

const monthLabel = computed(() => {
    if (!props.report?.month) return ''
    const [y, m] = props.report.month.split('-').map(Number)
    return new Date(y, m - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})

const platformRows = computed(() =>
    PLATFORMS.map((p) => {
        const r = props.report?.platforms?.[p.key] ?? { eligible: 0, measured: 0, mos: null, sum: 0, levels: {} }
        return { ...p, ...r, degraded: r.levels?.bad ?? 0 }
    }),
)
// Ensemble : sommes des deux plateformes, moyenne recalculée à partir des sommes
const allRow = computed(() => {
    const rows = platformRows.value
    const measured = rows.reduce((n, r) => n + r.measured, 0)
    const levels = Object.fromEntries(LEVELS.map((l) => [l.key, rows.reduce((n, r) => n + (r.levels?.[l.key] ?? 0), 0)]))
    return {
        key: 'all',
        label: 'Ensemble',
        total: true,
        eligible: rows.reduce((n, r) => n + r.eligible, 0),
        measured,
        mos: measured ? rows.reduce((n, r) => n + (r.sum ?? 0), 0) / measured : null,
        levels,
        degraded: levels.bad,
    }
})
const rows = computed(() => [allRow.value, ...platformRows.value])
const totals = computed(() => ({ eligible: allRow.value.eligible, measured: allRow.value.measured }))
const hasMeasures = computed(() => totals.value.measured > 0)

// Cause probable d'un appel dégradé : les mesures réseau au-delà des seuils usuels
function cause(c) {
    if (c.loss_pct === null && c.jitter_ms === null && c.rtt_ms === null) return { text: 'détail réseau indisponible', strong: false }
    const parts = []
    if (c.loss_pct !== null && c.loss_pct >= 2) parts.push(`pertes ${String(c.loss_pct).replace('.', ',')} %`)
    if (c.jitter_ms !== null && c.jitter_ms >= 40) parts.push(`gigue ${c.jitter_ms} ms`)
    if (c.rtt_ms !== null && c.rtt_ms >= 250) parts.push(`latence ${c.rtt_ms} ms`)
    if (parts.length) return { text: parts.join(', '), strong: true }
    return { text: `pertes ${String(c.loss_pct ?? 0).replace('.', ',')} %, gigue ${c.jitter_ms ?? '—'} ms, latence ${c.rtt_ms ?? '—'} ms`, strong: false }
}

// called_at est en UTC (ISO si l'API le formate, « AAAA-MM-JJ HH:MM:SS » sinon) ; affiché en heure de Paris,
// comme le reste du tableau de bord, quel que soit le fuseau de l'ordinateur
const formatWhen = (v) => (v ? new Date(v.includes('T') ? v : `${v.replace(' ', 'T')}Z`).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(' ', ' à ') : '—')
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white">
        <!-- En-tête : part des appels mesurés -->
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 py-3" :class="hasMeasures && 'border-b border-gray-100'">
            <div class="flex items-baseline gap-2">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="cursor-help text-xs font-semibold uppercase tracking-wider text-gray-500 underline decoration-dotted underline-offset-2">Qualité audio</span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p v-for="line in SCALE_LINES" :key="line" class="leading-relaxed">{{ line }}</p>
                    </TooltipContent>
                </Tooltip>
                <span class="text-sm text-gray-500">{{ monthLabel }}</span>
            </div>
            <div v-if="loading" class="h-4 w-56 animate-pulse rounded bg-gray-100" />
            <div v-else class="text-sm text-gray-600">
                <span class="font-semibold tabular-nums text-gray-900">{{ int(totals.measured) }}</span>
                appel{{ totals.measured > 1 ? 's' : '' }} mesuré{{ totals.measured > 1 ? 's' : '' }} sur
                <span class="tabular-nums">{{ int(totals.eligible) }}</span> où un agent a répondu
                <span v-if="totals.eligible" class="text-gray-400">({{ pct(totals.measured, totals.eligible) }})</span>
            </div>
        </div>

        <template v-if="hasMeasures && !loading">
            <!-- Par plateforme -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500">
                            <th class="px-4 pb-2 pt-3 align-bottom leading-4 font-normal" />
                            <th class="px-4 pb-2 pt-3 align-bottom leading-4 text-right font-normal">Qualité moyenne</th>
                            <th class="px-4 pb-2 pt-3 align-bottom leading-4 text-right font-normal">Mesurés</th>
                            <th class="w-1/2 px-4 pb-2 pt-3 align-bottom font-normal leading-4">
                                <!-- Légende en ligne : même ligne de base que les autres titres -->
                                <span v-for="l in LEVELS" :key="l.key" class="mr-3 inline-block whitespace-nowrap last:mr-0">
                                    <span class="relative top-px mr-1.5 inline-block h-2.5 w-2.5 rounded-[2px] align-baseline" :style="{ background: l.color }" />{{ l.label }}
                                </span>
                            </th>
                            <th class="px-4 pb-2 pt-3 align-bottom leading-4 text-right font-normal">Dégradés</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.key" :class="r.total && 'border-t border-b border-t-gray-200 border-b-gray-100 bg-gray-50/70'">
                            <td class="px-4 py-2 text-gray-900" :class="r.total ? 'font-semibold' : 'font-medium'">{{ r.label }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums" :class="qualityClass(r.mos) || 'text-gray-900'">{{ formatQuality(r.mos) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-gray-700">
                                {{ int(r.measured) }}<span class="inline-block w-[3.75rem] text-right text-gray-400">({{ pct(r.measured, r.eligible) }})</span>
                            </td>
                            <td class="px-4 py-2">
                                <div v-if="r.measured" class="flex h-4 gap-px overflow-hidden rounded-sm">
                                    <Tooltip v-for="l in LEVELS.filter((l) => r.levels[l.key])" :key="l.key">
                                        <TooltipTrigger as-child>
                                            <div class="h-full cursor-help" :style="{ width: `${(r.levels[l.key] / r.measured) * 100}%`, background: l.color, minWidth: '3px' }" />
                                        </TooltipTrigger>
                                        <TooltipContent>{{ l.label }} ({{ l.range }}) : {{ int(r.levels[l.key]) }} appels, {{ pct(r.levels[l.key], r.measured) }}</TooltipContent>
                                    </Tooltip>
                                </div>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" :class="r.degraded ? 'font-medium text-red-600' : 'text-gray-400'">
                                {{ int(r.degraded) }}<span class="inline-block w-[3.75rem] text-right font-normal text-gray-400">{{ r.measured ? `(${pct(r.degraded, r.measured)})` : '' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Appels dégradés et agents touchés -->
            <div v-if="report.degraded_calls.length" class="grid gap-6 border-t border-gray-100 px-4 py-3 lg:grid-cols-[minmax(0,1fr)_21rem]">
                <div class="min-w-0">
                    <div class="mb-1.5 flex items-baseline justify-between gap-3">
                        <span class="text-xs text-gray-500">Derniers appels dégradés</span>
                        <button type="button" class="text-xs text-gray-600 underline hover:text-gray-900" @click="openDegraded()">
                            Voir les {{ int(allRow.degraded) }} appels dégradés, avec les enregistrements
                        </button>
                    </div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="c in report.degraded_calls"
                                :key="c.id"
                                class="cursor-pointer border-b border-gray-50 last:border-b-0 hover:bg-gray-50"
                                title="Écouter et voir le détail"
                                @click="openDegraded({ callId: c.id })"
                            >
                                <td class="whitespace-nowrap py-1 pr-4 tabular-nums text-gray-500">{{ formatWhen(c.called_at) }}</td>
                                <td class="whitespace-nowrap py-1 pr-4 font-medium text-gray-900">{{ c.agent || '—' }}</td>
                                <td class="whitespace-nowrap py-1 pr-4 text-gray-500">{{ c.center || '—' }}</td>
                                <td class="whitespace-nowrap py-1 pr-4 text-gray-500">{{ c.platform === 'selfhosted' ? 'Notre serveur' : 'Voxnode' }}</td>
                                <td class="whitespace-nowrap py-1 pr-4 text-right font-medium tabular-nums" :class="qualityClass(c.mos)">{{ formatQuality(c.mos, 1) }}</td>
                                <td class="py-1" :class="cause(c).strong ? 'text-gray-700' : 'text-gray-400'">{{ cause(c).text }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="report.agents.length">
                    <div class="mb-1.5 text-xs text-gray-500">Agents les plus touchés</div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="a in report.agents"
                                :key="a.agent"
                                class="cursor-pointer border-b border-gray-50 last:border-b-0 hover:bg-gray-50"
                                :title="`Voir les appels dégradés de ${a.agent}`"
                                @click="openDegraded({ agent: a.agent })"
                            >
                                <td class="whitespace-nowrap py-1 pr-3 font-medium text-gray-900">{{ a.agent }}</td>
                                <td class="whitespace-nowrap py-1 pr-3 text-gray-500">{{ a.center || '—' }}</td>
                                <td class="whitespace-nowrap py-1 text-right tabular-nums text-gray-500">
                                    <span class="font-medium text-red-600">{{ int(a.degraded) }}</span> sur {{ int(a.measured) }}<span class="inline-block w-14 text-right text-gray-400">({{ pct(a.degraded, a.measured) }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <DegradedCallsDialog v-model:open="dialogOpen" :filters="filters" :initial="dialogInitial" />
    </div>
</template>
