<script setup>
import { computed } from 'vue'

const props = defineProps({
    label: String,
    value: [String, Number],
    sub: String,
    loading: Boolean,
    variant: {
        type: String,
        default: 'default',
        validator: (value) => ['default', 'danger', 'success', 'warning'].includes(value),
    },
})

const textColorClass = computed(() => {
    if (props.variant === 'danger') return 'text-red-600'
    if (props.variant === 'success') return 'text-green-600'
    if (props.variant === 'warning') return 'text-yellow-600'
    return 'text-gray-900'
})

const borderColorClass = computed(() => {
    if (props.variant === 'danger') return 'border-red-100'
    if (props.variant === 'success') return 'border-green-100'
    if (props.variant === 'warning') return 'border-yellow-100'
    return 'border-gray-100'
})
</script>

<template>
    <div class="rounded-lg border bg-white p-4" :class="borderColorClass">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ label }}</p>
        <div v-if="loading" class="mt-1.5 h-7 w-20 animate-pulse rounded bg-gray-100" />
        <p v-else class="mt-1.5 text-2xl font-semibold" :class="textColorClass">
            {{ value ?? '—' }}
        </p>
        <p v-if="sub && !loading" class="mt-1 text-xs text-gray-400">{{ sub }}</p>
    </div>
</template>
