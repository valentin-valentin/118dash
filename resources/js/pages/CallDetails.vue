<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import CallDetailPanel from '@/components/CallDetailPanel.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'

// Page d'un appel (lien direct) : même fiche que le panneau de la page Appels
defineProps({
    callId: { type: Number, required: true },
    centers: { type: Array, default: () => [] },
})
</script>

<template>
    <Head :title="`Appel #${callId}`" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Appels', href: '/calls' }, { title: `Appel #${callId}` }]">
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/calls"><ArrowLeft class="h-4 w-4" /> Appels</Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="max-w-3xl rounded-lg border border-gray-100 bg-white p-6">
                <CallDetailPanel :call-id="callId" :centers="centers" />
            </div>
        </div>
    </AppLayout>
</template>
