<script setup>
import { xsrfToken } from '@/lib/csrf'
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil, Upload } from 'lucide-vue-next'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/blacklists/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/blacklists')

// ─── Filter Options ───────────────────────────────────────────────────────────
const filterOptions = useApi('/data/blacklists/filter-options')

const { filters, reset } = useFilters(
    {
        search: '',
        source: '',
        sort: 'phonenumber',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() => !!filters.search || !!filters.source)

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
    { key: 'phonenumber', label: 'Numéro', sortable: true },
    { key: 'source', label: 'Source', sortable: true },
    { key: 'note', label: 'Note' },
    { key: 'created_at', label: 'Créé le', sortable: true },
    { key: 'actions', label: 'Actions' },
]

// ─── Import en masse ──────────────────────────────────────────────────────────
const showImportModal = ref(false)
const importForm = ref({ numbers: '', source: '', note: '' })
const importing = ref(false)
const importResult = ref(null)
const importError = ref(null)

function openImportModal() {
    importForm.value = { numbers: '', source: '', note: '' }
    importResult.value = null
    importError.value = null
    showImportModal.value = true
}

async function submitImport() {
    importing.value = true
    importError.value = null
    try {
        const response = await fetch('/data/blacklists/bulk-import', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(importForm.value),
        })

        const data = await response.json()

        if (!response.ok) {
            importError.value = data.message || "Erreur lors de l'import"
            return
        }

        importResult.value = data
        table.load(filters)
        stats.load()
        filterOptions.load()
    } catch (error) {
        importError.value = "Erreur lors de l'import"
    } finally {
        importing.value = false
    }
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
    filterOptions.load()
})
</script>

<template>
    <Head title="Blacklists" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Blacklists' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" @click="openImportModal">
                        <Upload class="mr-2 h-4 w-4" />
                        Import en masse
                    </Button>
                    <Button as-child>
                        <Link href="/blacklists/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle blacklist
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
                    <!-- Recherche -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher..."
                        class="h-8"
                    />

                    <!-- Source -->
                    <FilterSelect
                        v-model="filters.source"
                        :options="filterOptions.data?.sources || []"
                        placeholder="Toutes les sources"
                        :searchable="true"
                        :loading="filterOptions.loading"
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

                    <template #phonenumber="{ value }">
                        <span class="font-mono font-medium text-gray-900">{{ value }}</span>
                    </template>

                    <template #source="{ value }">
                        <span class="text-sm text-gray-600">{{ value || '-' }}</span>
                    </template>

                    <template #note="{ value }">
                        <span class="text-sm text-gray-600">{{ value || '-' }}</span>
                    </template>

                    <template #created_at="{ value }">
                        <span class="text-sm text-gray-600">
                            {{ value ? new Date(value).toLocaleDateString('fr-FR') : '-' }}
                        </span>
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/blacklists/${row.id}/edit`">
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
        </div>

        <!-- Modal import en masse -->
        <Dialog v-model:open="showImportModal">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Import en masse</DialogTitle>
                    <DialogDescription>
                        Un numéro par ligne (ou séparés par virgule / point-virgule). Formats acceptés :
                        0756996360, 33756996360, +33 7 56 99 63 60, 0033756996360…
                    </DialogDescription>
                </DialogHeader>

                <div v-if="importResult" class="space-y-3 text-sm">
                    <div class="rounded-md bg-green-50 p-3 text-green-800">
                        {{ importResult.created }} numéro(s) ajouté(s)
                    </div>
                    <div v-if="importResult.already_exists" class="text-gray-600">
                        {{ importResult.already_exists }} déjà présent(s) dans la blacklist (ignoré(s))
                    </div>
                    <div v-if="importResult.duplicates_in_batch" class="text-gray-600">
                        {{ importResult.duplicates_in_batch }} doublon(s) dans la liste importée (ignoré(s))
                    </div>
                    <div v-if="importResult.invalid.length" class="space-y-1">
                        <p class="text-red-600">{{ importResult.invalid.length }} numéro(s) invalide(s) :</p>
                        <pre class="max-h-40 overflow-auto rounded-md bg-red-50 p-2 font-mono text-xs text-red-700">{{ importResult.invalid.join('\n') }}</pre>
                    </div>
                </div>

                <form v-else id="import-form" class="space-y-4" @submit.prevent="submitImport">
                    <div class="space-y-2">
                        <Label for="import-numbers">Numéros *</Label>
                        <Textarea
                            id="import-numbers"
                            v-model="importForm.numbers"
                            rows="10"
                            required
                            class="font-mono"
                            placeholder="0756996360&#10;33612345678&#10;+33 1 23 45 67 89"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="import-source">Source</Label>
                        <Input id="import-source" v-model="importForm.source" type="text" placeholder="ex: Signalement client..." />
                    </div>
                    <div class="space-y-2">
                        <Label for="import-note">Note</Label>
                        <Textarea id="import-note" v-model="importForm.note" rows="2" />
                    </div>
                    <p v-if="importError" class="text-sm text-red-600">{{ importError }}</p>
                </form>

                <DialogFooter>
                    <template v-if="importResult">
                        <Button variant="outline" @click="importResult = null; importForm.numbers = ''">Nouvel import</Button>
                        <Button @click="showImportModal = false">Fermer</Button>
                    </template>
                    <template v-else>
                        <Button variant="outline" @click="showImportModal = false">Annuler</Button>
                        <Button type="submit" form="import-form" :disabled="importing || !importForm.numbers.trim()">
                            {{ importing ? 'Import...' : 'Importer' }}
                        </Button>
                    </template>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
