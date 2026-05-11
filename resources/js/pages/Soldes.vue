<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Calculator, ArrowDownCircle, ArrowUpCircle, ArrowRightLeft } from 'lucide-vue-next'

// ─── Soldes par source ────────────────────────────────────────────────────────
const balances = useApi('/data/sources/balances')

const balancesSort = ref({ sort: 'solde', dir: 'desc' })

function loadBalances() {
    balances.load(balancesSort.value)
}

function toggleBalancesSort(key) {
    if (balancesSort.value.sort === key) {
        balancesSort.value.dir = balancesSort.value.dir === 'asc' ? 'desc' : 'asc'
    } else {
        balancesSort.value.sort = key
        balancesSort.value.dir = 'desc'
    }
    loadBalances()
}

const balancesColumns = [
    { key: 'name', label: 'Source', sortable: true },
    { key: 'solde', label: 'Solde', sortable: true },
    { key: 'last_payment_at', label: 'Dernier paiement', sortable: true },
    { key: 'actions', label: '' },
]

// ─── Historique des paiements ─────────────────────────────────────────────────
const payments = useApi('/data/source-payments')
const filterOptions = useApi('/data/source-payments/filter-options')

const { filters, reset } = useFilters(
    {
        source_id: [],
        type: '',
        search: '',
        from: '',
        to: '',
        sort: 'created_at',
        dir: 'desc',
        per_page: 50,
        page: 1,
    },
    (f) => payments.load(f),
)

const hasFilters = computed(() =>
    (Array.isArray(filters.source_id) && filters.source_id.length > 0) ||
    !!filters.type ||
    !!filters.search ||
    !!filters.from ||
    !!filters.to
)

function togglePaymentsSort(key) {
    if (filters.sort === key) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.sort = key
        filters.dir = 'desc'
    }
}

const paymentsColumns = [
    { key: 'created_at', label: 'Date', sortable: true },
    { key: 'source', label: 'Source' },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'amount', label: 'Montant', sortable: true },
    { key: 'description', label: 'Description' },
]

const typeOptions = [
    { id: 'credit', name: 'Credit' },
    { id: 'debit', name: 'Debit' },
]

// ─── Formatage ────────────────────────────────────────────────────────────────
function formatMoney(value) {
    const n = Number(value || 0)
    return n.toLocaleString('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' €'
}

function formatDate(value) {
    if (!value) return '-'
    const d = new Date(value)
    return d.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

// ─── Modal: Ajouter paiement ──────────────────────────────────────────────────
const showPaymentModal = ref(false)
const paymentForm = ref({
    source_id: '',
    type: 'debit',
    amount: '',
    description: '',
})
const paymentErrors = ref({})
const isSavingPayment = ref(false)

function openPaymentModal(sourceId = null) {
    paymentForm.value = {
        source_id: sourceId || '',
        type: 'debit',
        amount: '',
        description: '',
    }
    paymentErrors.value = {}
    showPaymentModal.value = true
}

async function submitPayment() {
    paymentErrors.value = {}

    if (!paymentForm.value.source_id) {
        paymentErrors.value.source_id = 'Source requise'
        return
    }
    if (!paymentForm.value.amount || Number(paymentForm.value.amount) <= 0) {
        paymentErrors.value.amount = 'Montant > 0 requis'
        return
    }

    isSavingPayment.value = true
    try {
        const res = await fetch('/data/source-payments', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                source_id: paymentForm.value.source_id,
                type: paymentForm.value.type,
                amount: Number(paymentForm.value.amount),
                description: paymentForm.value.description || null,
            }),
        })

        if (!res.ok) {
            if (res.status === 422) {
                const body = await res.json()
                paymentErrors.value = Object.fromEntries(
                    Object.entries(body.errors || {}).map(([k, v]) => [k, v[0]])
                )
                return
            }
            throw new Error('HTTP ' + res.status)
        }

        showPaymentModal.value = false
        loadBalances()
        payments.load(filters)
    } catch (e) {
        alert('Erreur lors de l\'enregistrement du paiement')
    } finally {
        isSavingPayment.value = false
    }
}

// ─── Modal: Transférer le solde ───────────────────────────────────────────────
const showTransferModal = ref(false)
const transferForm = ref({
    from_source_id: '',
    to_source_id: '',
    amount: '',
    description: '',
})
const transferErrors = ref({})
const isTransferring = ref(false)

const fromSourceForTransfer = computed(() => {
    if (!transferForm.value.from_source_id) return null
    return (balances.data?.items ?? []).find(s => s.id === transferForm.value.from_source_id) ?? null
})

const transferTargetOptions = computed(() => {
    const all = filterOptions.data?.sources ?? []
    if (!transferForm.value.from_source_id) return all
    return all.filter(s => s.id !== transferForm.value.from_source_id)
})

function openTransferModal(source) {
    transferForm.value = {
        from_source_id: source.id,
        to_source_id: '',
        amount: source.solde && Number(source.solde) > 0 ? Number(source.solde).toFixed(2) : '',
        description: '',
    }
    transferErrors.value = {}
    showTransferModal.value = true
}

async function submitTransfer() {
    transferErrors.value = {}

    if (!transferForm.value.to_source_id) {
        transferErrors.value.to_source_id = 'Source de destination requise'
        return
    }
    if (transferForm.value.to_source_id === transferForm.value.from_source_id) {
        transferErrors.value.to_source_id = 'Les sources doivent être différentes'
        return
    }
    if (!transferForm.value.amount || Number(transferForm.value.amount) <= 0) {
        transferErrors.value.amount = 'Montant > 0 requis'
        return
    }

    isTransferring.value = true
    try {
        const res = await fetch('/data/source-payments/transfer', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                from_source_id: transferForm.value.from_source_id,
                to_source_id: transferForm.value.to_source_id,
                amount: Number(transferForm.value.amount),
                description: transferForm.value.description || null,
            }),
        })

        if (!res.ok) {
            if (res.status === 422) {
                const body = await res.json()
                transferErrors.value = Object.fromEntries(
                    Object.entries(body.errors || {}).map(([k, v]) => [k, v[0]])
                )
                return
            }
            throw new Error('HTTP ' + res.status)
        }

        showTransferModal.value = false
        loadBalances()
        payments.load(filters)
    } catch (e) {
        alert('Erreur lors du transfert')
    } finally {
        isTransferring.value = false
    }
}

// ─── Modal: Recalculer le solde ───────────────────────────────────────────────
const showRecalcModal = ref(false)
const recalcLoading = ref(false)
const recalcData = ref(null)
const recalcSource = ref(null)
const isSyncing = ref(false)

async function openRecalcModal(source) {
    recalcSource.value = source
    recalcData.value = null
    recalcLoading.value = true
    showRecalcModal.value = true

    try {
        const res = await fetch(`/data/sources/${source.id}/recalculate`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        if (!res.ok) throw new Error('HTTP ' + res.status)
        recalcData.value = await res.json()
    } catch (e) {
        alert('Erreur lors du recalcul du solde')
        showRecalcModal.value = false
    } finally {
        recalcLoading.value = false
    }
}

async function syncSolde() {
    if (!recalcSource.value) return
    isSyncing.value = true
    try {
        const res = await fetch(`/data/sources/${recalcSource.value.id}/sync-solde`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        if (!res.ok) throw new Error('HTTP ' + res.status)
        showRecalcModal.value = false
        loadBalances()
    } catch (e) {
        alert('Erreur lors de la mise à jour du solde')
    } finally {
        isSyncing.value = false
    }
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    loadBalances()
    filterOptions.load()
    payments.load(filters)
})
</script>

<template>
    <Head title="Soldes" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Soldes' }
                ]"
            >
                <template #actions>
                    <Button @click="openPaymentModal()">
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau paiement
                    </Button>
                </template>
            </PageHeader>

            <!-- ─── Soldes par source ─────────────────────────────────────── -->
            <div>
                <h2 class="mb-3 text-base font-semibold text-gray-900">Soldes par source</h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable
                        :columns="balancesColumns"
                        :rows="balances.data?.items ?? []"
                        :loading="balances.loading"
                        :sort-key="balancesSort.sort"
                        :sort-dir="balancesSort.dir"
                        empty-message="Aucune source"
                        @sort="toggleBalancesSort"
                    >
                        <template #name="{ row }">
                            <ColorBadge :color="row.color" :label="row.name" />
                        </template>

                        <template #solde="{ value }">
                            <span
                                class="font-semibold tabular-nums text-gray-700"
                            >
                                {{ formatMoney(value) }}
                            </span>
                        </template>

                        <template #last_payment_at="{ value }">
                            <span class="text-sm text-gray-600">{{ formatDate(value) }}</span>
                        </template>

                        <template #actions="{ row }">
                            <div class="flex flex-wrap items-center gap-1.5 justify-end">
                                <Button
                                    size="sm"
                                    class="h-7 gap-1 px-2 text-xs"
                                    @click="openPaymentModal(row.id)"
                                >
                                    <Plus class="size-3" />
                                    Paiement
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-7 gap-1 px-2 text-xs"
                                    @click="openTransferModal(row)"
                                >
                                    <ArrowRightLeft class="size-3" />
                                    Transférer
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-7 gap-1 px-2 text-xs"
                                    @click="openRecalcModal(row)"
                                >
                                    <Calculator class="size-3" />
                                    Recalculer
                                </Button>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </div>

            <!-- ─── Historique des paiements ──────────────────────────────── -->
            <div>
                <h2 class="mb-3 text-base font-semibold text-gray-900">Historique des paiements</h2>

                <FilterBar
                    title="Filtres"
                    :has-active-filters="hasFilters"
                    :result-count="payments.data?.total"
                    :loading-results="payments.loading"
                    @reset="reset"
                >
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-5">
                        <FilterSelect
                            v-if="filterOptions.data?.sources"
                            v-model="filters.source_id"
                            :options="filterOptions.data.sources"
                            placeholder="Sources"
                            multiple
                            searchable
                        />

                        <FilterSelect
                            v-model="filters.type"
                            :options="typeOptions"
                            placeholder="Type"
                            :searchable="false"
                        />

                        <Input
                            v-model="filters.from"
                            type="date"
                            class="h-8"
                            placeholder="Depuis"
                        />

                        <Input
                            v-model="filters.to"
                            type="date"
                            class="h-8"
                            placeholder="Jusqu'à"
                        />

                        <Input
                            v-model="filters.search"
                            type="text"
                            placeholder="Rechercher description..."
                            class="h-8"
                        />
                    </div>
                </FilterBar>

                <div class="mt-4 rounded-lg border border-gray-100 bg-white">
                    <DataTable
                        :columns="paymentsColumns"
                        :rows="payments.data?.items ?? []"
                        :loading="payments.loading"
                        :sort-key="filters.sort"
                        :sort-dir="filters.dir"
                        empty-message="Aucun paiement"
                        @sort="togglePaymentsSort"
                    >
                        <template #created_at="{ value }">
                            <span class="text-sm text-gray-700">{{ formatDate(value) }}</span>
                        </template>

                        <template #source="{ row }">
                            <ColorBadge
                                v-if="row.source"
                                :color="row.source.color"
                                :label="row.source.name"
                            />
                            <span v-else class="text-sm text-gray-400">-</span>
                        </template>

                        <template #type="{ value }">
                            <span
                                v-if="value === 'credit'"
                                class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700"
                            >
                                <ArrowUpCircle class="h-3 w-3" />
                                Credit
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                            >
                                <ArrowDownCircle class="h-3 w-3" />
                                Debit
                            </span>
                        </template>

                        <template #amount="{ row }">
                            <span
                                class="font-medium tabular-nums"
                                :class="row.type === 'credit' ? 'text-green-700' : 'text-red-700'"
                            >
                                {{ row.type === 'credit' ? '+' : '−' }}{{ formatMoney(row.amount) }}
                            </span>
                        </template>

                        <template #description="{ value }">
                            <span class="text-sm text-gray-700">{{ value || '-' }}</span>
                        </template>
                    </DataTable>

                    <div
                        v-if="payments.data?.total"
                        class="flex items-center justify-between border-t border-gray-50 px-4 py-3"
                    >
                        <div class="text-sm text-gray-500">
                            Page {{ payments.data.current_page }} sur {{ payments.data.last_page }}
                            · {{ payments.data.total.toLocaleString() }} résultat{{ payments.data.total !== 1 ? 's' : '' }}
                        </div>
                        <div class="flex gap-2">
                            <Button
                                v-if="payments.data.current_page > 1"
                                variant="outline"
                                size="sm"
                                @click="filters.page = (filters.page || 1) - 1"
                            >
                                Précédent
                            </Button>
                            <Button
                                v-if="payments.data.current_page < payments.data.last_page"
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

            <!-- ─── Modal: Ajouter paiement ───────────────────────────────── -->
            <Dialog :open="showPaymentModal" @update:open="(val) => showPaymentModal = val">
                <DialogContent class="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nouveau paiement</DialogTitle>
                    </DialogHeader>

                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Source</label>
                            <FilterSelect
                                v-if="filterOptions.data?.sources"
                                v-model="paymentForm.source_id"
                                :options="filterOptions.data.sources"
                                placeholder="Choisir une source..."
                                searchable
                            />
                            <p v-if="paymentErrors.source_id" class="mt-1 text-xs text-red-600">
                                {{ paymentErrors.source_id }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Type</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    @click="paymentForm.type = 'debit'"
                                    class="flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors"
                                    :class="paymentForm.type === 'debit'
                                        ? 'border-red-500 bg-red-50 text-red-700'
                                        : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                                >
                                    <ArrowDownCircle class="h-4 w-4" />
                                    Debit
                                </button>
                                <button
                                    type="button"
                                    @click="paymentForm.type = 'credit'"
                                    class="flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors"
                                    :class="paymentForm.type === 'credit'
                                        ? 'border-green-500 bg-green-50 text-green-700'
                                        : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                                >
                                    <ArrowUpCircle class="h-4 w-4" />
                                    Credit
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Montant (€)</label>
                            <Input
                                v-model="paymentForm.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                            />
                            <p v-if="paymentErrors.amount" class="mt-1 text-xs text-red-600">
                                {{ paymentErrors.amount }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">
                                Description <span class="text-xs text-gray-400">(optionnel)</span>
                            </label>
                            <Input
                                v-model="paymentForm.description"
                                type="text"
                                maxlength="255"
                                placeholder=""
                            />
                            <p v-if="paymentErrors.description" class="mt-1 text-xs text-red-600">
                                {{ paymentErrors.description }}
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <Button variant="outline" @click="showPaymentModal = false">Annuler</Button>
                            <Button :disabled="isSavingPayment" @click="submitPayment">
                                {{ isSavingPayment ? 'Enregistrement...' : 'Enregistrer' }}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <!-- ─── Modal: Transférer le solde ────────────────────────────── -->
            <Dialog :open="showTransferModal" @update:open="(val) => showTransferModal = val">
                <DialogContent class="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Transférer le solde</DialogTitle>
                    </DialogHeader>

                    <div class="space-y-4">
                        <!-- Source d'origine (read-only) -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Depuis</label>
                            <div class="flex items-center justify-between rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                                <ColorBadge
                                    v-if="fromSourceForTransfer"
                                    :color="fromSourceForTransfer.color"
                                    :label="fromSourceForTransfer.name"
                                />
                                <span class="font-mono text-sm font-medium text-gray-700">
                                    Solde : {{ fromSourceForTransfer ? formatMoney(fromSourceForTransfer.solde) : '-' }}
                                </span>
                            </div>
                        </div>

                        <!-- Source de destination -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Vers</label>
                            <FilterSelect
                                v-model="transferForm.to_source_id"
                                :options="transferTargetOptions"
                                placeholder="Choisir une source de destination..."
                                searchable
                            />
                            <p v-if="transferErrors.to_source_id" class="mt-1 text-xs text-red-600">
                                {{ transferErrors.to_source_id }}
                            </p>
                        </div>

                        <!-- Montant -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">Montant (€)</label>
                            <Input
                                v-model="transferForm.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                            />
                            <p v-if="transferErrors.amount" class="mt-1 text-xs text-red-600">
                                {{ transferErrors.amount }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                Le solde de la source d'origine sera débité, celui de la destination sera crédité du même montant.
                            </p>
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-900">
                                Description <span class="text-xs text-gray-400">(optionnel)</span>
                            </label>
                            <Input
                                v-model="transferForm.description"
                                type="text"
                                maxlength="255"
                                placeholder="Motif du transfert"
                            />
                            <p v-if="transferErrors.description" class="mt-1 text-xs text-red-600">
                                {{ transferErrors.description }}
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <Button variant="outline" @click="showTransferModal = false">Annuler</Button>
                            <Button :disabled="isTransferring" @click="submitTransfer">
                                {{ isTransferring ? 'Transfert...' : 'Transférer' }}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <!-- ─── Modal: Recalculer le solde ────────────────────────────── -->
            <Dialog :open="showRecalcModal" @update:open="(val) => showRecalcModal = val">
                <DialogContent class="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            Recalcul du solde
                            <span v-if="recalcSource" class="text-sm font-normal text-gray-500">
                                · {{ recalcSource.name }}
                            </span>
                        </DialogTitle>
                    </DialogHeader>

                    <!-- Loader -->
                    <div v-if="recalcLoading" class="flex flex-col items-center justify-center py-10">
                        <svg class="h-8 w-8 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="mt-3 text-sm text-gray-600">Calcul en cours...</p>
                        <p class="mt-1 text-xs text-gray-400">Cette opération agrège des millions d'appels, ça peut prendre quelques secondes.</p>
                    </div>

                    <!-- Résultat -->
                    <div v-else-if="recalcData" class="space-y-4">
                        <!-- Détail du calcul -->
                        <div class="space-y-2 rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">+ Somme <code class="text-xs">payout_source</code> des calls</span>
                                <span class="font-mono font-medium text-gray-900">{{ formatMoney(recalcData.total_payout_calls) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">+ Total credits (source_payments)</span>
                                <span class="font-mono font-medium text-green-700">+ {{ formatMoney(recalcData.total_credits) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">− Total debits (source_payments)</span>
                                <span class="font-mono font-medium text-red-700">− {{ formatMoney(recalcData.total_debits) }}</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 text-sm font-semibold">
                                <span class="text-gray-900">= Solde théorique</span>
                                <span class="font-mono text-gray-900">{{ formatMoney(recalcData.expected_solde) }}</span>
                            </div>
                        </div>

                        <!-- Comparaison -->
                        <div class="space-y-2 rounded-lg border p-4"
                             :class="recalcData.matches ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">Solde actuel en base</span>
                                <span class="font-mono font-medium text-gray-900">{{ formatMoney(recalcData.current_solde) }}</span>
                            </div>
                            <div v-if="!recalcData.matches" class="flex items-center justify-between text-sm font-semibold">
                                <span class="text-red-700">Différence</span>
                                <span class="font-mono text-red-700">
                                    {{ recalcData.difference > 0 ? '+' : '' }}{{ formatMoney(recalcData.difference) }}
                                </span>
                            </div>
                            <div v-else class="text-sm font-medium text-green-700">
                                ✓ Le solde est correct
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <Button variant="outline" @click="showRecalcModal = false">Fermer</Button>
                            <Button
                                v-if="!recalcData.matches"
                                :disabled="isSyncing"
                                @click="syncSolde"
                            >
                                {{ isSyncing ? 'Mise à jour...' : 'Mettre à jour le solde avec la bonne valeur' }}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
