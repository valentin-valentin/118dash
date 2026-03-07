<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ColorPicker from '@/components/ColorPicker.vue'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { ArrowLeft, Plus, Trash2 } from 'lucide-vue-next'
import tippy from 'tippy.js'
import 'tippy.js/dist/tippy.css'
import 'tippy.js/themes/light.css'

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
    availableProviders: {
        type: Array,
        required: true,
    },
})

const isEdit = computed(() => !!props.company)

// Préparer les providers existants
const initialProviders = props.company?.provider_companies?.map(pc => ({
    provider_id: pc.provider_id,
    payout: pc.payout || '',
    config: pc.config ? JSON.stringify(pc.config, null, 2) : '',
})) || []

const form = useForm({
    name: props.company?.name || '',
    enabled: props.company?.enabled ?? true,
    color: props.company?.color || 'green',
    providers: initialProviders.length > 0 ? initialProviders : [],
})

function getProviderName(id) {
    const provider = props.availableProviders.find(p => p.id === id)
    return provider ? provider.name : 'Sélectionnez...'
}

function addProvider() {
    form.providers.push({
        provider_id: null,
        payout: '',
        config: '',
    })
}

function removeProvider(index) {
    form.providers.splice(index, 1)
}

function getProviderRemovalInfo(providerId) {
    if (!isEdit.value || !providerId) {
        return { canRemove: true, tooltip: '' }
    }

    const providerCompany = props.company?.provider_companies?.find(pc => pc.provider_id === providerId)
    if (!providerCompany || !providerCompany.source_provider_companies || providerCompany.source_provider_companies.length === 0) {
        return { canRemove: true, tooltip: '' }
    }

    const sourceNames = providerCompany.source_provider_companies
        .map(spc => spc.source?.name)
        .filter(name => name)
        .join(', ')

    return {
        canRemove: false,
        tooltip: `Ce provider ne peut pas être supprimé car il est lié aux sources : ${sourceNames}`
    }
}

function setupTooltip(el) {
    if (!el) return

    nextTick(() => {
        const message = el.getAttribute('data-tooltip-message')
        if (message) {
            tippy(el, {
                content: message,
                theme: 'light',
                placement: 'left',
                arrow: true,
                animation: 'scale',
                duration: [200, 150],
                maxWidth: 350,
                interactive: false,
                appendTo: () => document.body,
            })
        }
    })
}

function submit() {
    if (isEdit.value) {
        form.put(`/companies/${props.company.id}`)
    } else {
        form.post('/companies')
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Modifier Company' : 'Nouvelle Company'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Companies', href: '/companies' },
                    { title: isEdit ? `Modifier ${company.name}` : 'Nouvelle' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/companies">
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
                        <CardTitle>Informations</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
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

                        <div class="flex items-center space-x-2">
                            <Switch
                                id="enabled"
                                v-model:checked="form.enabled"
                            />
                            <Label for="enabled">Company active</Label>
                        </div>

                        <div class="space-y-2">
                            <Label>Couleur</Label>
                            <ColorPicker v-model="form.color" />
                        </div>
                    </CardContent>
                </Card>

                <!-- Providers associés -->
                <Card>
                    <CardHeader>
                        <CardTitle>Providers</CardTitle>
                        <CardDescription>
                            Gérez les providers associés à cette company
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <!-- Liste des providers -->
                        <div v-if="form.providers.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center">
                            <p class="text-sm text-gray-500">Aucun provider associé</p>
                            <p class="text-xs text-gray-400">Cliquez sur "Ajouter un provider" pour commencer</p>
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="(provider, index) in form.providers"
                                :key="index"
                                class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-3"
                            >
                                <div class="flex-1 space-y-3">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div class="space-y-2">
                                            <Label :for="`provider_${index}`">Provider *</Label>
                                            <select
                                                :id="`provider_${index}`"
                                                v-model="provider.provider_id"
                                                required
                                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                                :class="{ 'border-red-500': form.errors[`providers.${index}.provider_id`] }"
                                            >
                                                <option :value="null" disabled>Sélectionnez...</option>
                                                <option
                                                    v-for="p in availableProviders"
                                                    :key="p.id"
                                                    :value="p.id"
                                                >
                                                    {{ p.name }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors[`providers.${index}.provider_id`]" class="text-sm text-red-600">
                                                {{ form.errors[`providers.${index}.provider_id`] }}
                                            </p>
                                        </div>

                                        <div class="space-y-2">
                                            <Label :for="`payout_${index}`">Payout (optionnel)</Label>
                                            <Input
                                                :id="`payout_${index}`"
                                                v-model="provider.payout"
                                                type="number"
                                                step="0.01"
                                                placeholder="0.00"
                                                :class="{ 'border-red-500': form.errors[`providers.${index}.payout`] }"
                                            />
                                            <p v-if="form.errors[`providers.${index}.payout`]" class="text-sm text-red-600">
                                                {{ form.errors[`providers.${index}.payout`] }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <Label :for="`config_${index}`">Configuration spécifique (optionnel)</Label>
                                        <Textarea
                                            :id="`config_${index}`"
                                            v-model="provider.config"
                                            placeholder='{"api_token": "cle_specifique", "base_url": "https://..."}'
                                            rows="3"
                                            class="font-mono text-xs"
                                            :class="{ 'border-red-500': form.errors[`providers.${index}.config`] }"
                                        />
                                        <p class="text-xs text-gray-500">
                                            JSON pour override la config du provider (ex: clé API spécifique pour cette company)
                                        </p>
                                        <p v-if="form.errors[`providers.${index}.config`]" class="text-sm text-red-600">
                                            {{ form.errors[`providers.${index}.config`] }}
                                        </p>
                                    </div>
                                </div>

                                <button
                                    v-if="!getProviderRemovalInfo(provider.provider_id).canRemove"
                                    type="button"
                                    disabled
                                    :ref="el => { if (el) setupTooltip(el) }"
                                    :data-tooltip-message="getProviderRemovalInfo(provider.provider_id).tooltip"
                                    class="mt-7 inline-flex h-9 w-9 cursor-not-allowed items-center justify-center rounded-md text-sm font-medium text-gray-400 transition-colors hover:bg-transparent"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                                <Button
                                    v-else
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeProvider(index)"
                                    class="mt-7 text-red-600 hover:text-red-700"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <!-- Bouton ajouter provider -->
                        <Button
                            type="button"
                            variant="outline"
                            @click="addProvider"
                            class="w-full"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Ajouter un provider
                        </Button>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child>
                        <Link href="/companies">Annuler</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Enregistrement...' : isEdit ? 'Modifier' : 'Créer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
