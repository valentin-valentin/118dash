<script setup>
import { Pause, PhoneForwarded, Play } from 'lucide-vue-next'

/**
 * Écoute d'un appel dans un tableau : « Agent » (conversation) et « Transfert » s'il est enregistré,
 * sinon « Pas d'enregistrement ». Le lecteur est celui de la page (événement play).
 */
defineProps({
    row: { type: Object, required: true },
    // (row, part) → true si cette partie est en cours de lecture
    isPlaying: { type: Function, required: true },
})
const emit = defineEmits(['play'])
</script>

<template>
    <div v-if="row.record_agent || row.record_transfer" class="flex items-center gap-1">
        <button
            v-if="row.record_agent"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm whitespace-nowrap"
            :class="isPlaying(row, 'agent') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
            :title="isPlaying(row, 'agent') ? 'Pause' : 'Écouter la conversation'"
            @click.stop="emit('play', row, 'agent')"
        >
            <Pause v-if="isPlaying(row, 'agent')" class="size-3.5" />
            <Play v-else class="size-3.5" />
            Agent
        </button>
        <button
            v-if="row.record_transfer"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm whitespace-nowrap"
            :class="isPlaying(row, 'transfer') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
            :title="isPlaying(row, 'transfer') ? 'Pause' : 'Écouter le transfert'"
            @click.stop="emit('play', row, 'transfer')"
        >
            <Pause v-if="isPlaying(row, 'transfer')" class="size-3.5" />
            <PhoneForwarded v-else class="size-3.5" />
            Transfert
        </button>
    </div>
    <span v-else class="text-sm whitespace-nowrap text-gray-400">Pas d'enregistrement</span>
</template>
