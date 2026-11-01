<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ColorBadge from '@/components/ColorBadge.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'
import { Plus, Pencil } from 'lucide-vue-next'
import { Switch } from '@/components/ui/switch'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { xsrfToken } from '@/lib/csrf'

// ─── Stats ────────────────────────────────────────────────────────────────────
const stats = useApi('/data/providers/stats')

// ─── Table + Filtres ──────────────────────────────────────────────────────────
const table = useApi('/data/providers')

const { filters, reset } = useFilters(
    {
        search: '',
        enabled: '',
        sort: 'name',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() =>
    !!filters.search ||
    !!filters.enabled
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
    { key: 'id', label: 'ID', sortable: true },
    { key: 'name', label: 'Nom', sortable: true },
    { key: 'payout', label: 'Payout', sortable: true },
    { key: 'enabled', label: 'Statut', sortable: true },
    { key: 'selfhosted', label: 'Routé chez nous' },
    { key: 'actions', label: 'Actions' },
]

// ─── « Routé chez nous » : config.selfhosted, lu par voxnode118 ───────────────
// Seuls les numéros des fournisseurs routés chez nous peuvent arriver sur notre serveur SIP.
const routing = ref(null) // { provider, enabled } en attente de confirmation
const routingBusy = ref(false)
const routingError = ref(null)
const isSelfhosted = (row) => !!row.config?.selfhosted

async function confirmRouting() {
    const { provider, enabled } = routing.value
    routingBusy.value = true
    routingError.value = null
    try {
        const res = await fetch(`/data/providers/${provider.id}/selfhosted`, {
            method: 'PUT',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ selfhosted: enabled }),
        })
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || `Erreur HTTP ${res.status}`)
        provider.config = { ...(provider.config ?? {}), selfhosted: enabled }
        routing.value = null
    } catch (e) {
        routingError.value = e.message
    } finally {
        routingBusy.value = false
    }
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Providers" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Providers' }
                ]"
            >
                <template #actions>
                    <Button as-child>
                        <Link href="/providers/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouveau provider
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Actifs" :value="stats.data?.active" :loading="stats.loading" />
                <StatCard label="Inactifs" :value="stats.data?.inactive" :loading="stats.loading" />
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
                    <!-- Recherche par nom -->
                    <Input
                        v-model="filters.search"
                        type="text"
                        placeholder="Rechercher par nom..."
                        class="h-8"
                    />

                    <!-- Statut -->
                    <FilterSelect
                        v-model="filters.enabled"
                        :options="[
                            { value: 'yes', label: 'Actifs' },
                            { value: 'no', label: 'Inactifs' },
                        ]"
                        placeholder="Tous les statuts"
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
                    @sort="toggleSort"
                >
                    <template #id="{ value }">
                        <span class="font-mono text-xs text-gray-500">#{{ value }}</span>
                    </template>

                    <template #name="{ row }">
                        <div class="flex items-center gap-2">
                            <ColorBadge :color="row.color" :label="row.name" />
                            <span class="text-xs font-medium text-gray-600">
                                {{ row.total_phonenumbers || 0 }} num.
                            </span>
                        </div>
                    </template>

                    <template #payout="{ value }">
                        <span class="text-sm text-gray-600">{{ value ? `${value} €` : '-' }}</span>
                    </template>

                    <template #enabled="{ value }">
                        <span
                            class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                            :class="value ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'"
                        >
                            {{ value ? 'Actif' : 'Inactif' }}
                        </span>
                    </template>

                    <template #selfhosted="{ row }">
                        <Switch
                            :checked="isSelfhosted(row)"
                            :aria-label="`${row.name} routé chez nous`"
                            @update:checked="(v) => { routing = { provider: row, enabled: v }; routingError = null }"
                        />
                    </template>

                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/providers/${row.id}/edit`">
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

        <!-- Confirmation : routage d'un fournisseur sur notre serveur SIP -->
        <Dialog :open="!!routing" @update:open="(open) => !open && !routingBusy && (routing = null)">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ routing?.enabled ? `Router ${routing?.provider.name} chez nous ?` : `Ne plus router ${routing?.provider.name} chez nous ?` }}</DialogTitle>
                    <DialogDescription class="text-gray-700">
                        <template v-if="routing?.enabled">
                            Ses numéros pourront être envoyés sur notre serveur SIP, dans la limite de la part réglée dans la configuration du serveur SIP.
                            À n'activer que pour un fournisseur dont les appels ont été testés chez nous.
                        </template>
                        <template v-else>
                            Ses numéros ne seront plus envoyés sur notre serveur SIP : ils restent chez Voxnode.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <p v-if="routingError" class="text-sm text-red-600">{{ routingError }}</p>
                <DialogFooter>
                    <Button variant="outline" :disabled="routingBusy" @click="routing = null">Annuler</Button>
                    <Button :variant="routing?.enabled ? 'default' : 'destructive'" :disabled="routingBusy" @click="confirmRouting">
                        {{ routing?.enabled ? 'Router chez nous' : 'Ne plus router' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
