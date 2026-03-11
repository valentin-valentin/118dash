<script setup>
import { ref, computed, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ColorPicker from '@/components/ColorPicker.vue'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { ArrowLeft, Plus, Trash2, AlertCircle } from 'lucide-vue-next'

const props = defineProps({
    source: {
        type: Object,
        default: null,
    },
    availableProviderCompanies: {
        type: Array,
        required: true,
    },
})

const isEdit = computed(() => !!props.source)

// Préparer les associations existantes
const initialAssociations = props.source?.source_provider_companies?.map(spc => ({
    providers_companies_id: spc.providers_companies_id,
    weight: spc.weight,
    payout: spc.payout || '',
})) || []

const enableMaxConcurrent = ref(!!props.source?.max_concurrent_numbers)

const form = useForm({
    name: props.source?.name || '',
    api_key: props.source?.api_key || '',
    fingerprint: props.source?.fingerprint ?? false,
    only_dedicated_phonenumber: props.source?.only_dedicated_phonenumber ?? false,
    color: props.source?.color || 'cyan',
    max_concurrent_numbers: props.source?.max_concurrent_numbers || null,
    payout_call: props.source?.payout_call || '',
    payout_minute: props.source?.payout_minute || '',
    associations: initialAssociations.length > 0 ? initialAssociations : [],
})

// Calculer le total des weights
const totalWeight = computed(() => {
    return form.associations.reduce((sum, assoc) => sum + (parseInt(assoc.weight) || 0), 0)
})

// Calculer le pourcentage pour chaque association
function getPercentage(weight) {
    if (totalWeight.value === 0) return 0
    return ((parseInt(weight) || 0) / totalWeight.value * 100).toFixed(1)
}

// Obtenir le label d'un provider-company
function getProviderCompanyLabel(id) {
    const pc = props.availableProviderCompanies.find(pc => pc.id === id)
    return pc ? pc.label : 'Sélectionnez...'
}

// Obtenir la couleur pour la barre de progression
function getColor(index) {
    const colors = [
        'bg-blue-500',
        'bg-green-500',
        'bg-yellow-500',
        'bg-purple-500',
        'bg-pink-500',
        'bg-indigo-500',
        'bg-red-500',
        'bg-orange-500',
    ]
    return colors[index % colors.length]
}

// Vérifier si un provider-company est déjà sélectionné (sauf pour l'index courant)
function isProviderCompanySelected(pcId, currentIndex) {
    return form.associations.some((assoc, index) =>
        index !== currentIndex && assoc.providers_companies_id === pcId
    )
}

function addAssociation() {
    form.associations.push({
        providers_companies_id: null,
        weight: 1,
        payout: '',
    })
}

function removeAssociation(index) {
    form.associations.splice(index, 1)
}

// Quand enableMaxConcurrent est décoché, mettre max_concurrent_numbers à null
watch(enableMaxConcurrent, (newValue) => {
    if (!newValue) {
        form.max_concurrent_numbers = null
    } else if (form.max_concurrent_numbers === null) {
        form.max_concurrent_numbers = 1
    }
})

function submit() {
    // S'assurer que max_concurrent_numbers est null si désactivé
    if (!enableMaxConcurrent.value) {
        form.max_concurrent_numbers = null
    }

    if (isEdit.value) {
        form.put(`/sources/${props.source.id}`)
    } else {
        form.post('/sources')
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Modifier Source' : 'Nouvelle Source'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Sources', href: '/sources' },
                    { title: isEdit ? `Modifier ${source.name}` : 'Nouvelle' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/sources">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Informations de base -->
                <Card>
                    <CardHeader>
                        <CardTitle>Configuration</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Nom *</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    required
                                    :class="{ 'border-red-500': form.errors.name }"
                                />
                                <p v-if="form.errors.name" class="text-sm text-red-600">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="api_key">Clé API *</Label>
                                <Input
                                    id="api_key"
                                    v-model="form.api_key"
                                    type="text"
                                    required
                                    :class="{ 'border-red-500': form.errors.api_key }"
                                />
                                <p v-if="form.errors.api_key" class="text-sm text-red-600">
                                    {{ form.errors.api_key }}
                                </p>
                            </div>
                        </div>

                        <div class="flex gap-6">
                            <div class="flex items-center space-x-2">
                                <Switch
                                    id="fingerprint"
                                    v-model:checked="form.fingerprint"
                                />
                                <Label for="fingerprint">Fingerprint</Label>
                            </div>

                            <div class="flex items-center space-x-2">
                                <Switch
                                    id="only_dedicated_phonenumber"
                                    v-model:checked="form.only_dedicated_phonenumber"
                                />
                                <Label for="only_dedicated_phonenumber">Numéro dédié uniquement</Label>
                            </div>
                        </div>

                        <!-- Max concurrent numbers (uniquement si fingerprint activé) -->
                        <div v-if="form.fingerprint" class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <div class="flex items-center space-x-2">
                                <Switch
                                    id="enable_max_concurrent"
                                    v-model:checked="enableMaxConcurrent"
                                />
                                <Label for="enable_max_concurrent">Limiter le nombre de numéros simultanés</Label>
                            </div>

                            <div v-if="enableMaxConcurrent" class="space-y-2">
                                <Label for="max_concurrent_numbers">Nombre maximum de numéros simultanés</Label>
                                <Input
                                    id="max_concurrent_numbers"
                                    v-model.number="form.max_concurrent_numbers"
                                    type="number"
                                    min="1"
                                    placeholder="Ex: 5"
                                    :class="{ 'border-red-500': form.errors.max_concurrent_numbers }"
                                />
                                <p class="text-xs text-gray-500">
                                    Nombre maximum de numéros pouvant être assignés simultanément à cette source
                                </p>
                                <p v-if="form.errors.max_concurrent_numbers" class="text-sm text-red-600">
                                    {{ form.errors.max_concurrent_numbers }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="payout_call">Payout par appel</Label>
                                <Input
                                    id="payout_call"
                                    v-model="form.payout_call"
                                    type="number"
                                    step="0.01"
                                    placeholder="0.00"
                                    :class="{ 'border-red-500': form.errors.payout_call }"
                                />
                                <p v-if="form.errors.payout_call" class="text-sm text-red-600">
                                    {{ form.errors.payout_call }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="payout_minute">Payout par minute</Label>
                                <Input
                                    id="payout_minute"
                                    v-model="form.payout_minute"
                                    type="number"
                                    step="0.01"
                                    placeholder="0.00"
                                    :class="{ 'border-red-500': form.errors.payout_minute }"
                                />
                                <p v-if="form.errors.payout_minute" class="text-sm text-red-600">
                                    {{ form.errors.payout_minute }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label>Couleur</Label>
                            <ColorPicker v-model="form.color" />
                        </div>
                    </CardContent>
                </Card>

                <!-- Répartition Provider-Company -->
                <Card>
                    <CardHeader>
                        <CardTitle>Répartition Provider - Company</CardTitle>
                        <!-- <CardDescription>
                            Gérez les weights pour répartir le trafic entre les différentes combinaisons provider-company
                        </CardDescription> -->
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Indicateur du total -->
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Total des weights</span>
                                <span class="text-2xl font-bold text-gray-900">{{ totalWeight }}</span>
                            </div>

                            <!-- Barre de progression visuelle -->
                            <div v-if="form.associations.length > 0" class="space-y-2">
                                <div class="flex h-6 overflow-hidden rounded-md bg-gray-200">
                                    <div
                                        v-for="(assoc, index) in form.associations"
                                        :key="index"
                                        :class="getColor(index)"
                                        :style="{ width: getPercentage(assoc.weight) + '%' }"
                                        class="flex items-center justify-center text-xs font-medium text-white transition-all"
                                    >
                                        <span v-if="getPercentage(assoc.weight) > 5">
                                            {{ getPercentage(assoc.weight) }}%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Liste des associations -->
                        <div v-if="form.associations.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center">
                            <AlertCircle class="mx-auto h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500">Aucune association configurée</p>
                            <p class="text-xs text-gray-400">Cliquez sur "Ajouter une association" pour commencer</p>
                        </div>

                        <div v-else class="space-y-4">
                            <div
                                v-for="(assoc, index) in form.associations"
                                :key="index"
                                class="rounded-lg border border-gray-200 bg-white p-4"
                            >
                                <div class="mb-3 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div
                                            :class="getColor(index)"
                                            class="h-3 w-3 rounded-full"
                                        ></div>
                                        <span class="font-medium text-gray-700">Association {{ index + 1 }}</span>
                                        <span
                                            class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700"
                                        >
                                            {{ getPercentage(assoc.weight) }}%
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="removeAssociation(index)"
                                        class="text-red-600 hover:text-red-700"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>

                                <div class="grid gap-4 md:grid-cols-3">
                                    <div class="space-y-2">
                                        <Label :for="`pc_${index}`">Provider - Company *</Label>
                                        <select
                                            :id="`pc_${index}`"
                                            v-model="assoc.providers_companies_id"
                                            required
                                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                            :class="{ 'border-red-500': form.errors[`associations.${index}.providers_companies_id`] }"
                                        >
                                            <option :value="null" disabled>Sélectionnez...</option>
                                            <option
                                                v-for="pc in availableProviderCompanies"
                                                :key="pc.id"
                                                :value="pc.id"
                                                :disabled="isProviderCompanySelected(pc.id, index)"
                                            >
                                                {{ pc.label }}{{ isProviderCompanySelected(pc.id, index) ? ' (déjà sélectionné)' : '' }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors[`associations.${index}.providers_companies_id`]" class="text-sm text-red-600">
                                            {{ form.errors[`associations.${index}.providers_companies_id`] }}
                                        </p>
                                    </div>

                                    <div class="space-y-2">
                                        <Label :for="`weight_${index}`">Weight *</Label>
                                        <Input
                                            :id="`weight_${index}`"
                                            v-model="assoc.weight"
                                            type="number"
                                            min="1"
                                            required
                                            :class="{ 'border-red-500': form.errors[`associations.${index}.weight`] }"
                                        />
                                        <p v-if="form.errors[`associations.${index}.weight`]" class="text-sm text-red-600">
                                            {{ form.errors[`associations.${index}.weight`] }}
                                        </p>
                                    </div>

                                    <div class="space-y-2">
                                        <Label :for="`payout_${index}`">Payout (optionnel)</Label>
                                        <Input
                                            :id="`payout_${index}`"
                                            v-model="assoc.payout"
                                            type="number"
                                            step="0.01"
                                            placeholder="0.00"
                                            :class="{ 'border-red-500': form.errors[`associations.${index}.payout`] }"
                                        />
                                        <p v-if="form.errors[`associations.${index}.payout`]" class="text-sm text-red-600">
                                            {{ form.errors[`associations.${index}.payout`] }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bouton ajouter association -->
                        <Button
                            type="button"
                            variant="outline"
                            @click="addAssociation"
                            class="w-full"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter une association
                        </Button>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child>
                        <Link href="/sources">Annuler</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Enregistrement...' : isEdit ? 'Modifier' : 'Créer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
