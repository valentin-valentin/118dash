<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { ArrowLeft } from 'lucide-vue-next'

const props = defineProps({
    blacklist: {
        type: Object,
        default: null,
    },
})

const isEdit = computed(() => !!props.blacklist)

const form = useForm({
    phonenumber: props.blacklist?.phonenumber || '',
    source: props.blacklist?.source || '',
    note: props.blacklist?.note || '',
})

function submit() {
    if (isEdit.value) {
        form.put(`/blacklists/${props.blacklist.id}`)
    } else {
        form.post('/blacklists')
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Modifier Blacklist' : 'Nouvelle Blacklist'" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Blacklists', href: '/blacklists' },
                    { title: isEdit ? `Modifier ${blacklist.phonenumber}` : 'Nouvelle' }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/blacklists">
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
                            <Label for="phonenumber">Numéro de téléphone *</Label>
                            <Input
                                id="phonenumber"
                                v-model="form.phonenumber"
                                type="text"
                                required
                                placeholder="+33612345678"
                                class="font-mono"
                                :class="{ 'border-red-500': form.errors.phonenumber }"
                            />
                            <p class="text-xs text-gray-500">Format E.164 français uniquement (+33)</p>
                            <p v-if="form.errors.phonenumber" class="text-sm text-red-600">
                                {{ form.errors.phonenumber }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="source">Source</Label>
                            <Input
                                id="source"
                                v-model="form.source"
                                type="text"
                                placeholder="ex: Signalement client, détection automatique..."
                                :class="{ 'border-red-500': form.errors.source }"
                            />
                            <p v-if="form.errors.source" class="text-sm text-red-600">
                                {{ form.errors.source }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="note">Note</Label>
                            <Textarea
                                id="note"
                                v-model="form.note"
                                rows="4"
                                placeholder="Notes supplémentaires..."
                                :class="{ 'border-red-500': form.errors.note }"
                            />
                            <p v-if="form.errors.note" class="text-sm text-red-600">
                                {{ form.errors.note }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="mt-6 flex justify-end gap-4">
                    <Button type="button" variant="outline" as-child>
                        <Link href="/blacklists">Annuler</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Enregistrement...' : isEdit ? 'Modifier' : 'Créer' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
