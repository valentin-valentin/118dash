<script setup>
import { onMounted, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'

/**
 * Encart d'information (Dashboard, Marques) : marques que les agents n'ont pas trouvées dans le softphone, à traiter.
 * Rien ne s'affiche s'il n'y en a pas.
 */
const count = ref(null)
onMounted(async () => {
    try {
        const res = await fetch('/data/brand-requests/count', { headers: { Accept: 'application/json' } })
        if (res.ok) count.value = await res.json()
    } catch {
        // encart absent si la requête échoue
    }
})
const plural = (n, one, many) => `${n.toLocaleString('fr-FR')} ${n > 1 ? many : one}`
</script>

<template>
    <div v-if="count?.brands" class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-100 bg-white px-5 py-3">
        <p class="text-sm text-gray-700">
            {{ plural(count.brands, 'marque demandée', 'marques demandées') }} par les agents
            <span class="text-gray-500">({{ plural(count.calls, 'appel', 'appels') }})</span>
        </p>
        <Button size="sm" variant="outline" class="ml-auto" as-child>
            <Link href="/brands/requests">Traiter</Link>
        </Button>
    </div>
</template>
