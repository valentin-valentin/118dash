<script setup>
import { X } from 'lucide-vue-next'

defineProps({
    hasActiveFilters: Boolean,
})

const emit = defineEmits(['reset'])
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 px-4 py-3">
        <slot />
        <button
            v-if="hasActiveFilters"
            class="ml-1 flex items-center gap-1 rounded border border-gray-100 px-2 py-1 text-xs text-gray-400 transition-colors hover:border-gray-300 hover:text-gray-700"
            @click="emit('reset')"
        >
            <X class="size-3" />
            Reset
        </button>
    </div>
</template>
