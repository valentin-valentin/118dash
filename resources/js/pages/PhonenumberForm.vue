<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { ArrowLeft } from 'lucide-vue-next'

const props = defineProps({
    phonenumber: {
        type: Object,
        default: null,
    },
    companies: {
        type: Array,
        required: true,
    },
    providers: {
        type: Array,
        required: true,
    },
    sources: {
        type: Array,
        required: true,
    },
    providersCompanies: {
        type: Array,
        required: true,
    },
    sourcesProvidersCompanies: {
        type: Array,
        required: true,
    },
})

const isEdit = computed(() => !!props.phonenumber)

const form = useForm({
    phonenumbers: props.phonenumber?.phonenumber || '',
    company_id: props.phonenumber?.company_id || null,
    provider_id: props.phonenumber?.provider_id || null,
    only_source_id: props.phonenumber?.only_source_id || null,
})

const numberCount = computed(() => {
    if (!form.phonenumbers) return 0
    return form.phonenumbers.split('\n').filter(n => n.trim().length > 0).length
})

// Filtrer les providers en fonction de la company sélectionnée
const availableProviders = computed(() => {
    if (!form.company_id) {
        return props.providers
    }

    const validProviderIds = props.providersCompanies
        .filter(pc => pc.company_id === form.company_id.toString())
        .map(pc => pc.provider_id)

    return props.providers.filter(p => validProviderIds.includes(p.value))
})

// Filtrer les sources en fonction de la company et du provider sélectionnés
const availableSources = computed(() => {
    if (!form.company_id || !form.provider_id) {
        return props.sources
    }

    // Trouver le providers_companies_id correspondant
    const providerCompany = props.providersCompanies.find(
        pc => pc.company_id === form.company_id.toString() && pc.provider_id === form.provider_id.toString()
    )

    if (!providerCompany) {
        return []
    }

    // Trouver les sources associées à ce providers_companies_id
    const validSourceIds = props.sourcesProvidersCompanies
        .filter(spc => spc.providers_companies_id === providerCompany.id)
        .map(spc => spc.source_id)

    return props.sources.filter(s => validSourceIds.includes(s.value))
})

const isProcessing = ref(false)
const importErrors = ref([])

// Réinitialiser provider_id quand company_id change
watch(() => form.company_id, (newCompanyId, oldCompanyId) => {
    if (newCompanyId !== oldCompanyId && oldCompanyId !== undefined) {
        form.provider_id = null
        form.only_source_id = null
    }
})

// Réinitialiser only_source_id quand provider_id change
watch(() => form.provider_id, (newProviderId, oldProviderId) => {
    if (newProviderId !== oldProviderId && oldProviderId !== undefined) {
        form.only_source_id = null
    }
})

async function submit() {
    if (isEdit.value) {
        form.put(`/phonenumbers/${props.phonenumber.id}`)
        return
    }

    // Mode création : import en masse
    const numbers = form.phonenumbers
        .split('\n')
        .map(n => n.trim())
        .filter(n => n.length > 0)
        .map(phonenumber => ({
            phonenumber,
            company_id: parseInt(form.company_id),
            provider_id: parseInt(form.provider_id),
            only_source_id: form.only_source_id ? parseInt(form.only_source_id) : null,
        }))

    if (numbers.length === 0) {
        alert('Veuillez saisir au moins un numéro')
        return
    }

    if (!form.company_id || !form.provider_id) {
        alert('Veuillez sélectionner une company et un provider')
        return
    }

    isProcessing.value = true
    importErrors.value = []

    try {
        // Récupérer le CSRF token depuis le meta tag ou le cookie
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                         document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1]

        const response = await fetch('/data/phonenumbers/bulk-import', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ numbers }),
        })

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: `Erreur ${response.status}` }))

            // Erreur de validation Laravel
            if (response.status === 422 && errorData.errors) {
                const validationErrors = Object.values(errorData.errors).flat()
                throw new Error(validationErrors.join(', '))
            }

            throw new Error(errorData.message || `Erreur HTTP ${response.status}`)
        }

        const data = await response.json()

        if (data.success) {
            router.visit('/phonenumbers', {
                onSuccess: () => {
                    alert(`${data.created} numéro(s) importé(s) avec succès`)
                }
            })
        } else {
            importErrors.value = data.errors || []
            if (data.created > 0) {
                alert(`${data.created} numéro(s) importé(s), mais ${data.errors.length} erreur(s)`)
            } else {
                alert('Erreur lors de l\'import: ' + (data.errors?.[0] || 'Erreur inconnue'))
            }
        }
    } catch (error) {
        console.error('Erreur import:', error)
        alert('Erreur lors de l\'import: ' + error.message)
    } finally {
        isProcessing.value = false
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Modifier Numéro' : 'Ajouter des Numéros'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Numéros', href: '/phonenumbers' },
                    { title: isEdit ? `Modifier ${phonenumber.phonenumber}` : 'Ajouter' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/phonenumbers">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Informations principales -->
                <Card>
                    <CardHeader>
                        <CardTitle>Informations principales</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2 md:col-span-2">
                                <Label for="phonenumbers">Numéros de téléphone * (un par ligne)</Label>
                                <Textarea
                                    id="phonenumbers"
                                    v-model="form.phonenumbers"
                                    required
                                    placeholder="+33123456789&#10;+33987654321&#10;+33555555555"
                                    rows="8"
                                    class="font-mono"
                                    :class="{ 'border-red-500': form.errors.phonenumbers }"
                                    :disabled="isEdit"
                                />
                                <p class="text-sm text-gray-600">
                                    {{ numberCount }} numéro{{ numberCount > 1 ? 's' : '' }}
                                </p>
                                <p class="text-xs text-gray-500">Format E.164 français uniquement (+33). Les doublons et numéros mal formatés seront ignorés.</p>
                                <p v-if="form.errors.phonenumbers" class="text-sm text-red-600">
                                    {{ form.errors.phonenumbers }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="company_id">Company *</Label>
                                <select
                                    id="company_id"
                                    v-model="form.company_id"
                                    required
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    :class="{ 'border-red-500': form.errors.company_id }"
                                >
                                    <option :value="null" disabled>Sélectionnez...</option>
                                    <option v-for="company in companies" :key="company.value" :value="parseInt(company.value)">
                                        {{ company.label }}
                                    </option>
                                </select>
                                <p v-if="form.errors.company_id" class="text-sm text-red-600">
                                    {{ form.errors.company_id }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="provider_id">Provider *</Label>
                                <select
                                    id="provider_id"
                                    v-model="form.provider_id"
                                    required
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    :class="{ 'border-red-500': form.errors.provider_id }"
                                    :disabled="!form.company_id"
                                >
                                    <option :value="null" disabled>{{ form.company_id ? 'Sélectionnez...' : 'Sélectionnez d\'abord une company' }}</option>
                                    <option v-for="provider in availableProviders" :key="provider.value" :value="parseInt(provider.value)">
                                        {{ provider.label }}
                                    </option>
                                </select>
                                <p v-if="form.errors.provider_id" class="text-sm text-red-600">
                                    {{ form.errors.provider_id }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="only_source_id">Source dédiée (optionnel)</Label>
                                <select
                                    id="only_source_id"
                                    v-model="form.only_source_id"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    :class="{ 'border-red-500': form.errors.only_source_id }"
                                    :disabled="!form.company_id || !form.provider_id"
                                >
                                    <option :value="null">{{ (form.company_id && form.provider_id) ? 'Aucune' : 'Sélectionnez company et provider' }}</option>
                                    <option v-for="source in availableSources" :key="source.value" :value="parseInt(source.value)">
                                        {{ source.label }}
                                    </option>
                                </select>
                                <p v-if="form.errors.only_source_id" class="text-sm text-red-600">
                                    {{ form.errors.only_source_id }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Erreurs d'import -->
                <Card v-if="importErrors.length > 0">
                    <CardHeader>
                        <CardTitle class="text-red-600">Erreurs lors de l'import</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="list-disc space-y-1 pl-5 text-sm text-red-600">
                            <li v-for="(error, index) in importErrors" :key="index">{{ error }}</li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child>
                        <Link href="/phonenumbers">Annuler</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing || isProcessing">
                        {{ form.processing || isProcessing ? 'Import en cours...' : isEdit ? 'Modifier' : 'Importer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
