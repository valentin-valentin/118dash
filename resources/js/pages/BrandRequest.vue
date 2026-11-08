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
import { refreshBrandRequests } from '@/composables/useBrandRequests'
import { xsrfToken } from '@/lib/csrf'

/**
 * Une marque demandée (tous les appels où les agents ont tapé ce nom, ou une variante) :
 *   1. Appels : écoute de la conversation
 *   2. Autres noms : ajoutés à la marque (brands.aliases, un par ligne), cherchés par le softphone
 *   3. Marques existantes proches : « Associer à cette marque » ajoute les autres noms à la marque et classe la demande
 *   4. Créer la marque ; ou « Marquer la demande comme traitée », sans créer ni associer de marque
 * Chaque action porte sur tous les appels de la demande.
 */
const props = defineProps({ nameKey: String, types: Array })

const data = ref(null)
const error = ref(null)
// Autres noms à ajouter à la marque : même champ que la fiche marque (un par ligne)
const aliasText = ref('')
async function load() {
    try {
        const res = await fetch(`/data/brand-requests/${props.nameKey}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        data.value = await res.json()
        error.value = null
        // Préremplis avec tous les noms tapés, un par ligne (ceux qui sont déjà le nom ou un autre nom de la marque sont ignorés à l'enregistrement)
        aliasText.value = data.value.names.map((n) => n.name).join('\n')
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
    { key: 'at', label: 'Date' },
    { key: 'called', label: 'Numéro appelé' },
    { key: 'caller', label: 'Appelant' },
    { key: 'agent', label: 'Agent' },
    { key: 'name', label: 'Nom tapé' },
    { key: 'record', label: 'Conversation' },
]

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
const aliases = computed(() => aliasText.value.split('\n').map((x) => x.trim()).filter(Boolean))
const ids = computed(() => data.value?.calls.map((c) => c.id) ?? [])

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
    refreshBrandRequests(true)
    await load()
    if (data.value && !data.value.calls.length) router.visit(data.value.next ? `/brands/requests/${data.value.next}` : '/brands/requests')
}
async function link(brandId) {
    if (await post('/data/brand-requests/link', { ids: ids.value, brand_id: brandId, aliases: aliases.value })) after()
}
const otherBrand = ref(null)
// Action principale (bouton noir) = la réponse la plus probable : associer quand une marque a exactement ce nom
// (sinon on créerait un doublon), créer sinon. Une seule action principale sur la page.
const identicalId = computed(() => (data.value?.suggestions?.[0]?.rank === 1 ? data.value.suggestions[0].id : null))

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
        ids: ids.value,
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

// Marquer comme traitée, sans créer ni associer de marque
async function ignore() {
    if (await post('/data/brand-requests/ignore', { ids: ids.value })) after()
}
</script>

<template>
    <Head :title="data?.name ? `${data.name} · Marques demandées` : 'Marque demandée'" />

    <AppLayout width="form">
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: 'Marques demandées', href: '/brands/requests' }, { title: data?.name ?? '…' }]">
            </PageHeader>

            <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

            <!-- ─── Appels ─── -->
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-900">
                    Appels <span class="ml-1 font-normal tabular-nums text-gray-400">{{ data?.calls.length ?? '' }}</span>
                </h2>
                <div class="rounded-lg border border-gray-100 bg-white">
                    <DataTable :columns="callColumns" :rows="data?.calls ?? []" :loading="!data && !error" empty-message="Aucun appel à traiter">
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

                <!-- ─── Autres noms : même champ que la fiche marque ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                        <div>
                            <Label for="br-aliases" class="text-gray-900">Autres noms</Label>
                            <p class="text-sm text-gray-500">Noms tapés par les agents, ajoutés à la marque créée ou existante. Un par ligne.</p>
                        </div>
                        <div>
                            <Textarea id="br-aliases" v-model="aliasText" rows="3" />
                        </div>
                    </div>
                </section>

                <!-- Marques proches et création : la réponse la plus probable en premier (proches s'il y en a, sinon création) -->
                <div class="flex flex-col gap-6">
                <!-- ─── Marques existantes proches ─── -->
                <section class="rounded-lg border border-gray-100 bg-white" :class="data.suggestions.length ? 'order-1' : 'order-2'">
                    <header class="border-b border-gray-100 px-5 py-3">
                        <h2 class="font-semibold text-gray-900">Marques existantes proches</h2>
                        <p class="text-sm text-gray-500">« Associer à cette marque » : les autres noms ci-dessus sont ajoutés à la marque, et la demande passe dans « Traitées » comme marque existante.</p>
                    </header>
                    <div class="divide-y divide-gray-50">
                        <div v-for="b in data.suggestions" :key="b.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <Link :href="`/brands/${b.id}/edit`" class="font-medium text-gray-900 hover:underline">{{ b.name }}</Link>
                            <span class="tabular-nums text-gray-600">{{ b.phonenumber || 'sans numéro' }}</span>
                            <span class="text-gray-500">{{ TYPE_LABELS[b.type] ?? b.type ?? '-' }}</span>
                            <span class="text-gray-500">· {{ b.reason }}<template v-if="b.via"> (autre nom « {{ b.via }} »)</template></span>
                            <Button type="button" size="sm" :variant="b.id === identicalId ? 'default' : 'outline'" class="ml-auto" :disabled="busy" @click="link(b.id)">Associer à cette marque</Button>
                        </div>
                        <p v-if="!data.suggestions.length" class="px-5 py-3 text-sm text-gray-500">Aucune marque proche</p>
                        <div class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                            <span class="text-gray-700">Une autre marque</span>
                            <div class="w-72"><FilterSelect v-model="otherBrand" :options="data.brands" placeholder="Chercher une marque" searchable /></div>
                            <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="busy || !otherBrand" @click="link(otherBrand)">Associer à cette marque</Button>
                        </div>
                    </div>
                </section>

                <!-- ─── Créer la marque ─── -->
                <form class="rounded-lg border border-gray-100 bg-white" :class="data.suggestions.length ? 'order-2' : 'order-1'" @submit.prevent="create">
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
                                    <a :href="googleUrl" target="_blank" rel="noopener"><Search class="mr-2 h-4 w-4" />Chercher sur Google<ExternalLink class="ml-2 h-3.5 w-3.5" /></a>
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
                                <select v-model="form.country" class="flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm w-40" aria-label="Pays">
                                    <option v-for="o in countryOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                                <select v-model="form.type" class="flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm w-56" aria-label="Catégorie">
                                    <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                            </div>
                        </div>
                        <div v-if="phonenumber" class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <span class="text-sm font-medium text-gray-900">Transfert</span>
                            <label class="flex items-center gap-2 text-sm text-gray-700"><Checkbox :model-value="form.canTransfer" @update:model-value="form.canTransfer = !!$event" />Les agents peuvent transférer l'appel vers ce numéro</label>
                        </div>
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]">
                            <Label for="br-note" class="pt-2 text-gray-900">Note pour les agents</Label>
                            <Textarea id="br-note" v-model="form.note" rows="2" placeholder="" />
                        </div>
                    </div>
                    <footer class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
                        <Button type="submit" size="sm" :variant="identicalId ? 'outline' : 'default'" :disabled="busy || !form.name || !phoneValid">Créer la marque</Button>
                    </footer>
                </form>
                </div>


                <!-- ─── Sans créer ni associer de marque ─── -->
                <section class="rounded-lg border border-gray-100 bg-white">
                    <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <!-- <p class="text-sm text-gray-500">Sans créer ni associer de marque.</p> -->
                        <Button type="button" size="sm" variant="outline" class="ml-auto" :disabled="busy" @click="ignore">Marquer la demande comme traitée</Button>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
