<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { ArrowLeft } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

const props = defineProps({
    call: {
        type: Object,
        required: true,
    },
})

function formatDate(dateString) {
    if (!dateString) return '-'
    const date = new Date(dateString)
    return date.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
}

function formatDuration(seconds) {
    if (!seconds) return '-'
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}
</script>

<template>
    <Head :title="`Appel #${call.id}`" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'Appels', href: '/calls' },
                    { title: `Appel #${call.id}` }
                ]"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/calls">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="grid gap-6 md:grid-cols-2">
                <!-- Informations générales -->
                <Card>
                    <CardHeader>
                        <CardTitle>Informations générales</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">ID Voxnode</span>
                            <span class="font-mono text-sm">{{ call.voxnode_id || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Date de l'appel</span>
                            <span class="text-sm">{{ formatDate(call.called_at) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Appelant</span>
                            <span class="font-mono text-sm">{{ call.caller || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Appelé</span>
                            <span class="font-mono text-sm">{{ call.called || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Opérateur</span>
                            <span class="text-sm">{{ call.carrier || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Qui raccroche</span>
                            <span class="text-sm">{{ call.who_hangup || '-' }}</span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Durées -->
                <Card>
                    <CardHeader>
                        <CardTitle>Durées</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Durée totale</span>
                            <span class="font-mono text-sm font-semibold">
                                {{ formatDuration(call.total_duration) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Durée agent</span>
                            <span class="font-mono text-sm">{{ formatDuration(call.duration_agent) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Durée transfert</span>
                            <span class="font-mono text-sm">{{ formatDuration(call.duration_transfer) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Payout</span>
                            <span class="font-mono text-sm font-semibold text-green-600">
                                {{ call.payout ? parseFloat(call.payout).toFixed(2) + '€' : '-' }}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Marque & Agent -->
                <Card>
                    <CardHeader>
                        <CardTitle>Marque & Agent</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Marque</span>
                            <span class="text-sm font-medium">{{ call.brand_name || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Numéro marque</span>
                            <span class="font-mono text-sm">{{ call.brand_phonenumber || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Agent</span>
                            <span class="text-sm font-medium">
                                {{ call.agent?.name || call.agent_name || '-' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Call center</span>
                            <span class="text-sm">{{ call.callcenter?.name || '-' }}</span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Ratings -->
                <Card>
                    <CardHeader>
                        <CardTitle>Évaluation</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Note</span>
                            <span class="text-sm font-medium">{{ call.ratings_note || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Évaluateur</span>
                            <span class="text-sm">{{ call.ratings_reviewer || '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-500">Durée review</span>
                            <span class="text-sm">
                                {{ call.ratings_review_duration ? formatDuration(call.ratings_review_duration) : '-' }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-if="call.ratings_warning" variant="outline" class="bg-yellow-50">
                                ⚠️ Warning
                            </Badge>
                            <Badge v-if="call.ratings_danger" variant="destructive">
                                ❌ Danger
                            </Badge>
                            <Badge v-if="call.ratings_not_rated" variant="secondary">
                                Non noté
                            </Badge>
                        </div>
                        <div v-if="call.ratings_danger_reason" class="mt-2">
                            <span class="text-xs text-gray-500">Raison danger:</span>
                            <p class="text-sm">{{ call.ratings_danger_reason }}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Enregistrements -->
            <Card v-if="call.record_agent || call.record_transfer">
                <CardHeader>
                    <CardTitle>Enregistrements</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div v-if="call.record_agent">
                        <span class="text-sm text-gray-500">Enregistrement agent:</span>
                        <a
                            :href="call.record_agent"
                            target="_blank"
                            class="ml-2 text-sm text-blue-600 hover:underline"
                        >
                            Écouter
                        </a>
                    </div>
                    <div v-if="call.record_transfer">
                        <span class="text-sm text-gray-500">Enregistrement transfert:</span>
                        <a
                            :href="call.record_transfer"
                            target="_blank"
                            class="ml-2 text-sm text-blue-600 hover:underline"
                        >
                            Écouter
                        </a>
                    </div>
                </CardContent>
            </Card>

            <!-- Issues de rating -->
            <Card v-if="
                call.ratings_issue_microphone_quality ||
                call.ratings_issue_bad_lang ||
                call.ratings_issue_bad_understanding ||
                call.ratings_issue_bad_answers ||
                call.ratings_issue_not_happy ||
                call.ratings_issue_swearing ||
                call.ratings_issue_duration
            ">
                <CardHeader>
                    <CardTitle>Problèmes identifiés</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-2">
                        <Badge v-if="call.ratings_issue_microphone_quality" variant="outline">
                            🎤 Qualité micro
                        </Badge>
                        <Badge v-if="call.ratings_issue_bad_lang" variant="outline">
                            🗣️ Mauvais langage
                        </Badge>
                        <Badge v-if="call.ratings_issue_bad_understanding" variant="outline">
                            👂 Mauvaise compréhension
                        </Badge>
                        <Badge v-if="call.ratings_issue_bad_answers" variant="outline">
                            💬 Mauvaises réponses
                        </Badge>
                        <Badge v-if="call.ratings_issue_not_happy" variant="outline">
                            😔 Client mécontent
                        </Badge>
                        <Badge v-if="call.ratings_issue_swearing" variant="outline">
                            🤬 Insultes
                        </Badge>
                        <Badge v-if="call.ratings_issue_duration" variant="outline">
                            ⏱️ Durée
                        </Badge>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
