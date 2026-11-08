<script setup>
import { onMounted, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { refreshBrandRequests } from '@/composables/useBrandRequests'
import { xsrfToken } from '@/lib/csrf'

/**
 * Marques demandées : les marques que les agents n'ont pas trouvées dans le softphone.
 *   À traiter : une ligne par nom tapé (variantes regroupées), avec la marque existante la plus proche ; « Traiter »
 *   ouvre la page de la demande (appels, écoute, marques proches, création).
 *   Traitées : une ligne par appel, avec le résultat ; « Rouvrir » remet la demande à traiter.
 */
defineProps({ ignoreReasons: Object })

const tab = ref(new URLSearchParams(window.location.search).get('tab') === 'handled' ? 'handled' : 'pending')
const rows = ref([])
const loading = ref(true)
const error = ref(null)
async function load() {
    loading.value = true
    try {
        const res = await fetch(`/data/brand-requests?tab=${tab.value}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        rows.value = (await res.json()).items
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}
onMounted(load)
watch(tab, (t) => {
    history.replaceState(history.state, '', t === 'handled' ? '?tab=handled' : window.location.pathname)
    load()
})

const date = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-')

const pendingColumns = [
    { key: 'name', label: 'Nom tapé' },
    { key: 'calls', label: 'Appels' },
    { key: 'origin', label: 'Numéro appelé' },
    { key: 'last_at', label: 'Dernière demande' },
    { key: 'suggestion', label: 'Marque existante proche' },
    { key: 'actions', label: '' },
]
const handledColumns = [
    { key: 'name', label: 'Nom tapé' },
    { key: 'at', label: 'Appel' },
    { key: 'result', label: 'Résultat' },
    { key: 'handled_by', label: 'Par' },
    { key: 'handled_at', label: 'Le' },
    { key: 'actions', label: '' },
]

const reopening = ref(null)
async function reopen(row) {
    reopening.value = row.id
    const res = await fetch(`/data/brand-requests/${row.id}/reopen`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() } }).catch(() => null)
    reopening.value = null
    if (res?.ok) {
        refreshBrandRequests(true)
        load()
    }
}
</script>

<template>
    <Head title="Marques demandées" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées' }]" />

            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="t in [
                        { key: 'pending', label: 'À traiter' },
                        { key: 'handled', label: 'Traitées' },
                    ]"
                    :key="t.key"
                    type="button"
                    class="h-8 rounded-md px-3 text-xs font-medium transition-all"
                    :class="tab === t.key ? 'bg-gray-900 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-100'"
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <div class="rounded-lg border border-gray-100 bg-white">
                <DataTable v-if="tab === 'pending'" :columns="pendingColumns" :rows="rows" :loading="loading" empty-message="Aucune marque à traiter">
                    <template #name="{ row }">
                        <Link :href="`/brands/requests/${row.key}`" class="font-medium text-gray-900 hover:underline">{{ row.name }}</Link>
                        <span v-if="row.variants.length" class="ml-2 text-sm text-gray-500">{{ row.variants.join(', ') }}</span>
                    </template>
                    <template #calls="{ value }"><span class="text-sm tabular-nums text-gray-900">{{ value }}</span></template>
                    <template #origin="{ row }">
                        <span class="text-sm text-gray-600">
                            <template v-if="row.calls - row.support">118 500 : {{ row.calls - row.support }}</template>
                            <template v-if="row.calls - row.support && row.support"> · </template>
                            <template v-if="row.support">Support : {{ row.support }}</template>
                        </span>
                    </template>
                    <template #last_at="{ value }"><span class="text-sm whitespace-nowrap text-gray-600">{{ date(value) }}</span></template>
                    <template #suggestion="{ value }">
                        <span v-if="value" class="text-sm text-gray-700">{{ value.name }} <span class="text-gray-400">· {{ value.reason }}</span></span>
                        <span v-else class="text-sm text-gray-400">-</span>
                    </template>
                    <template #actions="{ row }">
                        <div class="text-right">
                            <Button size="sm" variant="outline" as-child>
                                <Link :href="`/brands/requests/${row.key}`">Traiter</Link>
                            </Button>
                        </div>
                    </template>
                </DataTable>

                <DataTable v-else :columns="handledColumns" :rows="rows" :loading="loading" empty-message="Aucune demande traitée">
                    <template #name="{ row }">
                        <span class="font-medium text-gray-900">{{ row.name }}</span>
                        <span class="ml-2 text-xs text-gray-500">{{ row.called }}</span>
                    </template>
                    <template #at="{ value }"><span class="text-sm whitespace-nowrap text-gray-600">{{ date(value) }}</span></template>
                    <template #result="{ row }">
                        <span class="text-sm text-gray-700">
                            <template v-if="row.status === 'created'">Marque créée : <Link :href="`/brands/${row.brand_id}/edit`" class="hover:underline">{{ row.brand_name }}</Link></template>
                            <template v-else-if="row.status === 'linked' && row.handled_by === 'softphone'">Marque existante, trouvée par le softphone : <Link :href="`/brands/${row.brand_id}/edit`" class="hover:underline">{{ row.brand_name }}</Link></template>
                            <template v-else-if="row.status === 'linked'">Marque existante : <Link :href="`/brands/${row.brand_id}/edit`" class="hover:underline">{{ row.brand_name }}</Link></template>
                            <template v-else>Ignorée : {{ row.ignore_reason }}</template>
                        </span>
                    </template>
                    <template #handled_by="{ value }"><span class="text-sm text-gray-600">{{ value || '-' }}</span></template>
                    <template #handled_at="{ value }"><span class="text-sm whitespace-nowrap text-gray-600">{{ date(value) }}</span></template>
                    <template #actions="{ row }">
                        <div class="text-right">
                            <Button size="sm" variant="ghost" :disabled="reopening === row.id" @click="reopen(row)">Rouvrir</Button>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
