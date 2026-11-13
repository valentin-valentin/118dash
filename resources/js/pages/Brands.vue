<script setup>
import { computed, onMounted } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Pencil, Phone, PhoneForwarded, Plus } from 'lucide-vue-next'
import { call as callNumber, normalizeNumber } from '@/composables/useSoftphone'
import AppLayout from '@/layouts/AppLayout.vue'
import BrandRequestsNotice from '@/components/BrandRequestsNotice.vue'
import DataTable from '@/components/DataTable.vue'
import FilterBar from '@/components/FilterBar.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import { Button } from '@/components/ui/button'
import { formatDuration, formatPhone } from '@/lib/calls'
import { Input } from '@/components/ui/input'
import { useApi } from '@/composables/useApi'
import { useFilters } from '@/composables/useFilters'

/**
 * Marques : entreprises vers lesquelles les agents transfèrent les appels.
 * « Vérifier les transferts » (en haut) ouvre la vérification des numéros de transfert, comme l'audit RSVA des numéros.
 */
defineProps({
    types: { type: Array, default: () => [] },
})

const stats = useApi('/data/brands/stats')
const table = useApi('/data/brands')

const { filters, reset } = useFilters(
    {
        search: '',
        enabled: '',
        type: '',
        country: '',
        phonenumber: '',
        can_transfer: '',
        transfer_announcement: '',
        sort: 'name',
        dir: 'asc',
        per_page: 50,
        page: 1,
    },
    (f) => table.load(f),
)

const hasFilters = computed(() => ['search', 'enabled', 'type', 'country', 'phonenumber', 'can_transfer', 'transfer_announcement'].some((k) => !!filters[k]))

function toggleSort(key) {
    if (filters.sort === key) filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'
    else Object.assign(filters, { sort: key, dir: 'asc' })
}

const columns = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'name', label: 'Nom', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'country', label: 'Pays', sortable: true },
    { key: 'phonenumber', label: 'Numéro de transfert' },
    { key: 'can_transfer', label: 'Transfert', sortable: true },
    { key: 'transfer_announcement', label: 'Message avant transfert' },
    // Nombre de transferts, et en dessous la durée de ceux arrivés à la vraie marque (sans ceux renvoyés au service interne)
    { key: 'transfers_30d', label: 'Transferts 30 j' },
    { key: 'enabled', label: 'Statut', sortable: true },
    { key: 'actions', label: '' },
]

const YES_NO = [
    { value: 'yes', label: 'Oui' },
    { value: 'no', label: 'Non' },
]

const phone = (n) => (n ? formatPhone(n) : '-')

// Appeler la marque avec le softphone (numéros français composables uniquement)
const softphoneEnabled = computed(() => !!usePage().props.softphone?.enabled)
const callable = (number) => softphoneEnabled.value && !!normalizeNumber(number ?? '')

onMounted(() => {
    stats.load()
    table.load(filters)
})
</script>

<template>
    <Head title="Marques" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques' }]">
                <template #actions>
                    <Button variant="outline" as-child>
                        <Link href="/brands/transfer-check">
                            <PhoneForwarded class="mr-2 h-4 w-4" />
                            Vérifier les transferts
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link href="/brands/create">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouvelle marque
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <BrandRequestsNotice />

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard label="Total" :value="stats.data?.total" :loading="stats.loading" />
                <StatCard label="Actives" :value="stats.data?.active" :loading="stats.loading" />
                <StatCard label="Inactives" :value="stats.data?.inactive" :loading="stats.loading" />
                <StatCard label="Sans numéro de transfert" :value="stats.data?.without_number" :loading="stats.loading" />
            </div>

            <FilterBar title="Filtres" :has-active-filters="hasFilters" :result-count="table.data?.total" :loading-results="table.loading" @reset="reset">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                    <Input v-model="filters.search" type="text" placeholder="Nom ou numéro..." class="h-8" />
                    <FilterSelect v-model="filters.enabled" :options="[{ value: 'yes', label: 'Actives' }, { value: 'no', label: 'Inactives' }]" placeholder="Tous les statuts" :searchable="false" />
                    <FilterSelect v-model="filters.type" :options="types.map((t) => ({ value: t, label: t }))" placeholder="Tous les types" searchable />
                    <FilterSelect v-model="filters.country" :options="[{ value: 'FR', label: 'France' }, { value: 'BE', label: 'Belgique' }, { value: 'DE', label: 'Allemagne' }]" placeholder="Tous les pays" :searchable="false" />
                    <FilterSelect v-model="filters.phonenumber" :options="[{ value: 'yes', label: 'Avec numéro' }, { value: 'no', label: 'Sans numéro' }]" placeholder="Numéro de transfert" :searchable="false" />
                    <FilterSelect v-model="filters.can_transfer" :options="YES_NO" placeholder="Transfert possible" :searchable="false" />
                    <FilterSelect v-model="filters.transfer_announcement" :options="YES_NO" placeholder="Message avant transfert" :searchable="false" />
                </div>
            </FilterBar>

            <div class="rounded-lg border border-gray-100 bg-white">
                <DataTable :columns="columns" :rows="table.data?.items ?? []" :loading="table.loading" :sort-key="filters.sort" :sort-dir="filters.dir" @sort="toggleSort">
                    <template #id="{ value }">
                        <span class="font-mono text-xs text-gray-500">#{{ value }}</span>
                    </template>
                    <template #name="{ row }">
                        <span class="font-medium text-gray-900">{{ row.name }}</span>
                    </template>
                    <template #type="{ value }"><span class="text-sm text-gray-600">{{ value || '-' }}</span></template>
                    <template #country="{ value }"><span class="text-sm text-gray-600">{{ value || '-' }}</span></template>
                    <template #phonenumber="{ value, row }">
                        <button
                            v-if="callable(value)"
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap text-gray-700 tabular-nums hover:text-gray-900"
                            :title="`Appeler ${row.name} avec le softphone`"
                            @click="callNumber(value)"
                        >
                            <Phone class="size-3.5 text-gray-400" />
                            {{ phone(value) }}
                        </button>
                        <span v-else class="text-sm whitespace-nowrap text-gray-700 tabular-nums">{{ phone(value) }}</span>
                    </template>
                    <template #can_transfer="{ value }"><span class="text-sm" :class="value ? 'text-gray-900' : 'text-gray-400'">{{ value ? 'Oui' : 'Non' }}</span></template>
                    <template #transfer_announcement="{ value }"><span class="text-sm" :class="value ? 'text-gray-900' : 'text-gray-400'">{{ value ? 'Oui' : 'Non' }}</span></template>
                    <template #transfers_30d="{ value, row }">
                        <div class="text-sm tabular-nums text-gray-700">{{ value ? value.toLocaleString('fr-FR') : '-' }}</div>
                        <div v-if="row.transfer_avg_30d != null" class="text-xs whitespace-nowrap tabular-nums text-gray-500">
                            vers la marque : moy. {{ formatDuration(row.transfer_avg_30d) }} · méd. {{ formatDuration(row.transfer_median_30d) }}
                        </div>
                    </template>
                    <template #enabled="{ value }">
                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium" :class="value ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'">
                            {{ value ? 'Active' : 'Inactive' }}
                        </span>
                    </template>
                    <template #actions="{ row }">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/brands/${row.id}/edit`" title="Modifier"><Pencil class="h-4 w-4" /></Link>
                        </Button>
                    </template>
                </DataTable>

                <div v-if="table.data?.total" class="flex items-center justify-between border-t border-gray-50 px-4 py-3">
                    <div class="text-sm text-gray-500">
                        Page {{ table.data.current_page }} sur {{ table.data.last_page }} · {{ table.data.total.toLocaleString('fr-FR') }} marque{{ table.data.total !== 1 ? 's' : '' }}
                    </div>
                    <div class="flex gap-2">
                        <Button v-if="table.data.current_page > 1" variant="outline" size="sm" @click="filters.page = (filters.page || 1) - 1">Précédent</Button>
                        <Button v-if="table.data.current_page < table.data.last_page" variant="outline" size="sm" @click="filters.page = (filters.page || 1) + 1">Suivant</Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
