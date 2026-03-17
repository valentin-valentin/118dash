<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil, Check, X, Link as LinkIcon, Copy } from 'lucide-vue-next'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/sources/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/sources')

const { filters, reset } = useFilters(
    {
        search: '',
        sort: 'name',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() => !!filters.search)

function toggleSort(key) {
    if (filters.sort === key) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.sort = key
        filters.dir = 'asc'
    }
}

const columns = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'name', label: 'Nom', sortable: true },
    { key: 'fingerprint', label: 'Fingerprint' },
    { key: 'only_dedicated_phonenumber', label: 'Numéro dédié' },
    { key: 'display_duration_minutes', label: 'Durée affichage' },
    { key: 'real_duration_minutes', label: 'Durée réelle' },
    { key: 'total_assignable', label: 'Numéros assignables' },
    { key: 'associations', label: 'Répartition' },
    { key: 'actions', label: 'Actions' },
]

// Calculer le total des weights et les pourcentages
function getAssociationStats(associations) {
    const total = associations.reduce((sum, assoc) => sum + (assoc.weight || 0), 0)
    return associations.map(assoc => ({
        ...assoc,
        percentage: total > 0 ? ((assoc.weight / total) * 100).toFixed(1) : 0,
    }))
}

// Formater les minutes en format lisible
function formatDuration(minutes, defaultValue = null) {
    const actualMinutes = minutes || defaultValue
    if (!actualMinutes) return '-'

    const days = Math.floor(actualMinutes / 1440)
    const hours = Math.floor((actualMinutes % 1440) / 60)
    const mins = actualMinutes % 60

    const parts = []
    if (days > 0) parts.push(`${days}j`)
    if (hours > 0) parts.push(`${hours}h`)
    if (mins > 0 && days === 0) parts.push(`${mins}m`)

    const result = parts.length > 0 ? parts.join(' ') : '-'

    // Si on utilise la valeur par défaut, l'indiquer
    if (!minutes && defaultValue) {
        return `${result} (défaut)`
    }

    return result
}

// ─── Partner URL Generator ────────────────────────────────────────────────────
const showPartnerUrlModal = ref(false)
const selectedSourceIds = ref([])
const generatedUrl = ref('')
const isGenerating = ref(false)

function openPartnerUrlModal() {
    selectedSourceIds.value = []
    generatedUrl.value = ''
    showPartnerUrlModal.value = true
}

function toggleSource(sourceId) {
    const index = selectedSourceIds.value.indexOf(sourceId)
    if (index > -1) {
        selectedSourceIds.value.splice(index, 1)
    } else {
        selectedSourceIds.value.push(sourceId)
    }
}

async function generateUrl() {
    if (selectedSourceIds.value.length === 0) return

    isGenerating.value = true
    try {
        const response = await fetch('/data/sources/generate-partner-url', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ source_ids: selectedSourceIds.value })
        })

        if (!response.ok) {
            throw new Error('Erreur lors de la génération')
        }

        const data = await response.json()
        generatedUrl.value = data.url
    } catch (error) {
        alert('Erreur lors de la génération de l\'URL')
    } finally {
        isGenerating.value = false
    }
}

function copyUrl() {
    navigator.clipboard.writeText(generatedUrl.value)
    alert('URL copiée!')
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Sources" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Sources' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" @click="openPartnerUrlModal">
                        <LinkIcon class="mr-2 h-4 w-4" />
                        URL Partenaire
                    </Button>
                    <Button as-child>
                        <Link href="/sources/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle source
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Avec Fingerprint" :value="stats.data?.with_fingerprint" :loading="stats.loading" />
                <StatCard label="Numéro dédié" :value="stats.data?.with_dedicated_phonenumber" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
            >
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <!-- Recherche par nom -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher par nom..."
                        class="h-8"
                    />
                </div>
            </FilterBar>

            <!-- Table -->
            <div class="rounded-lg border border-gray-100 bg-white">
                <DataTable
                    :columns="columns"
                    :rows="table.data?.items ?? []"
                    :loading="table.loading"
                    :sort-key="filters.sort"
                    :sort-dir="filters.dir"
                    @sort="toggleSort"
                >
                    <template #id="{ value }">
                        <span class="font-mono text-xs text-gray-500">#{{ value }}</span>
                    </template>

                    <template #name="{ row }">
                        <ColorBadge :color="row.color" :label="row.name" />
                    </template>

                    <template #fingerprint="{ value }">
                        <Check v-if="value" class="h-4 w-4 text-green-600" />
                        <X v-else class="h-4 w-4 text-gray-400" />
                    </template>

                    <template #only_dedicated_phonenumber="{ value }">
                        <Check v-if="value" class="h-4 w-4 text-green-600" />
                        <X v-else class="h-4 w-4 text-gray-400" />
                    </template>

                    <template #display_duration_minutes="{ value }">
                        <span class="text-sm" :class="value ? 'text-gray-700' : 'text-gray-500 italic'">
                            {{ formatDuration(value, table.data?.default_display_duration) }}
                        </span>
                    </template>

                    <template #real_duration_minutes="{ value }">
                        <span class="text-sm" :class="value ? 'text-gray-700' : 'text-gray-500 italic'">
                            {{ formatDuration(value, table.data?.default_real_duration) }}
                        </span>
                    </template>

                    <template #total_assignable="{ row }">
                        <span class="text-sm font-medium text-gray-900">
                            {{ row.total_assignable || 0 }} numéros
                        </span>
                    </template>

                    <template #associations="{ row }">
                        <div v-if="row.source_provider_companies && row.source_provider_companies.length > 0" class="space-y-1">
                            <div
                                v-for="assoc in getAssociationStats(row.source_provider_companies)"
                                :key="assoc.id"
                                class="flex items-center gap-2"
                            >
                                <ColorBadge
                                    v-if="assoc.provider_company?.provider"
                                    :color="assoc.provider_company.provider.color"
                                    :label="assoc.provider_company.provider.name"
                                />
                                <ColorBadge
                                    v-if="assoc.provider_company?.company"
                                    :color="assoc.provider_company.company.color"
                                    :label="assoc.provider_company.company.name"
                                />
                                <span class="text-xs font-medium text-gray-900">
                                    {{ assoc.percentage }}%
                                </span>
                                <span class="text-xs text-gray-500">
                                    (w: {{ assoc.weight }})
                                </span>
                                <span class="text-xs font-medium text-gray-600">
                                    {{ assoc.assignable_count || 0 }} num.
                                </span>
                            </div>
                        </div>
                        <span v-else class="text-sm text-gray-400">Aucune répartition</span>
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/sources/${row.id}/edit`">
                                <Pencil class="h-4 w-4" />
                            </Link>
                        </Button>
                    </template>
                </DataTable>

                <div
                    v-if="table.data?.total"
                    class="flex items-center justify-between border-t border-gray-50 px-4 py-3"
                >
                    <div class="text-sm text-gray-500">
                        Page {{ table.data.current_page }} sur {{ table.data.last_page }}
                        · {{ table.data.total.toLocaleString() }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="table.data.current_page > 1"
                            variant="outline"
                            size="sm"
                            @click="filters.page = (filters.page || 1) - 1"
                        >
                            Précédent
                        </Button>
                        <Button
                            v-if="table.data.current_page < table.data.last_page"
                            variant="outline"
                            size="sm"
                            @click="filters.page = (filters.page || 1) + 1"
                        >
                            Suivant
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Modal génération URL partenaire -->
            <Dialog :open="showPartnerUrlModal" @update:open="(val) => showPartnerUrlModal = val">
                <DialogContent class="max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Générer URL Partenaire</DialogTitle>
                    </DialogHeader>

                    <div class="space-y-4">
                        <!-- Liste des sources -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-900">Sélectionnez les sources :</label>
                            <div class="border rounded-lg max-h-80 overflow-y-auto p-2 space-y-1">
                                <div
                                    v-for="source in table.data?.items ?? []"
                                    :key="source.id"
                                    class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer"
                                    @click="toggleSource(source.id)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="selectedSourceIds.includes(source.id)"
                                        class="h-4 w-4 rounded border-gray-300"
                                    />
                                    <ColorBadge :color="source.color" :label="source.name" />
                                    <span class="text-xs text-gray-500">#{{ source.id }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bouton générer -->
                        <Button
                            :disabled="selectedSourceIds.length === 0 || isGenerating"
                            @click="generateUrl"
                            class="w-full"
                        >
                            {{ isGenerating ? 'Génération...' : 'Générer l\'URL' }}
                        </Button>

                        <!-- URL générée -->
                        <div v-if="generatedUrl" class="space-y-2 p-3 bg-gray-50 rounded-lg">
                            <label class="text-sm font-medium text-gray-900">URL générée :</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    :value="generatedUrl"
                                    readonly
                                    class="flex-1 px-3 py-2 text-xs font-mono bg-white border rounded-md"
                                />
                                <Button variant="outline" size="sm" @click="copyUrl">
                                    <Copy class="h-4 w-4" />
                                </Button>
                            </div>
                            <p class="text-xs text-gray-600">
                                Accès aux stats de {{ selectedSourceIds.length }} source{{ selectedSourceIds.length > 1 ? 's' : '' }}
                            </p>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
