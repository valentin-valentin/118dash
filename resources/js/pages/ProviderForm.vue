<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ColorPicker from '@/components/ColorPicker.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { ArrowLeft } from 'lucide-vue-next'

const props = defineProps({
    provider: {
        type: Object,
        default: null,
    },
})

const isEdit = computed(() => !!props.provider)

const configString = ref(
    props.provider?.config
        ? JSON.stringify(props.provider.config, null, 2)
        : ''
)

const form = useForm({
    name: props.provider?.name || '',
    driver: props.provider?.driver || '',
    enabled: props.provider?.enabled ?? true,
    config: props.provider?.config || null,
    payout: props.provider?.payout || '',
    color: props.provider?.color || 'blue',
})

function submit() {
    // Parse config JSON
    try {
        if (configString.value.trim()) {
            form.config = JSON.parse(configString.value)
        } else {
            form.config = null
        }
    } catch (e) {
        alert('Le format JSON de la configuration est invalide')
        return
    }

    if (isEdit.value) {
        form.put(`/providers/${props.provider.id}`)
    } else {
        form.post('/providers')
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Modifier Provider' : 'Nouveau Provider'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Providers', href: '/providers' },
                    { title: isEdit ? `Modifier ${provider.name}` : 'Nouveau' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/providers">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <form @submit.prevent="submit">
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

                        <div class="space-y-2">
                            <Label for="driver">Driver *</Label>
                            <Input
                                id="driver"
                                v-model="form.driver"
                                type="text"
                                required
                                placeholder="ex: neo_editions, boris, even_media..."
                                :class="{ 'border-red-500': form.errors.driver }"
                            />
                            <p v-if="form.errors.driver" class="text-sm text-red-600">
                                {{ form.errors.driver }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="payout">Payout</Label>
                            <Input
                                id="payout"
                                v-model="form.payout"
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                :class="{ 'border-red-500': form.errors.payout }"
                            />
                            <p v-if="form.errors.payout" class="text-sm text-red-600">
                                {{ form.errors.payout }}
                            </p>
                        </div>

                        <div class="flex items-center space-x-2">
                            <Switch
                                id="enabled"
                                v-model:checked="form.enabled"
                            />
                            <Label for="enabled">Provider actif</Label>
                        </div>

                        <div class="space-y-2">
                            <Label>Couleur</Label>
                            <ColorPicker v-model="form.color" />
                        </div>

                        <div class="space-y-2">
                            <Label for="config">Configuration (JSON)</Label>
                            <Textarea
                                id="config"
                                v-model="configString"
                                rows="8"
                                placeholder='{"api_url": "https://...", "api_token": "..."}'
                                class="font-mono text-sm"
                                :class="{ 'border-red-500': form.errors.config }"
                            />
                            <p v-if="form.errors.config" class="text-sm text-red-600">
                                {{ form.errors.config }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="mt-6 flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child>
                        <Link href="/providers">Annuler</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Enregistrement...' : isEdit ? 'Modifier' : 'Créer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
