<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { formatPhone } from '@/lib/calls'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'

// Fiche d'une marque. La table brands est lue par le poste des agents : une modification s'applique tout de suite.
const props = defineProps({
    brand: { type: Object, default: null },
    types: { type: Array, default: () => [] },
})

const isEdit = computed(() => !!props.brand)
const b = props.brand ?? {}

const form = useForm({
    name: b.name ?? '',
    type: b.type ?? '',
    country: b.country ?? 'FR',
    phonenumber: b.phonenumber ?? '',
    note: b.note ?? '',
    scenario: b.scenario ?? '',
    image: b.image ?? '',
    icon: b.icon ?? '',
    enabled: b.enabled ?? true,
    can_transfer: b.can_transfer ?? false,
    can_offer_callback: b.can_offer_callback ?? false,
    transfer_announcement: b.transfer_announcement ?? true,
})

const SWITCHES = [
    { key: 'enabled', label: 'Marque active' },
    { key: 'can_transfer', label: 'Transfert possible' },
    { key: 'transfer_announcement', label: 'Message avant transfert' },
    { key: 'can_offer_callback', label: 'Rappel pour une offre possible' },
]

// Modification sensible d'une marque existante, confirmée avant l'enregistrement :
//   - numéro de transfert : les transferts partent vers le nouveau numéro tout de suite ;
//   - nom : les appels passés gardent l'ancien nom (historique et stats retrouvent la marque par son nom).
const confirming = ref(false)
const phone = (n) => (n ? formatPhone(n) : 'aucun')
const changes = computed(() => {
    if (!isEdit.value) return []
    const list = []
    if ((form.phonenumber.trim() || null) !== (props.brand.phonenumber || null)) {
        list.push(`Numéro de transfert : ${phone(props.brand.phonenumber)} devient ${phone(form.phonenumber.trim())}. Les transferts partiront vers le nouveau numéro dès l'enregistrement.`)
    }
    if (form.name.trim() !== props.brand.name) {
        list.push(`Nom : « ${props.brand.name} » devient « ${form.name.trim()} ». Les appels passés gardent l'ancien nom.`)
    }
    return list
})

function submit() {
    // Les colonnes display_time et display_118500 ne servent plus : elles ne sont ni affichées ni envoyées
    const payload = (data) => ({ ...data, type: data.type || null, country: data.country || null, phonenumber: data.phonenumber.trim() || null, note: data.note || null, scenario: data.scenario || null, image: data.image || null, icon: data.icon || null })
    if (isEdit.value) {
        if (changes.value.length && !confirming.value) {
            confirming.value = true
            return
        }
        confirming.value = false
        form.transform(payload).put(`/brands/${props.brand.id}`)
    } else {
        form.transform(payload).post('/brands')
    }
}
</script>

<template>
    <Head :title="isEdit ? `Modifier ${brand.name}` : 'Nouvelle marque'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Marques', href: '/brands' }, { title: isEdit ? `Modifier ${brand.name}` : 'Nouvelle' }]">
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/brands"><ArrowLeft class="mr-2 h-4 w-4" /> Retour</Link>
                    </Button>
                </template>
            </PageHeader>

            <form class="space-y-6" @submit.prevent="submit">
                <Card>
                    <CardHeader><CardTitle>Informations</CardTitle></CardHeader>
                    <CardContent class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="name">Nom *</Label>
                            <Input id="name" v-model="form.name" required :class="{ 'border-red-500': form.errors.name }" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="phonenumber">Numéro de transfert</Label>
                            <Input id="phonenumber" v-model="form.phonenumber" placeholder="+33123456789" class="tabular-nums" :class="{ 'border-red-500': form.errors.phonenumber }" />
                            <p v-if="form.errors.phonenumber" class="text-sm text-red-600">{{ form.errors.phonenumber }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="type">Type</Label>
                            <Input id="type" v-model="form.type" list="brand-types" placeholder="service, bank, ecommerce…" />
                            <datalist id="brand-types">
                                <option v-for="t in types" :key="t" :value="t" />
                            </datalist>
                        </div>
                        <div class="space-y-2">
                            <Label for="country">Pays</Label>
                            <select id="country" v-model="form.country" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm">
                                <option value="FR">France</option>
                                <option value="BE">Belgique</option>
                                <option value="DE">Allemagne</option>
                                <option value="">—</option>
                            </select>
                        </div>
                        <div class="grid gap-3 md:col-span-2 md:grid-cols-3">
                            <div v-for="s in SWITCHES" :key="s.key" class="flex items-center gap-2">
                                <Switch :id="s.key" v-model:checked="form[s.key]" />
                                <Label :for="s.key">{{ s.label }}</Label>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Pour l'agent</CardTitle></CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="note">Note</Label>
                            <Textarea id="note" v-model="form.note" rows="3" />
                        </div>
                        <div class="space-y-2">
                            <Label for="scenario">Scénario</Label>
                            <Textarea id="scenario" v-model="form.scenario" rows="6" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Images</CardTitle></CardHeader>
                    <CardContent class="grid gap-4 md:grid-cols-2">
                        <div v-for="field in [{ key: 'image', label: 'Image (URL)' }, { key: 'icon', label: 'Icône (URL)' }]" :key="field.key" class="space-y-2">
                            <Label :for="field.key">{{ field.label }}</Label>
                            <div class="flex items-center gap-3">
                                <img v-if="form[field.key]" :key="form[field.key]" :src="form[field.key]" alt="" class="h-10 w-10 shrink-0 rounded object-cover" @error="(e) => (e.target.style.visibility = 'hidden')" />
                                <Input :id="field.key" v-model="form[field.key]" type="url" placeholder="https://…" :class="{ 'border-red-500': form.errors[field.key] }" />
                            </div>
                            <p v-if="form.errors[field.key]" class="text-sm text-red-600">{{ form.errors[field.key] }}</p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child><Link href="/brands">Annuler</Link></Button>
                    <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Enregistrement...' : isEdit ? 'Enregistrer' : 'Créer' }}</Button>
                </div>
            </form>
        </div>

        <Dialog v-model:open="confirming">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Enregistrer ces changements ?</DialogTitle>
                    <DialogDescription as="div">
                        <ul class="mt-1 list-disc space-y-1 pl-4">
                            <li v-for="c in changes" :key="c">{{ c }}</li>
                        </ul>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirming = false">Annuler</Button>
                    <Button @click="submit">Enregistrer</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
