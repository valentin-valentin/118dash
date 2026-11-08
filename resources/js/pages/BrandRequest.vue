<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DataTable from '@/components/DataTable.vue'
import FilterSelect from '@/components/FilterSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { ExternalLink, Pause, Phone, Play, Search } from 'lucide-vue-next'
import { call as softphoneCall } from '@/composables/useSoftphone'
import { xsrfToken } from '@/lib/csrf'

/**
 * Une marque demandée (tous les appels où les agents ont tapé ce nom, ou une variante) :
 *   1. Appels : écoute de la conversation, cases à cocher (tout est coché ; décocher ceux qui visent une autre marque)
 *   2. Noms tapés : ceux à ajouter aux autres noms de la marque (recherche du softphone)
 *   3. Marques existantes proches : « C'est celle-ci » relie les appels cochés (l'agent ne l'a pas trouvée)
 *   4. Créer la marque, ou ignorer les appels cochés
 */
const props = defineProps({ nameKey: String, ignoreReasons: Object, types: Array })

const data = ref(null)
const error = ref(null)
const selected = ref([])
const aliasChoice = reactive({})
async function load() {
    try {
        const res = await fetch(`/data/brand-requests/${props.nameKey}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
        selected.value = data.value.calls.map((c) => c.id)
        // Noms tapés ajoutés aux autres noms : ceux que la recherche du softphone ne trouve pas déjà
        for (const n of data.value.names) aliasChoice[n.name] = !n.found
        if (!form.name) form.name = titleCase(data.value.name ?? '')
    } catch (e) {
        error.value = e.message
    }
}
onMounted(load)

const titleCase = (s) => s.replace(/\S+/g, (w) => (w.length > 1 && w === w.toUpperCase() ? w : w.charAt(0).toUpperCase() + w.slice(1)))
const date = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-')
const mmss = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`)

// ─── Appels ───────────────────────────────────────────────────────────────────
const callColumns = [
    { key: 'select', label: '' },
    { key: 'at', label: 'Date' },
    { key: 'called', label: 'Numéro appelé' },
    { key: 'caller', label: 'Appelant' },
    { key: 'agent', label: 'Agent' },
    { key: 'name', label: 'Nom tapé' },
    { key: 'record', label: 'Conversation' },
]
const allSelected = computed(() => data.value && selected.value.length === data.value.calls.length)
function toggle(id, on) {
    selected.value = on ? [...new Set([...selected.value, id])] : selected.value.filter((x) => x !== id)
}
function toggleAll(on) {
    selected.value = on ? data.value.calls.map((c) => c.id) : []
}

// Écoute de la conversation avec l'agent
const audio = typeof Audio !== 'undefined' ? new Audio() : null
const playing = ref(null)
if (audio) audio.addEventListener('ended', () => (playing.value = null))
function play(row) {
    if (!audio || !row.record) return
    if (playing.value === row.id) {
        audio.pause()
        playing.value = null
        return
    }
    audio.src = row.record
    audio.play().catch(() => (playing.value = null))
    playing.value = row.id
}
onUnmounted(() => audio?.pause())

// ─── Noms tapés → autres noms de la marque ────────────────────────────────────
const aliases = computed(() => Object.entries(aliasChoice).filter(([, on]) => on).map(([name]) => name))

// ─── Actions ──────────────────────────────────────────────────────────────────
const busy = ref(false)
const actionError = ref(null)
async function post(url, body) {
    busy.value = true
    actionError.value = null
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify(body),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.errors ? Object.values(json.errors).flat()[0] : json.message || `Erreur HTTP ${res.status}`)
        return json
    } catch (e) {
        actionError.value = e.message
        return null
    } finally {
        busy.value = false
    }
}
// Après une action : la suite des appels de cette demande, ou la demande suivante, ou la liste
async function after() {
    await load()
    if (data.value && !data.value.calls.length) router.visit(data.value.next ? `/brands/requests/${data.value.next}` : '/brands/requests')
}
async function link(brandId) {
    if (await post('/data/brand-requests/link', { ids: selected.value, brand_id: brandId, aliases: aliases.value })) after()
}
const otherBrand = ref(null)

// Création
const COUNTRY = { 33: 'FR', 32: 'BE', 49: 'DE' }
const TYPE_LABELS = {
    service: 'Service',
    ecommerce: 'E-commerce',
    travel: 'Voyage',
    bank: 'Banque',
    insurance: 'Assurance',
    public: 'Service public',
    utility: 'Énergie et eau',
    package: 'Colis',
    telecom: 'Télécom',
    car: 'Automobile',
    transport: 'Transport',
    social: 'Réseau social',
    food: 'Alimentation',
    multimedia: 'Multimédia',
}
const typeOptions = computed(() => props.types.map((t) => ({ value: t, label: TYPE_LABELS[t] ?? t })))
const countryOptions = [
    { value: 'FR', label: 'France' },
    { value: 'BE', label: 'Belgique' },
    { value: 'DE', label: 'Allemagne' },
]
const form = reactive({ name: '', phone: '', country: 'FR', type: 'service', canTransfer: true, note: '' })
// Numéro saisi librement (01 23…, 0033…, +33…) → +33…
const phonenumber = computed(() => {
    const d = form.phone.replace(/[^\d+]/g, '')
    if (!d) return ''
    if (d.startsWith('+')) return d
    if (d.startsWith('00')) return `+${d.slice(2)}`
    if (d.startsWith('0')) return `+33${d.slice(1)}`
    return d
})
const phoneValid = computed(() => !phonenumber.value || /^\+(33|32|49)\d{8,11}$/.test(phonenumber.value))
const premium = computed(() => /^\+3389/.test(phonenumber.value))
// Pays déduit du numéro (modifiable)
watch(phonenumber, (n) => {
    const code = n.match(/^\+(33|32|49)/)?.[1]
    if (code) form.country = COUNTRY[code]
})
// Une autre marque a déjà ce numéro (les appels sont reliés aux marques par leur numéro)
const sameNumber = ref([])
let phoneTimer = null
watch(phonenumber, (n) => {
    clearTimeout(phoneTimer)
    sameNumber.value = []
    if (!n || !phoneValid.value) return
    phoneTimer = setTimeout(async () => {
        const res = await fetch(`/data/brand-requests/phone-check?phonenumber=${encodeURIComponent(n)}`, { headers: { Accept: 'application/json' } }).catch(() => null)
        if (res?.ok) sameNumber.value = (await res.json()).brands
    }, 400)
})
const googleUrl = computed(() => `https://www.google.com/search?q=${encodeURIComponent(`${form.name || data.value?.name || ''} numéro de téléphone service client contact`)}`)
async function create() {
    const json = await post('/data/brand-requests/create', {
        ids: selected.value,
        name: form.name,
        phonenumber: phonenumber.value || null,
        country: form.country,
        type: form.type,
        can_transfer: !!phonenumber.value && form.canTransfer,
        note: form.note || null,
        aliases: aliases.value,
    })
    if (json) {
        Object.assign(form, { name: '', phone: '', note: '' })
        after()
    }
}

// Ignorer
const ignoreReason = ref(null)
const reasonOptions = computed(() => Object.entries(props.ignoreReasons).map(([value, label]) => ({ value, label })))
async function ignore() {
    if (await post('/data/brand-requests/ignore', { ids: selected.value, reason: ignoreReason.value })) {
        ignoreReason.value = null
        after()
    }
}
</script>

<template>
    <Head :title="data?.name ? `${data.name} · Marques demandées` : 'Marque demandée'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées', href: '/brands/requests' }, { title: data?.name ?? '…' }]">
                <template v-if="data?.next" #actions>
                    <Button variant="outline" as-child>
                        <Link :href="`/brands/requests/${data.next}`">Demande suivante</Link>
                    </Button>
                </template>
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── Appels ─── -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data?.calls.length ?? '' }}</span>
                    <span class="ml-2 font-normal text-gray-500">les actions ci-dessous portent sur les appels cochés</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="callColumns" :rows="data?.calls ?? []" :loading="!data && !error" empty-message="Aucun appel à traiter">
                        <template #header-select>
                            <Checkbox v-if="data?.calls.length" :model-value="allSelected" aria-label="Tout cocher" @update:model-value="toggleAll(!!$event)" />
                        </template>
                        <template #select="{ row }">
                            <Checkbox :model-value="selected.includes(row.id)" :aria-label="`Cocher l'appel du ${date(row.at)}`" @update:model-value="toggle(row.id, !!$event)" />
                        </template>
                        <template #at="{ value }"><span class="text-sm whitespace-nowrap text-gray-700">{{ date(value) }}</span></template>
                        <template #called="{ value }"><span class="text-sm text-gray-700">{{ value || '-' }}</span></template>
                        <template #caller="{ value }"><span class="font-mono text-sm text-gray-600">{{ value || '-' }}</span></template>
                        <template #agent="{ value }"><span class="text-sm text-gray-700">{{ value || '-' }}</span></template>
                        <template #name="{ value }"><span class="text-sm text-gray-900">{{ value }}</span></template>
                        <template #record="{ row }">
                            <button
                                v-if="row.record"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                :class="playing === row.id ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                                @click="play(row)"
                            >
                                <Pause v-if="playing === row.id" class="size-3.5" />
                                <Play v-else class="size-3.5" />
                                {{ mmss(row.duration) }}
                            </button>
                            <span v-else class="text-sm text-gray-400">-</span>
                        </template>
                    </DataTable>
                </div>
            </section>

            <template v-if="data?.calls.length">
                <p v-if="actionError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ actionError }}</p>

                <!-- ─── Noms tapés : autres noms de la marque ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="font-semibold text-gray-900">Noms tapés par les agents</h2>
                        <p class="text-sm text-gray-500">Ceux qui sont cochés deviennent des autres noms de la marque, cherchés par le softphone.</p>
                    </header>
                    <div class="divide-y divide-gray-50">
                        <label v-for="n in data.names" :key="n.name" class="flex items-center gap-3 px-5 py-3 text-sm">
                            <Checkbox :model-value="aliasChoice[n.name]" @update:model-value="aliasChoice[n.name] = !!$event" />
                            <span class="text-gray-900">{{ n.name }}</span>
                            <span class="text-gray-500">{{ n.calls }} appel{{ n.calls > 1 ? 's' : '' }}</span>
                            <span v-if="n.found" class="text-gray-500">· déjà trouvé par la recherche du softphone</span>
                        </label>
                    </div>
                </section>

                <!-- ─── Marques existantes proches ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="font-semibold text-gray-900">Marques existantes proches</h2>
                        <p class="text-sm text-gray-500">Si c'est l'une d'elles, les appels cochés lui sont reliés : l'agent ne l'avait pas trouvée.</p>
                    </header>
                    <div class="divide-y divide-gray-50">
                        <div v-for="b in data.suggestions" :key="b.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <Link :href="`/brands/${b.id}/edit`" class="font-medium text-gray-900 hover:underline">{{ b.name }}</Link>
                            <span class="tabular-nums text-gray-600">{{ b.phonenumber || 'sans numéro' }}</span>
                            <span class="text-gray-500">{{ TYPE_LABELS[b.type] ?? b.type ?? '-' }}</span>
                            <span class="text-gray-500">· {{ b.reason }}<template v-if="b.via"> (autre nom « {{ b.via }} »)</template></span>
                            <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="busy || !selected.length" @click="link(b.id)">C'est celle-ci</Button>
                        </div>
                        <p v-if="!data.suggestions.length" class="px-5 py-3 text-sm text-gray-500">Aucune marque proche</p>
                        <div class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                            <span class="text-gray-700">Une autre marque</span>
                            <div class="w-72"><FilterSelect v-model="otherBrand" :options="data.brands" placeholder="Chercher une marque" searchable /></div>
                            <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="busy || !otherBrand || !selected.length" @click="link(otherBrand)">C'est celle-ci</Button>
                        </div>
                    </div>
                </section>

                <!-- ─── Créer la marque ─── -->
                <form class="rounded-lg border border-gray-100 bg-white" @submit.prevent="create">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="font-semibold text-gray-900">Créer la marque</h2>
                        <p class="text-sm text-gray-500">Activée tout de suite et visible des agents. Le reste se complète ensuite dans la fiche de la marque.</p>
                    </header>
                    <div class="divide-y divide-gray-50">
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <Label for="br-name" class="pt-2 text-gray-900">Nom</Label>
                            <div class="flex flex-wrap items-center gap-2">
                                <Input id="br-name" v-model="form.name" class="h-9 w-72" required />
                                <Button type="button" size="sm" variant="outline" as-child>
                                    <a :href="googleUrl" target="_blank" rel="noopener"><Search class="mr-2 h-4 w-4" />Chercher le service client sur Google<ExternalLink class="ml-2 h-3.5 w-3.5" /></a>
                                </Button>
                            </div>
                        </div>
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <Label for="br-phone" class="pt-2 text-gray-900">Numéro du service client</Label>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Input id="br-phone" v-model="form.phone" class="h-9 w-56 tabular-nums" placeholder="01 23 45 67 89" />
                                    <span v-if="phonenumber && phoneValid" class="text-sm tabular-nums text-gray-500">{{ phonenumber }}</span>
                                    <Button v-if="phonenumber && phoneValid" type="button" size="sm" variant="outline" @click="softphoneCall(phonenumber)"><Phone class="mr-2 h-4 w-4" />Appeler</Button>
                                </div>
                                <p v-if="!phoneValid" class="mt-1 text-sm text-red-600">Numéro français, belge ou allemand attendu.</p>
                                <p v-if="premium" class="mt-1 text-sm text-amber-700">Numéro surtaxé (08 9).</p>
                                <p v-if="sameNumber.length" class="mt-1 text-sm text-red-600">
                                    Déjà le numéro de
                                    <template v-for="(b, i) in sameNumber" :key="b.id">{{ i ? ', ' : '' }}<Link :href="`/brands/${b.id}/edit`" class="underline">{{ b.name }}</Link></template>
                                    : les appels des deux marques seraient mélangés.
                                </p>
                            </div>
                        </div>
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <span class="pt-2 text-sm font-medium text-gray-900">Pays et catégorie</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="w-40"><FilterSelect v-model="form.country" :options="countryOptions" placeholder="Pays" :searchable="false" /></div>
                                <div class="w-56"><FilterSelect v-model="form.type" :options="typeOptions" placeholder="Catégorie" :searchable="false" /></div>
                            </div>
                        </div>
                        <div v-if="phonenumber" class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <span class="text-sm font-medium text-gray-900">Transfert</span>
                            <label class="flex items-center gap-2 text-sm text-gray-700"><Checkbox :model-value="form.canTransfer" @update:model-value="form.canTransfer = !!$event" />Les agents peuvent transférer l'appel vers ce numéro</label>
                        </div>
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <Label for="br-note" class="pt-2 text-gray-900">Note pour les agents</Label>
                            <Textarea id="br-note" v-model="form.note" rows="2" placeholder="Horaires, particularités…" />
                        </div>
                    </div>
                    <footer class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                        <Button type="submit" size="sm" :disabled="busy || !selected.length || !form.name || !phoneValid">Créer la marque</Button>
                    </footer>
                </form>

                <!-- ─── Ignorer ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <div class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                        <span class="text-sm font-medium text-gray-900">Ignorer les appels cochés</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="w-56"><FilterSelect v-model="ignoreReason" :options="reasonOptions" placeholder="Motif" :searchable="false" /></div>
                            <Button type="button" size="sm" variant="outline" :disabled="busy || !ignoreReason || !selected.length" @click="ignore">Ignorer</Button>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
