<script setup>
import { onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Store } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { useBrandRequests } from '@/composables/useBrandRequests'

/**
 * Encart d'information (Dashboard, Marques) : marques que les agents n'ont pas trouvées dans le softphone, à traiter.
 * Rien ne s'affiche s'il n'y en a pas.
 */
const { count, refresh } = useBrandRequests()
onMounted(() => refresh())
const plural = (n, one, many) => `${n.toLocaleString('fr-FR')} ${n > 1 ? many : one}`
</script>

<template>
    <div v-if="count?.brands" class="flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-5 py-3">
        <Store class="size-5 shrink-0 text-blue-600" />
        <p class="text-sm text-blue-900">
            <span class="font-semibold">{{ plural(count.brands, 'marque demandée', 'marques demandées') }} par les agents</span>
            <span class="text-blue-700"> · {{ plural(count.calls, 'appel', 'appels') }}</span>
        </p>
        <Button size="sm" class="ml-auto" as-child>
            <Link href="/brands/requests">Traiter</Link>
        </Button>
    </div>
</template>
