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
const generatedPartnerUrl = ref('')

const HASH_SALT = 'partner_stats_secure_salt_2024_voxnode'

// Simple MD5 implementation for browser
function md5(string) {
    function md5cycle(x, k) {
        let a = x[0], b = x[1], c = x[2], d = x[3]
        a = ff(a, b, c, d, k[0], 7, -680876936)
        d = ff(d, a, b, c, k[1], 12, -389564586)
        c = ff(c, d, a, b, k[2], 17, 606105819)
        b = ff(b, c, d, a, k[3], 22, -1044525330)
        a = ff(a, b, c, d, k[4], 7, -176418897)
        d = ff(d, a, b, c, k[5], 12, 1200080426)
        c = ff(c, d, a, b, k[6], 17, -1473231341)
        b = ff(b, c, d, a, k[7], 22, -45705983)
        a = ff(a, b, c, d, k[8], 7, 1770035416)
        d = ff(d, a, b, c, k[9], 12, -1958414417)
        c = ff(c, d, a, b, k[10], 17, -42063)
        b = ff(b, c, d, a, k[11], 22, -1990404162)
        a = ff(a, b, c, d, k[12], 7, 1804603682)
        d = ff(d, a, b, c, k[13], 12, -40341101)
        c = ff(c, d, a, b, k[14], 17, -1502002290)
        b = ff(b, c, d, a, k[15], 22, 1236535329)
        a = gg(a, b, c, d, k[1], 5, -165796510)
        d = gg(d, a, b, c, k[6], 9, -1069501632)
        c = gg(c, d, a, b, k[11], 14, 643717713)
        b = gg(b, c, d, a, k[0], 20, -373897302)
        a = gg(a, b, c, d, k[5], 5, -701558691)
        d = gg(d, a, b, c, k[10], 9, 38016083)
        c = gg(c, d, a, b, k[15], 14, -660478335)
        b = gg(b, c, d, a, k[4], 20, -405537848)
        a = gg(a, b, c, d, k[9], 5, 568446438)
        d = gg(d, a, b, c, k[14], 9, -1019803690)
        c = gg(c, d, a, b, k[3], 14, -187363961)
        b = gg(b, c, d, a, k[8], 20, 1163531501)
        a = gg(a, b, c, d, k[13], 5, -1444681467)
        d = gg(d, a, b, c, k[2], 9, -51403784)
        c = gg(c, d, a, b, k[7], 14, 1735328473)
        b = gg(b, c, d, a, k[12], 20, -1926607734)
        a = hh(a, b, c, d, k[5], 4, -378558)
        d = hh(d, a, b, c, k[8], 11, -2022574463)
        c = hh(c, d, a, b, k[11], 16, 1839030562)
        b = hh(b, c, d, a, k[14], 23, -35309556)
        a = hh(a, b, c, d, k[1], 4, -1530992060)
        d = hh(d, a, b, c, k[4], 11, 1272893353)
        c = hh(c, d, a, b, k[7], 16, -155497632)
        b = hh(b, c, d, a, k[10], 23, -1094730640)
        a = hh(a, b, c, d, k[13], 4, 681279174)
        d = hh(d, a, b, c, k[0], 11, -358537222)
        c = hh(c, d, a, b, k[3], 16, -722521979)
        b = hh(b, c, d, a, k[6], 23, 76029189)
        a = hh(a, b, c, d, k[9], 4, -640364487)
        d = hh(d, a, b, c, k[12], 11, -421815835)
        c = hh(c, d, a, b, k[15], 16, 530742520)
        b = hh(b, c, d, a, k[2], 23, -995338651)
        a = ii(a, b, c, d, k[0], 6, -198630844)
        d = ii(d, a, b, c, k[7], 10, 1126891415)
        c = ii(c, d, a, b, k[14], 15, -1416354905)
        b = ii(b, c, d, a, k[5], 21, -57434055)
        a = ii(a, b, c, d, k[12], 6, 1700485571)
        d = ii(d, a, b, c, k[3], 10, -1894986606)
        c = ii(c, d, a, b, k[10], 15, -1051523)
        b = ii(b, c, d, a, k[1], 21, -2054922799)
        a = ii(a, b, c, d, k[8], 6, 1873313359)
        d = ii(d, a, b, c, k[15], 10, -30611744)
        c = ii(c, d, a, b, k[6], 15, -1560198380)
        b = ii(b, c, d, a, k[13], 21, 1309151649)
        a = ii(a, b, c, d, k[4], 6, -145523070)
        d = ii(d, a, b, c, k[11], 10, -1120210379)
        c = ii(c, d, a, b, k[2], 15, 718787259)
        b = ii(b, c, d, a, k[9], 21, -343485551)
        x[0] = add32(a, x[0])
        x[1] = add32(b, x[1])
        x[2] = add32(c, x[2])
        x[3] = add32(d, x[3])
    }
    function cmn(q, a, b, x, s, t) {
        a = add32(add32(a, q), add32(x, t))
        return add32((a << s) | (a >>> (32 - s)), b)
    }
    function ff(a, b, c, d, x, s, t) { return cmn((b & c) | ((~b) & d), a, b, x, s, t) }
    function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & (~d)), a, b, x, s, t) }
    function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t) }
    function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | (~d)), a, b, x, s, t) }
    function md51(s) {
        let n = s.length
        let state = [1732584193, -271733879, -1732584194, 271733878]
        let i
        for (i = 64; i <= s.length; i += 64) {
            md5cycle(state, md5blk(s.substring(i - 64, i)))
        }
        s = s.substring(i - 64)
        let tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]
        for (i = 0; i < s.length; i++)
            tail[i >> 2] |= s.charCodeAt(i) << ((i % 4) << 3)
        tail[i >> 2] |= 0x80 << ((i % 4) << 3)
        if (i > 55) {
            md5cycle(state, tail)
            for (i = 0; i < 16; i++) tail[i] = 0
        }
        tail[14] = n * 8
        md5cycle(state, tail)
        return state
    }
    function md5blk(s) {
        let md5blks = []
        for (let i = 0; i < 64; i += 4) {
            md5blks[i >> 2] = s.charCodeAt(i) + (s.charCodeAt(i + 1) << 8) + (s.charCodeAt(i + 2) << 16) + (s.charCodeAt(i + 3) << 24)
        }
        return md5blks
    }
    const hex_chr = '0123456789abcdef'.split('')
    function rhex(n) {
        let s = ''
        for (let j = 0; j < 4; j++)
            s += hex_chr[(n >> (j * 8 + 4)) & 0x0F] + hex_chr[(n >> (j * 8)) & 0x0F]
        return s
    }
    function hex(x) {
        for (let i = 0; i < x.length; i++)
            x[i] = rhex(x[i])
        return x.join('')
    }
    function add32(a, b) {
        return (a + b) & 0xFFFFFFFF
    }
    return hex(md51(string))
}

function generatePartnerHash(sources) {
    const str = sources + HASH_SALT
    return md5(str)
}

function openPartnerUrlModal() {
    selectedSourceIds.value = []
    generatedPartnerUrl.value = ''
    showPartnerUrlModal.value = true
}

function generateUrl() {
    if (selectedSourceIds.value.length === 0) return

    const sortedIds = [...selectedSourceIds.value].sort((a, b) => a - b)
    const sourcesParam = sortedIds.join(',')
    const hash = generatePartnerHash(sourcesParam)

    // Utiliser window.location.origin ou construire l'URL avec le protocol et host
    const baseUrl = window.location.origin || `${window.location.protocol}//${window.location.host}`
    generatedPartnerUrl.value = `${baseUrl}/partners/${sourcesParam}/${hash}`
}

function copyToClipboard() {
    navigator.clipboard.writeText(generatedPartnerUrl.value)
        .then(() => {
            alert('URL copiée dans le presse-papier!')
        })
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
                <DialogContent class="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Générer URL Partenaire</DialogTitle>
                    </DialogHeader>

                    <div class="space-y-4">
                        <!-- Sélection des sources -->
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-3">Sélectionnez les sources :</p>
                            <div class="space-y-2 max-h-96 overflow-y-auto border border-gray-200 rounded-md p-3">
                                <div
                                    v-for="source in table.data?.items ?? []"
                                    :key="source.id"
                                    class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                                    @click="() => {
                                        const index = selectedSourceIds.indexOf(source.id)
                                        if (index > -1) {
                                            selectedSourceIds.splice(index, 1)
                                        } else {
                                            selectedSourceIds.push(source.id)
                                        }
                                    }"
                                >
                                    <Checkbox
                                        :id="`source-${source.id}`"
                                        :checked="selectedSourceIds.includes(source.id)"
                                    />
                                    <div class="flex items-center gap-2 flex-1">
                                        <ColorBadge :color="source.color" :label="source.name" />
                                        <span class="text-xs text-gray-500">#{{ source.id }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bouton générer -->
                        <div class="flex justify-end">
                            <Button
                                :disabled="selectedSourceIds.length === 0"
                                @click="generateUrl"
                            >
                                Générer l'URL
                            </Button>
                        </div>

                        <!-- URL générée -->
                        <div v-if="generatedPartnerUrl" class="space-y-2">
                            <p class="text-sm font-medium text-gray-700">URL générée :</p>
                            <div class="flex gap-2">
                                <Input
                                    :value="generatedPartnerUrl"
                                    readonly
                                    class="font-mono text-xs"
                                />
                                <Button variant="outline" @click="copyToClipboard">
                                    <Copy class="h-4 w-4" />
                                </Button>
                            </div>
                            <p class="text-xs text-gray-500">
                                Cette URL donne accès aux statistiques de {{ selectedSourceIds.length }} source{{ selectedSourceIds.length > 1 ? 's' : '' }}.
                            </p>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
