<script setup>
import { onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Store } from 'lucide-vue-next'
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
    <!-- Même alerte que la page Numéros (Audit RSVA) : bloc à icône, titre et ligne d'explication -->
    <div v-if="count?.brands" class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        <Store class="mt-0.5 h-5 w-5 shrink-0" />
        <div>
            <p class="font-medium">{{ plural(count.brands, 'marque demandée', 'marques demandées') }} par les agents ({{ plural(count.calls, 'appel', 'appels') }})</p>
            <p class="mt-0.5 text-amber-700">
                Marques que les agents n'ont pas trouvées dans le softphone.
                <Link href="/brands/requests" class="font-medium underline">Traiter les demandes</Link>
            </p>
        </div>
    </div>
</template>
