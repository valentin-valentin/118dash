<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil, AlertTriangle, Check, X, Trash2, RefreshCw, Edit3, Zap } from 'lucide-vue-next'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/phonenumbers/stats')

// ─── Refresh temps réel ────────────────────────────────────────────────────────
const now = ref(Date.now())
let refreshInterval = null

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/phonenumbers')

// ─── Filter Options ───────────────────────────────────────────────────────────
const filterOptions = useApi('/data/phonenumbers/filter-options')

const { filters, reset } = useFilters(
    {
        search: '',
        company_id: '',
        provider_id: '',
        only_source_id: '',
        source_id: '',
        include_deleted: '',
        only_deleted: '',
        assigned_status: '',
        routing_status: '',
        sort: 'id',
        dir: 'desc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.search ||
    !!filters.company_id ||
    !!filters.provider_id ||
    !!filters.only_source_id ||
    !!filters.source_id ||
    !!filters.include_deleted ||
    !!filters.only_deleted ||
    !!filters.assigned_status ||
    !!filters.routing_status
)

function toggleSort(key) {
    if (filters.sort === key) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.sort = key
        filters.dir = 'asc'
    }
}

const columns = [
    { key: 'select', label: '' },
    { key: 'id', label: 'ID', sortable: true },
    { key: 'phonenumber', label: 'Numéro', sortable: true },
    { key: 'company', label: 'Company' },
    { key: 'provider', label: 'Provider' },
    { key: 'only_source', label: 'Source dédiée' },
    { key: 'source', label: 'Source actuelle' },
    { key: 'assigned_status', label: 'Assignation' },
    { key: 'routing', label: 'Routing' },
    { key: 'stats', label: 'Statistiques' },
]

// ─── Sélection Multiple ───────────────────────────────────────────────────────
const selectedIds = ref([])
const allSelected = computed(() => {
    return table.data?.items?.length > 0 && selectedIds.value.length === table.data.items.length
})

function toggleSelectAll() {
    if (allSelected.value) {
        selectedIds.value = []
    } else {
        selectedIds.value = table.data.items.map(item => item.id)
    }
}

function toggleSelect(id) {
    const index = selectedIds.value.indexOf(id)
    if (index > -1) {
        selectedIds.value.splice(index, 1)
    } else {
        selectedIds.value.push(id)
    }
}

function isSelected(id) {
    return selectedIds.value.includes(id)
}

function getTimeRemaining(expiresAt) {
    if (!expiresAt) return null
    const expires = new Date(expiresAt)
    const diffMs = expires - now.value
    const diffMinutes = Math.floor(diffMs / 1000 / 60)

    if (diffMinutes < 0) return 'Expiré'
    if (diffMinutes === 0) return '< 1mn'
    return `${diffMinutes}mn`
}

function getExpirationTime(expiresAt) {
    if (!expiresAt) return null
    const expires = new Date(expiresAt)
    return expires.toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    })
}

function getTooltipText(displayExpiresAt, realExpiresAt) {
    const lines = []
    if (displayExpiresAt) {
        const expires = new Date(displayExpiresAt)
        const diffMs = expires - now.value
        const diffMinutes = Math.floor(diffMs / 1000 / 60)
        const time = getExpirationTime(displayExpiresAt)

        if (diffMinutes < 0) {
            lines.push(`Affichage a expiré à ${time}`)
        } else {
            const timeRemaining = diffMinutes === 0 ? '< 1mn' : `${diffMinutes}mn`
            lines.push(`Affichage expire dans ${timeRemaining} (à ${time})`)
        }
    }
    if (realExpiresAt) {
        const expires = new Date(realExpiresAt)
        const diffMs = expires - now.value
        const diffMinutes = Math.floor(diffMs / 1000 / 60)
        const time = getExpirationTime(realExpiresAt)

        if (diffMinutes < 0) {
            lines.push(`Expiration réelle à ${time}`)
        } else {
            const timeRemaining = diffMinutes === 0 ? '< 1mn' : `${diffMinutes}mn`
            lines.push(`Expiration réelle dans ${timeRemaining} (à ${time})`)
        }
    }
    return lines.join('\n')
}

function formatDuration(seconds) {
    if (!seconds || seconds === 0) return '00:00:00'
    const hours = Math.floor(seconds / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)
    const secs = seconds % 60
    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

function hasInvalidRouting(row) {
    // Si le numéro a une erreur de routing explicite
    if (row.routing_error) return true

    // Vérifier si le numéro est expiré
    const isExpired = row.real_expires_at && new Date(row.real_expires_at) < now.value

    // Si le numéro n'a jamais été assigné
    if (!row.assigned_at) {
        // Doit pointer vers scr.sip.twilio.com immédiatement
        if (row.current_endpoint !== 'scr.sip.twilio.com') {
            return true
        }
    }
    // Si le numéro est assigné et PAS expiré
    else if (!isExpired) {
        // Doit pointer vers une vraie destination (pas scr.sip.twilio.com)
        if (row.current_endpoint === 'scr.sip.twilio.com') {
            return true
        }
    }
    // Si le numéro est expiré
    else {
        const expiredFor2Minutes = (now.value - new Date(row.real_expires_at)) > 2 * 60 * 1000

        // Si expiré depuis plus de 2 minutes et ne pointe PAS vers scr.sip.twilio.com → ERREUR
        if (expiredFor2Minutes && row.current_endpoint !== 'scr.sip.twilio.com') {
            return true
        }
        // Si expiré depuis moins de 2 minutes → OK (délai de grâce pour le re-routing)
    }

    return false
}

const invalidRoutingCount = computed(() => {
    if (!table.data?.items) return 0
    return table.data.items.filter(row => hasInvalidRouting(row)).length
})

function getRowClass(row) {
    if (hasInvalidRouting(row)) {
        return 'bg-red-50 hover:bg-red-100/80'
    }
    return ''
}

// Réinitialiser la sélection quand on change de page
watch(() => table.data?.items, () => {
    selectedIds.value = []
})

// ─── Actions en Masse ─────────────────────────────────────────────────────────
const isProcessing = ref(false)
const showSourceModal = ref(false)
const selectedSourceId = ref(null)

function openSourceModal() {
    showSourceModal.value = true
    selectedSourceId.value = null
}

function closeSourceModal() {
    showSourceModal.value = false
    selectedSourceId.value = null
}

async function bulkUpdateSource() {
    if (!selectedSourceId.value && selectedSourceId.value !== '') {
        alert('Veuillez sélectionner une source')
        return
    }

    isProcessing.value = true
    try {
        const response = await fetch('/data/phonenumbers/bulk-update-source', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                ids: selectedIds.value,
                source_id: selectedSourceId.value === '' ? null : selectedSourceId.value
            }),
        })

        const data = await response.json()

        if (data.success) {
            selectedIds.value = []
            closeSourceModal()
            table.load(filters)
            stats.load()
        } else {
            alert(data.message || 'Erreur lors de la modification')
        }
    } catch (error) {
        alert('Erreur lors de la modification')
    } finally {
        isProcessing.value = false
    }
}

async function bulkDelete() {
    if (!confirm(`Voulez-vous vraiment supprimer ${selectedIds.value.length} numéro(s) ?`)) return

    isProcessing.value = true
    try {
        const response = await fetch('/data/phonenumbers/bulk-delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ids: selectedIds.value }),
        })

        const data = await response.json()

        if (data.success) {
            selectedIds.value = []
            table.load(filters)
            stats.load()
        }
    } catch (error) {
        alert('Erreur lors de la suppression')
    } finally {
        isProcessing.value = false
    }
}

async function bulkRestore() {
    if (!confirm(`Voulez-vous vraiment restaurer ${selectedIds.value.length} numéro(s) ?`)) return

    isProcessing.value = true
    try {
        const response = await fetch('/data/phonenumbers/bulk-restore', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ids: selectedIds.value }),
        })

        const data = await response.json()

        if (data.success) {
            selectedIds.value = []
            table.load(filters)
            stats.load()
        }
    } catch (error) {
        alert('Erreur lors de la restauration')
    } finally {
        isProcessing.value = false
    }
}

async function bulkRoute() {
    if (!confirm(`Voulez-vous vraiment router ${selectedIds.value.length} numéro(s) maintenant ?`)) return

    isProcessing.value = true
    try {
        const response = await fetch('/data/phonenumbers/manual-route', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ids: selectedIds.value }),
        })

        const data = await response.json()

        if (data.success) {
            alert(data.message || 'Routing effectué avec succès')
            selectedIds.value = []
            table.load(filters)
            stats.load()
        } else {
            alert(data.message || 'Erreur lors du routing')
        }
    } catch (error) {
        alert('Erreur lors du routing')
    } finally {
        isProcessing.value = false
    }
}

async function cancelDeletion(id) {
    try {
        const response = await fetch('/data/phonenumbers/cancel-deletion', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ id }),
        })

        const data = await response.json()

        if (data.success) {
            table.load(filters)
            stats.load()
        } else {
            alert(data.message || 'Erreur lors de l\'annulation')
        }
    } catch (error) {
        alert('Erreur lors de l\'annulation')
    }
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
    filterOptions.load()

    // Update toutes les secondes pour rafraîchir les temps restants
    refreshInterval = setInterval(() => {
        now.value = Date.now()
    }, 1000)
})

onUnmounted(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval)
    }
})
</script>

<template>
    <Head title="Numéros de téléphone" />

    <AppLayout>
        <div class="space-y-6 p-6 pb-24">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Numéros' }
                ]"
            >
                <template #actions>
                    <Button as-child>
                        <Link href="/phonenumbers/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter des numéros
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Actifs" :value="stats.data?.active" :loading="stats.loading" />
                <StatCard label="Supprimés" :value="stats.data?.deleted" :loading="stats.loading" variant="danger" />
                <StatCard label="Assignés" :value="stats.data?.assigned" :loading="stats.loading" variant="success" />
                <StatCard label="Non assignés" :value="stats.data?.unassigned" :loading="stats.loading" />
                <StatCard label="Erreurs routing" :value="stats.data?.with_routing_error" :loading="stats.loading" variant="warning" />
            </div>

            <!-- Filtres -->
            <FilterBar
                title="Filtres"
                :has-active-filters="hasFilters"
                :result-count="table.data?.total"
                :loading-results="table.loading"
                @reset="reset"
            >
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm text-gray-600">Résultats par page :</span>
                    <select
                        v-model.number="filters.per_page"
                        class="rounded-md border border-gray-300 px-3 py-1 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                        <option :value="250">250</option>
                        <option :value="500">500</option>
                        <option :value="1000">1000</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    <!-- Recherche -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher un numéro..."
                        class="h-8"
                    />

                    <!-- Company -->
                    <FilterSelect
                        v-model="filters.company_id"
                        :options="filterOptions.data?.companies || []"
                        placeholder="Toutes les companies"
                        :searchable="true"
                        :loading="filterOptions.loading"
                    />

                    <!-- Provider -->
                    <FilterSelect
                        v-model="filters.provider_id"
                        :options="filterOptions.data?.providers || []"
                        placeholder="Tous les providers"
                        :searchable="true"
                        :loading="filterOptions.loading"
                    />

                    <!-- Source dédiée -->
                    <FilterSelect
                        v-model="filters.only_source_id"
                        :options="[
                            { value: 'null', label: 'Sans source dédiée' },
                            ...(filterOptions.data?.sources || [])
                        ]"
                        placeholder="Toutes sources dédiées"
                        :searchable="true"
                        :loading="filterOptions.loading"
                    />

                    <!-- Source actuelle -->
                    <FilterSelect
                        v-model="filters.source_id"
                        :options="[
                            { value: 'null', label: 'Non assigné' },
                            ...(filterOptions.data?.sources || [])
                        ]"
                        placeholder="Toutes sources actuelles"
                        :searchable="true"
                        :loading="filterOptions.loading"
                    />

                    <!-- Statut suppression -->
                    <FilterSelect
                        v-model="filters.only_deleted"
                        :options="[
                            { value: 'yes', label: 'Supprimés uniquement' },
                        ]"
                        placeholder="Actifs uniquement"
                        :searchable="false"
                    />

                    <!-- Statut assignation -->
                    <FilterSelect
                        v-model="filters.assigned_status"
                        :options="[
                            { value: 'assigned', label: 'Assignés' },
                            { value: 'unassigned', label: 'Non assignés' },
                        ]"
                        placeholder="Tous les statuts"
                        :searchable="false"
                    />

                    <!-- Routing status -->
                    <FilterSelect
                        v-model="filters.routing_status"
                        :options="[
                            { value: 'error', label: 'Avec erreur' },
                            { value: 'no_error', label: 'Sans erreur' },
                        ]"
                        placeholder="Tous"
                        :searchable="false"
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
                    :row-class="getRowClass"
                    @sort="toggleSort"
                >
                    <template #select="{ row }">
                        <input
                            type="checkbox"
                            :checked="isSelected(row.id)"
                            @change="toggleSelect(row.id)"
                            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                        />
                    </template>

                    <template #header-select>
                        <input
                            type="checkbox"
                            :checked="allSelected"
                            @change="toggleSelectAll"
                            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                        />
                    </template>

                    <template #id="{ value }">
                        <span class="font-mono text-xs text-gray-500">#{{ value }}</span>
                    </template>

                    <template #phonenumber="{ row, value }">
                        <div class="space-y-1">
                            <span class="font-mono font-semibold text-gray-900">{{ value }}</span>
                            <div v-if="row.will_be_deleted" class="flex items-center gap-2">
                                <span class="text-xs font-medium text-red-600">Sera supprimé</span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="cancelDeletion(row.id)"
                                    class="h-5 px-2 text-xs text-gray-500 hover:text-gray-700 hover:bg-gray-100"
                                >
                                    Annuler
                                </Button>
                            </div>
                        </div>
                    </template>

                    <template #company="{ row }">
                        <span class="inline-flex rounded-full bg-purple-100 px-2 py-1 text-xs font-medium text-purple-700">
                            {{ row.company?.name || '-' }}
                        </span>
                    </template>

                    <template #provider="{ row }">
                        <span class="inline-flex rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                            {{ row.provider?.name || '-' }}
                        </span>
                    </template>

                    <template #only_source="{ row }">
                        <span
                            v-if="row.only_source"
                            class="inline-flex rounded-full bg-orange-100 px-2 py-1 text-xs font-medium text-orange-700"
                        >
                            {{ row.only_source.name }}
                        </span>
                        <span v-else class="text-xs text-gray-400">-</span>
                    </template>

                    <template #source="{ row }">
                        <span
                            v-if="row.source"
                            class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700"
                        >
                            {{ row.source.name }}
                        </span>
                        <span v-else class="text-xs text-gray-400">Non assigné</span>
                    </template>

                    <template #assigned_status="{ row }">
                        <div class="space-y-1">
                            <div class="flex items-center gap-1">
                                <Check v-if="row.assigned_at" class="h-4 w-4 text-green-600" />
                                <X v-else class="h-4 w-4 text-gray-400" />
                                <span class="text-xs text-gray-600">
                                    {{ row.assigned_at ? 'Assigné' : 'Libre' }}
                                </span>
                            </div>
                            <div v-if="row.display_expires_at || row.real_expires_at" class="group relative">
                                <div class="text-[11px] text-gray-600">
                                    <template v-if="row.display_expires_at && row.real_expires_at">
                                        {{ getTimeRemaining(row.display_expires_at) }} · {{ getTimeRemaining(row.real_expires_at) }}
                                    </template>
                                    <template v-else-if="row.display_expires_at">
                                        {{ getTimeRemaining(row.display_expires_at) }}
                                    </template>
                                    <template v-else>
                                        {{ getTimeRemaining(row.real_expires_at) }}
                                    </template>
                                </div>
                                <div class="pointer-events-none absolute bottom-full left-0 z-50 mb-2 w-64 whitespace-pre-line rounded-lg bg-gray-900 px-3 py-2 text-xs leading-relaxed text-white shadow-xl opacity-0 scale-95 transition-all duration-200 group-hover:opacity-100 group-hover:scale-100">
                                    {{ getTooltipText(row.display_expires_at, row.real_expires_at) }}
                                    <div class="absolute left-4 top-full -mt-1 h-2 w-2 rotate-45 bg-gray-900"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template #routing="{ row }">
                        <div v-if="hasInvalidRouting(row)" class="space-y-0.5">
                            <div class="flex items-center gap-1 text-red-600">
                                <AlertTriangle class="h-4 w-4" />
                                <span class="text-xs">Erreur</span>
                            </div>
                            <div v-if="row.current_endpoint" class="text-[11px] text-red-500">
                                {{ row.current_endpoint }}
                            </div>
                        </div>
                        <div v-else class="space-y-0.5">
                            <div class="flex items-center gap-1 text-green-600">
                                <Check class="h-4 w-4" />
                                <span class="text-xs">OK</span>
                            </div>
                            <div v-if="row.current_endpoint" class="text-[11px] text-gray-500">
                                {{ row.current_endpoint }}
                            </div>
                        </div>
                    </template>

                    <template #stats="{ row }">
                        <div class="group relative text-xs text-gray-600">
                            {{ row.total_assignments || 0 }} · {{ formatDuration(row.total_duration || 0) }}
                            <div class="pointer-events-none absolute bottom-full left-0 z-50 mb-2 w-48 rounded-lg bg-gray-900 px-3 py-2 text-xs leading-relaxed text-white shadow-xl opacity-0 scale-95 transition-all duration-200 group-hover:opacity-100 group-hover:scale-100">
                                <div>{{ row.total_assignments || 0 }} assignations</div>
                                <div>Durée totale : {{ formatDuration(row.total_duration || 0) }}</div>
                                <div class="absolute left-4 top-full -mt-1 h-2 w-2 rotate-45 bg-gray-900"></div>
                            </div>
                        </div>
                    </template>
                </DataTable>

                <div
                    v-if="table.data?.total"
                    class="flex items-center justify-between border-t border-gray-50 px-4 py-3"
                >
                    <div class="flex items-center gap-4">
                        <div class="text-sm text-gray-500">
                            Page {{ table.data.current_page }} sur {{ table.data.last_page }}
                            · {{ table.data.total.toLocaleString() }} résultat{{ table.data.total !== 1 ? 's' : '' }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Résultats par page :</span>
                            <select
                                v-model.number="filters.per_page"
                                class="rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="250">250</option>
                                <option :value="500">500</option>
                                <option :value="1000">1000</option>
                            </select>
                        </div>
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

        <!-- Barre d'actions en masse (sticky en bas) -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-full opacity-0"
        >
            <div
                v-if="selectedIds.length > 0"
                class="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 bg-white shadow-lg"
            >
                <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                    <div class="flex items-center gap-3">
                        <span class="font-medium text-gray-900">
                            {{ selectedIds.length }} numéro{{ selectedIds.length > 1 ? 's' : '' }} sélectionné{{ selectedIds.length > 1 ? 's' : '' }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="selectedIds = []"
                        >
                            Désélectionner tout
                        </Button>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="bulkRoute"
                            :disabled="isProcessing"
                            v-if="filters.only_deleted !== 'yes'"
                            class="text-blue-600 hover:text-blue-700"
                        >
                            <Zap class="mr-2 h-4 w-4" />
                            Router
                        </Button>

                        <Button
                            variant="outline"
                            size="sm"
                            @click="openSourceModal"
                            :disabled="isProcessing"
                            v-if="filters.only_deleted !== 'yes'"
                        >
                            <Edit3 class="mr-2 h-4 w-4" />
                            Modifier source dédiée
                        </Button>

                        <Button
                            variant="outline"
                            size="sm"
                            @click="bulkRestore"
                            :disabled="isProcessing"
                            v-if="filters.only_deleted === 'yes'"
                        >
                            <RefreshCw class="mr-2 h-4 w-4" />
                            Restaurer
                        </Button>

                        <Button
                            variant="outline"
                            size="sm"
                            @click="bulkDelete"
                            :disabled="isProcessing"
                            class="text-red-600 hover:text-red-700"
                            v-if="filters.only_deleted !== 'yes'"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Supprimer
                        </Button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Modal de modification de source dédiée -->
        <Dialog :open="showSourceModal" @update:open="closeSourceModal">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Modifier la source dédiée</DialogTitle>
                </DialogHeader>

                <div class="space-y-4 py-4">
                    <p class="text-sm text-gray-600">
                        Modifier la source dédiée pour {{ selectedIds.length }} numéro{{ selectedIds.length > 1 ? 's' : '' }}
                    </p>

                    <div class="space-y-2">
                        <label class="text-sm font-medium text-gray-700">
                            Nouvelle source dédiée
                        </label>
                        <select
                            v-model="selectedSourceId"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            <option value="">Aucune source (retirer la source dédiée)</option>
                            <option
                                v-for="source in filterOptions.data?.sources || []"
                                :key="source.value"
                                :value="source.value"
                            >
                                {{ source.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="closeSourceModal"
                        :disabled="isProcessing"
                    >
                        Annuler
                    </Button>
                    <Button
                        @click="bulkUpdateSource"
                        :disabled="isProcessing"
                    >
                        {{ isProcessing ? 'Modification...' : 'Modifier' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
