<script setup>
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ExternalLink } from 'lucide-vue-next'
import { formatQuality, qualityClass, qualityLevel } from '@/lib/quality'

/**
 * Appels dégradés du mois (score < 3,1) : liste à gauche, détail de l'appel choisi à droite
 * (enregistrements à écouter, cause réseau, durées, numéros, lien vers la fiche complète).
 *
 * @prop {Boolean} open
 * @prop {Object}  filters  filtres du tableau de bord (mois, marques, agents...)
 * @prop {Object}  initial  { agent?, callId? } : filtre ou appel à ouvrir directement
 * @emits update:open
 */
const props = defineProps({
    open: Boolean,
    filters: { type: Object, default: () => ({}) },
    initial: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:open'])

const platform = ref('')
const agent = ref('')
const sort = ref('recent')
const calls = ref([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const selectedId = ref(null)
const selected = computed(() => calls.value.find((c) => c.id === selectedId.value) ?? null)

// Mêmes filtres que la page, au format attendu par l'API (listes séparées par des virgules)
function params(p) {
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(props.filters)) {
        if (Array.isArray(value) ? value.length : value !== '' && value !== false && value !== null && value !== undefined) {
            query.set(key, Array.isArray(value) ? value.join(',') : String(value))
        }
    }
    if (platform.value) query.set('platform', platform.value)
    if (agent.value.trim()) query.set('agent', agent.value.trim())
    query.set('sort', sort.value)
    query.set('page', p)
    return query
}

async function load(reset = true) {
    loading.value = true
    try {
        const p = reset ? 1 : page.value + 1
        const res = await fetch(`/data/audio-quality/degraded?${params(p)}`, { headers: { Accept: 'application/json' } })
        const json = await res.json()
        calls.value = reset ? json.data : [...calls.value, ...json.data]
        total.value = json.total
        page.value = json.current_page
        lastPage.value = json.last_page
        if (reset && !calls.value.some((c) => c.id === selectedId.value)) selectedId.value = calls.value[0]?.id ?? null
    } finally {
        loading.value = false
    }
}

// Ouverture : on repart des réglages demandés (agent cliqué, appel cliqué)
watch(
    () => props.open,
    (open) => {
        if (!open) return
        platform.value = ''
        sort.value = 'recent'
        agent.value = props.initial.agent ?? ''
        selectedId.value = props.initial.callId ?? null
        load()
    },
)
let searchTimer = null
watch([platform, sort], () => props.open && load())
watch(agent, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => props.open && load(), 300)
})

// ─── Affichage ────────────────────────────────────────────────────────────────
const PLATFORMS = { voxnode: 'Voxnode', selfhosted: 'Notre serveur' }
const monthLabel = computed(() => {
    const m = props.filters.month
    const date = m ? new Date(Number(m.slice(0, 4)), Number(m.slice(5, 7)) - 1, 1) : new Date()
    return date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})
const parisDate = (v, options) => new Date(v).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', ...options })
const shortDate = (v) => parisDate(v, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(' ', ' à ')
const longDate = (v) => parisDate(v, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).replace(' à ', ' à ')
function dur(s) {
    if (s === null || s === undefined) return '—'
    const v = Math.max(0, Math.round(s))
    return v < 60 ? `${v} s` : `${Math.floor(v / 60)} min ${String(v % 60).padStart(2, '0')}`
}
const num = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('fr-FR').format(v).replace(/ /g, ' '))
const phone = (v) => (v ? v.replace(/^\+33(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/, '0$1 $2 $3 $4 $5') : '—')

// Mesures réseau : seuils au-delà desquels la voix se dégrade nettement
const NETWORK = [
    { key: 'loss_pct', label: 'Paquets perdus', format: (n) => `${String(n.loss_pct ?? '—').replace('.', ',')} %`, sub: (n) => `${num(n.packets_lost)} sur ${num(n.packets_received)}`, level: (n) => (n.loss_pct >= 5 ? 'bad' : n.loss_pct >= 2 ? 'poor' : '') },
    { key: 'jitter_max_ms', label: 'Gigue max', format: (n) => `${n.jitter_max_ms ?? '—'} ms`, sub: () => 'irrégularité des paquets', level: (n) => (n.jitter_max_ms >= 80 ? 'bad' : n.jitter_max_ms >= 40 ? 'poor' : '') },
    { key: 'rtt_avg_ms', label: 'Latence moyenne', format: (n) => `${n.rtt_avg_ms ?? '—'} ms`, sub: (n) => `max ${n.rtt_max_ms ?? '—'} ms`, level: (n) => (n.rtt_avg_ms >= 400 ? 'bad' : n.rtt_avg_ms >= 250 ? 'poor' : '') },
    { key: 'codec', label: 'Codec', format: (n) => n.codec ?? '—', sub: (n) => `${num(n.samples)} mesures`, level: () => '' },
]
const LEVEL_CLASS = { bad: 'text-red-600', poor: 'text-amber-700', '': 'text-gray-900' }

// Cause probable : la mesure la plus hors norme
function cause(c) {
    const n = c.network
    if (!n) return 'détail réseau indisponible'
    const parts = []
    if (n.loss_pct >= 2) parts.push(`pertes de paquets (${String(n.loss_pct).replace('.', ',')} %)`)
    if (n.jitter_max_ms >= 40) parts.push(`gigue (${n.jitter_max_ms} ms)`)
    if (n.rtt_avg_ms >= 250) parts.push(`latence (${n.rtt_avg_ms} ms)`)
    return parts.length ? parts.join(', ') : 'aucune mesure réseau hors norme'
}

// Durée totale pas encore connue (appel pas finalisé à l'import) : pas de total ni d'attente
const totalKnown = (c) => c.total_s > 0 && c.total_s >= c.agent_s
const waitS = (c) => (totalKnown(c) ? Math.max(0, c.total_s - c.agent_s - c.transfer_s) : null)
const HANGUP = { agent: "L'agent", caller: "L'appelant", client: "L'appelant" }
const hangup = (v) => (!v || v === 'undefined' ? '—' : HANGUP[v] ?? v)
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="flex h-[85vh] max-w-6xl flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl">
            <DialogHeader class="border-b border-gray-100 px-5 py-4">
                <DialogTitle>Appels dégradés · {{ monthLabel }}</DialogTitle>
                <DialogDescription>{{ num(total) }} appel{{ total > 1 ? 's' : '' }} avec un score inférieur à 3,1 (mauvais), mêmes filtres que le tableau.</DialogDescription>
                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <div class="inline-flex rounded-md border border-gray-200 p-0.5 text-sm">
                        <button
                            v-for="(label, key) in { '': 'Toutes', voxnode: 'Voxnode', selfhosted: 'Notre serveur' }"
                            :key="key"
                            type="button"
                            class="rounded px-3 py-1"
                            :class="platform === key ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-50'"
                            @click="platform = key"
                        >
                            {{ label }}
                        </button>
                    </div>
                    <input v-model="agent" type="search" placeholder="Agent (dedi014, prepose…)" class="h-8 w-56 rounded-md border border-gray-200 px-2.5 text-sm" />
                    <select v-model="sort" class="h-8 rounded-md border border-gray-200 bg-white px-2 text-sm">
                        <option value="recent">Plus récents d'abord</option>
                        <option value="worst">Pires scores d'abord</option>
                    </select>
                </div>
            </DialogHeader>

            <div class="grid min-h-0 flex-1 grid-cols-[20rem_minmax(0,1fr)]">
                <!-- Liste -->
                <div class="min-h-0 overflow-y-auto border-r border-gray-100">
                    <p v-if="!loading && !calls.length" class="px-4 py-8 text-center text-sm text-gray-400">Aucun appel dégradé</p>
                    <button
                        v-for="c in calls"
                        :key="c.id"
                        type="button"
                        class="flex w-full items-center gap-3 border-b border-gray-50 px-4 py-2 text-left text-sm"
                        :class="selectedId === c.id ? 'bg-sky-50' : 'hover:bg-gray-50'"
                        @click="selectedId = c.id"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ c.agent || '—' }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ shortDate(c.called_at) }} · {{ PLATFORMS[c.platform] }}</span>
                        </span>
                        <span class="shrink-0 font-medium tabular-nums" :class="qualityClass(c.mos)">{{ formatQuality(c.mos, 1) }}</span>
                    </button>
                    <div v-if="page < lastPage" class="p-3">
                        <button type="button" class="w-full rounded-md border border-gray-200 py-1.5 text-sm text-gray-600 hover:bg-gray-50" :disabled="loading" @click="load(false)">
                            {{ loading ? 'Chargement…' : `Afficher plus (${num(total - calls.length)} restants)` }}
                        </button>
                    </div>
                    <div v-if="loading && !calls.length" class="space-y-2 p-4">
                        <div v-for="n in 8" :key="n" class="h-9 animate-pulse rounded bg-gray-50" />
                    </div>
                </div>

                <!-- Détail -->
                <div class="min-h-0 overflow-y-auto">
                    <p v-if="!selected" class="px-6 py-10 text-center text-sm text-gray-400">Choisissez un appel</p>
                    <div v-else class="space-y-6 px-6 py-5">
                        <!-- En-tête -->
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="text-sm text-gray-500 first-letter:uppercase">{{ longDate(selected.called_at) }}</div>
                                <div class="mt-0.5 text-lg font-semibold text-gray-900">
                                    {{ selected.agent || '—' }}
                                    <span class="text-base font-normal text-gray-500">· {{ selected.center || 'centre inconnu' }} · {{ PLATFORMS[selected.platform] }}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold tabular-nums" :class="qualityClass(selected.mos)">{{ formatQuality(selected.mos, 1) }}</div>
                                <div class="text-sm text-gray-500">{{ qualityLevel(selected.mos) }} · {{ cause(selected) }}</div>
                            </div>
                        </div>

                        <!-- Enregistrements -->
                        <section>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Enregistrements</h3>
                            <div v-if="selected.record_agent || selected.record_transfer" class="grid gap-3 md:grid-cols-2">
                                <div v-for="r in [
                                    { label: 'Partie agent', url: selected.record_agent, length: selected.agent_s },
                                    { label: 'Partie transfert', url: selected.record_transfer, length: selected.transfer_s },
                                ].filter((r) => r.url)" :key="r.label" class="rounded-lg border border-gray-100 p-3">
                                    <div class="mb-2 flex items-baseline justify-between text-sm">
                                        <span class="font-medium text-gray-900">{{ r.label }}</span>
                                        <a :href="r.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-800">
                                            {{ dur(r.length) }} <ExternalLink class="h-3 w-3" />
                                        </a>
                                    </div>
                                    <!-- Un lecteur par appel : changer d'appel arrête la lecture -->
                                    <audio :key="`${selected.id}-${r.label}`" :src="r.url" controls preload="none" class="h-9 w-full" />
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-400">Pas d'enregistrement pour cet appel.</p>
                        </section>

                        <!-- Réseau -->
                        <section>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Réseau du softphone agent</h3>
                            <div v-if="selected.network" class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100 md:grid-cols-4">
                                <div v-for="m in NETWORK" :key="m.key" class="bg-white px-3 py-2.5">
                                    <div class="text-xs text-gray-500">{{ m.label }}</div>
                                    <div class="text-base font-semibold tabular-nums" :class="LEVEL_CLASS[m.level(selected.network)]">{{ m.format(selected.network) }}</div>
                                    <div class="text-xs text-gray-400">{{ m.sub(selected.network) }}</div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-400">Détail réseau indisponible pour cet appel.</p>
                        </section>

                        <!-- Durées et appel -->
                        <div class="grid gap-6 md:grid-cols-2">
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Durées</h3>
                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between"><dt class="text-gray-500">Durée totale</dt><dd class="tabular-nums text-gray-900">{{ totalKnown(selected) ? dur(selected.total_s) : '—' }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Attente initiale</dt><dd class="tabular-nums text-gray-900">{{ dur(waitS(selected)) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Agent</dt><dd class="tabular-nums text-gray-900">{{ dur(selected.agent_s) }}</dd></div>
                                    <div class="flex justify-between pl-3"><dt class="text-gray-400">dont conversation</dt><dd class="tabular-nums text-gray-600">{{ dur(selected.talk_s) }}</dd></div>
                                    <div class="flex justify-between pl-3"><dt class="text-gray-400">dont pause</dt><dd class="tabular-nums text-gray-600">{{ dur(selected.pause_s) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Transfert</dt><dd class="tabular-nums text-gray-900">{{ dur(selected.transfer_s) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">A raccroché</dt><dd class="text-gray-900">{{ hangup(selected.who_hangup) }}</dd></div>
                                </dl>
                            </section>
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Appel</h3>
                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Marque</dt><dd class="truncate text-gray-900">{{ selected.brand || '—' }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Appelant</dt><dd class="font-mono text-gray-900">{{ phone(selected.caller) }}</dd></div>
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Numéro appelé</dt><dd class="font-mono text-gray-900">{{ phone(selected.called) }}</dd></div>
                                </dl>
                                <a :href="`/calls/${selected.id}`" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-1 text-sm text-gray-600 underline hover:text-gray-900">
                                    Fiche complète de l'appel <ExternalLink class="h-3.5 w-3.5" />
                                </a>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
