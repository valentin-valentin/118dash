<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { ArrowLeft, AlertTriangle, ChevronDown, ChevronRight, ExternalLink, Play, Square, Check } from 'lucide-vue-next'

// ─── Groupes ──────────────────────────────────────────────────────────────────
// Ordre d'affichage : les problèmes d'abord, les numéros en service à la fin
const GROUPS = [
    { key: 'inactive', label: 'Hors service', description: 'Aucun service déclaré', tone: 'red' },
    { key: 'unknown', label: 'Inconnus', description: 'Pas trouvé sur Numero08', tone: 'gray' },
    { key: 'error', label: 'Erreurs', description: 'Pas de réponse de l\'API', tone: 'gray' },
    { key: 'active', label: 'En service', description: 'Service déclaré', tone: 'green' },
]

const TONES = {
    red: { card: 'border-red-200 bg-red-50', text: 'text-red-700', dot: 'bg-red-500' },
    gray: { card: 'border-gray-200 bg-gray-50', text: 'text-gray-700', dot: 'bg-gray-400' },
    green: { card: 'border-green-200 bg-green-50', text: 'text-green-700', dot: 'bg-green-500' },
}

// ─── Audit ────────────────────────────────────────────────────────────────────
// La page vérifie les numéros en parallèle. Rien n'est stocké : quitter la page arrête l'audit.

// Nombre de requêtes simultanées
const CONCURRENCY = 4
// L'API autorise 120 appels / minute : on espace les départs pour rester en dessous (~115 / min)
const MIN_INTERVAL_MS = 520
// Attente après un dépassement de limite (429) avant de réessayer
const RATE_LIMIT_WAIT_MS = 30000

const results = ref([])
const running = ref(false)
const stopRequested = ref(false)
const waitingRateLimit = ref(false)
const finishedAt = ref(null)
const actionError = ref(null)

const processed = computed(() => results.value.filter((r) => r.group).length)
const total = computed(() => results.value.length)
const progress = computed(() => (total.value ? Math.round((processed.value / total.value) * 100) : 0))

// Débit plafonné par la limite de l'API : environ 2 numéros par seconde
const remainingLabel = computed(() => {
    const minutes = Math.ceil(((total.value - processed.value) * MIN_INTERVAL_MS) / 60000)
    return minutes <= 1 ? "moins d'une minute" : `environ ${minutes} min`
})

const counts = computed(() =>
    Object.fromEntries(GROUPS.map((g) => [g.key, results.value.filter((r) => r.group === g.key).length])),
)
const assignedNotActive = computed(
    () => results.value.filter((r) => r.assigned && r.group && r.group !== 'active').length,
)

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

async function getJson(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    return { status: response.status, data: response.ok || response.status === 429 ? await response.json() : null }
}

async function startAudit() {
    running.value = true
    stopRequested.value = false
    finishedAt.value = null
    actionError.value = null

    try {
        const { data } = await getJson('/data/phonenumbers/rsva-audit/numbers')
        if (!data) throw new Error('Impossible de récupérer la liste des numéros')
        results.value = data.map((n) => ({ ...n, group: null }))

        // État partagé entre les requêtes parallèles
        let nextIndex = 0
        let nextSlotAt = 0 // prochain départ autorisé
        let pausedUntil = 0 // pause commune après un 429

        // Réserve un créneau de départ, espacé de MIN_INTERVAL_MS du précédent
        async function waitForSlot() {
            const slot = Math.max(Date.now(), nextSlotAt, pausedUntil)
            nextSlotAt = slot + MIN_INTERVAL_MS
            await sleep(slot - Date.now())
        }

        async function worker() {
            while (!stopRequested.value && nextIndex < results.value.length) {
                const row = results.value[nextIndex++]

                while (!stopRequested.value) {
                    await waitForSlot()
                    if (stopRequested.value) return

                    let response
                    try {
                        response = await getJson(`/data/phonenumbers/${row.id}/rsva-status`)
                    } catch {
                        response = { status: 0, data: null }
                    }

                    if (response.status === 429) {
                        // Toutes les requêtes se mettent en pause, puis on réessaie ce numéro
                        pausedUntil = Math.max(pausedUntil, Date.now() + RATE_LIMIT_WAIT_MS)
                        waitingRateLimit.value = true
                        continue
                    }

                    waitingRateLimit.value = false
                    Object.assign(row, response.data ?? { group: 'error', error: `Erreur dashboard (HTTP ${response.status})` })
                    break
                }
            }
        }

        await Promise.all(Array.from({ length: CONCURRENCY }, worker))

        finishedAt.value = new Date()
    } catch (e) {
        actionError.value = e.message
    } finally {
        running.value = false
    }
}

function stopAudit() {
    stopRequested.value = true
}

// Prévenir avant de quitter la page pendant un audit
function onBeforeUnload(e) {
    if (running.value) e.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onUnmounted(() => {
    stopRequested.value = true
    window.removeEventListener('beforeunload', onBeforeUnload)
})

// ─── Filtres + regroupement ───────────────────────────────────────────────────
const search = ref('')
const onlyAssigned = ref(false)
const collapsed = ref({ active: true })

const filteredResults = computed(() => {
    const q = search.value.trim().toLowerCase()
    return results.value.filter((r) => {
        if (!r.group) return false
        if (onlyAssigned.value && !r.assigned) return false
        if (!q) return true
        return [r.phonenumber, r.company?.name, r.provider?.name, r.operator, r.holder, r.service]
            .some((v) => v && v.toLowerCase().includes(q))
    })
})

const grouped = computed(() =>
    GROUPS.map((g) => ({
        ...g,
        rows: filteredResults.value
            .filter((r) => r.group === g.key)
            // Les numéros assignés (donc affichés) d'abord
            .sort((a, b) => (b.assigned - a.assigned) || a.phonenumber.localeCompare(b.phonenumber)),
    })).filter((g) => g.rows.length),
)

function toggle(key) {
    collapsed.value[key] = !collapsed.value[key]
}

function scrollToGroup(key) {
    collapsed.value[key] = false
    document.getElementById(`group-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const columns = [
    { key: 'phonenumber', label: 'Numéro' },
    { key: 'company', label: 'Company' },
    { key: 'provider', label: 'Provider' },
    { key: 'assigned', label: 'Chez nous' },
    { key: 'status_label', label: 'Statut officiel' },
    { key: 'operator', label: 'Opérateur' },
    { key: 'holder', label: 'Titulaire / service' },
    { key: 'since', label: 'Depuis' },
    { key: 'public_url', label: '' },
]

function formatDate(value, withTime = false) {
    if (!value) return '-'
    const d = new Date(value)
    return withTime
        ? d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
        : d.toLocaleDateString('fr-FR')
}
</script>

<template>
    <Head title="Audit RSVA" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Numéros', href: '/phonenumbers' },
                    { title: 'Audit RSVA' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/phonenumbers">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
                    </Button>
                    <Button v-if="running" variant="outline" :disabled="stopRequested" @click="stopAudit">
                        <Square class="mr-2 h-4 w-4" />
                        Arrêter l'audit
                    </Button>
                    <Button v-else @click="startAudit">
                        <Play class="mr-2 h-4 w-4" />
                        {{ results.length ? 'Relancer l\'audit' : 'Lancer l\'audit' }}
                    </Button>
                </template>
            </PageHeader>

            <p v-if="actionError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ actionError }}</p>

            <!-- Aucun audit -->
            <div
                v-if="!results.length && !running"
                class="rounded-lg border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-500"
            >
                Interroge chaque numéro sur Numero08.com, garder la page ouverte jusqu'à la fin de l'audit.
            </div>

            <template v-if="results.length">
                <!-- État / progression -->
                <div class="rounded-lg border border-gray-100 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div class="text-gray-700">
                            <template v-if="running">
                                <span class="font-medium">Audit en cours</span>
                                · {{ processed }} / {{ total }} numéros · {{ remainingLabel }} restantes
                                <span v-if="waitingRateLimit" class="block text-xs text-amber-700">
                                    Limite de 120 appels / min atteinte, reprise dans 30 s
                                </span>
                            </template>
                            <template v-else-if="processed === total">
                                <Check class="mr-1 inline h-4 w-4 text-green-600" />
                                <span class="font-medium">Audit terminé</span>
                                le {{ formatDate(finishedAt, true) }} · {{ processed }} numéros vérifiés
                            </template>
                            <template v-else>
                                <span class="font-medium">Audit arrêté</span>
                                · {{ processed }} / {{ total }} numéros vérifiés
                            </template>
                        </div>
                    </div>
                    <div v-if="running" class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-gray-900 transition-all duration-500" :style="{ width: `${progress}%` }" />
                    </div>
                </div>

                <!-- Alerte principale : numéros affichés mais plus actifs -->
                <div
                    v-if="assignedNotActive"
                    class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                >
                    <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-medium">
                            {{ assignedNotActive }} numéro{{ assignedNotActive > 1 ? 's' : '' }}
                            assigné{{ assignedNotActive > 1 ? 's' : '' }} hors service
                        </p>
                        <p class="mt-0.5 text-red-700">En haut de chaque groupe.</p>
                    </div>
                </div>

                <!-- Compteurs par groupe -->
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <button
                        v-for="g in GROUPS"
                        :key="g.key"
                        type="button"
                        class="rounded-lg border p-4 text-left transition hover:shadow-sm disabled:cursor-default disabled:hover:shadow-none"
                        :class="counts[g.key] ? TONES[g.tone].card : 'border-gray-100 bg-white'"
                        :disabled="!counts[g.key]"
                        @click="scrollToGroup(g.key)"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ g.label }}</p>
                        <p class="mt-1.5 text-2xl font-semibold" :class="counts[g.key] ? TONES[g.tone].text : 'text-gray-300'">
                            {{ counts[g.key] }}
                        </p>
                    </button>
                </div>

                <!-- Filtres -->
                <div v-if="results.length" class="flex flex-wrap items-center gap-4">
                    <Input v-model="search" type="text" placeholder="Numéro, company, opérateur, titulaire..." class="h-8 max-w-xs" />
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="onlyAssigned" type="checkbox" class="rounded border-gray-300" />
                        Uniquement les numéros assignés
                    </label>
                </div>

                <!-- Résultats groupés -->
                <div
                    v-for="g in grouped"
                    :id="`group-${g.key}`"
                    :key="g.key"
                    class="scroll-mt-4 rounded-lg border border-gray-100 bg-white"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left"
                        @click="toggle(g.key)"
                    >
                        <ChevronRight v-if="collapsed[g.key]" class="h-4 w-4 text-gray-400" />
                        <ChevronDown v-else class="h-4 w-4 text-gray-400" />
                        <span class="h-2 w-2 rounded-full" :class="TONES[g.tone].dot" />
                        <span class="font-medium text-gray-900">{{ g.label }}</span>
                        <span class="text-sm text-gray-500">{{ g.rows.length }}</span>
                        <span class="hidden text-xs text-gray-400 md:inline">{{ g.description }}</span>
                    </button>

                    <div v-if="!collapsed[g.key]" class="border-t border-gray-50 px-2 pb-2">
                        <DataTable
                            :columns="columns"
                            :rows="g.rows"
                            :row-class="(row) => (row.assigned && g.key !== 'active' ? 'bg-red-50/60' : '')"
                        >
                            <template #phonenumber="{ value }">
                                <span class="font-mono font-medium text-gray-900">{{ value }}</span>
                            </template>

                            <template #company="{ row }">
                                <ColorBadge v-if="row.company" :color="row.company.color" :label="row.company.name" />
                                <span v-else class="text-xs text-gray-400">-</span>
                            </template>

                            <template #provider="{ row }">
                                <ColorBadge v-if="row.provider" :color="row.provider.color" :label="row.provider.name" />
                                <span v-else class="text-xs text-gray-400">-</span>
                            </template>

                            <template #assigned="{ value }">
                                <span v-if="value" class="text-xs font-medium text-gray-900">Assigné</span>
                                <span v-else class="text-xs text-gray-400">Libre</span>
                            </template>

                            <template #status_label="{ row }">
                                <span v-if="row.error" class="text-xs text-red-600">{{ row.error }}</span>
                                <span v-else class="text-xs text-gray-600">{{ row.status_label || '-' }}</span>
                            </template>

                            <template #operator="{ value }">
                                <span class="text-xs text-gray-600">{{ value || '-' }}</span>
                            </template>

                            <template #holder="{ row }">
                                <div class="text-xs leading-tight">
                                    <div class="text-gray-700">{{ row.holder || '-' }}</div>
                                    <div v-if="row.service && row.service !== row.holder" class="text-gray-400">{{ row.service }}</div>
                                </div>
                            </template>

                            <template #since="{ value }">
                                <span class="text-xs text-gray-600">{{ formatDate(value) }}</span>
                            </template>

                            <template #public_url="{ value }">
                                <a
                                    v-if="value"
                                    :href="value"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-gray-400 hover:text-gray-700"
                                    title="Fiche Numero08"
                                >
                                    <ExternalLink class="h-4 w-4" />
                                </a>
                            </template>
                        </DataTable>
                    </div>
                </div>

                <p
                    v-if="results.length && !grouped.length"
                    class="rounded-lg border border-gray-100 bg-white p-6 text-center text-sm text-gray-500"
                >
                    Aucun numéro ne correspond aux filtres.
                </p>
            </template>
        </div>
    </AppLayout>
</template>
