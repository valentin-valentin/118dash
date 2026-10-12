<script setup>
import { formatRange } from '@/lib/performance'

/**
 * Choix de la période d'un export (semaine dernière, mois dernier, période affichée).
 *
 * @prop {Array}  periods     [{ key, label, range: { from, to } }]
 * @prop {String} modelValue  Clé sélectionnée
 */
defineProps({
    periods: { type: Array, required: true },
    modelValue: { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <div>
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Période</div>
        <div class="grid grid-cols-3 gap-2">
            <button
                v-for="p in periods"
                :key="p.key"
                type="button"
                class="rounded-md border px-3 py-2 text-left transition"
                :class="modelValue === p.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 hover:bg-gray-50'"
                @click="emit('update:modelValue', p.key)"
            >
                <div class="text-sm font-medium">{{ p.label }}</div>
                <div class="text-[11px]" :class="modelValue === p.key ? 'text-gray-300' : 'text-gray-500'">{{ formatRange(p.range.from, p.range.to) }}</div>
            </button>
        </div>
    </div>
</template>
