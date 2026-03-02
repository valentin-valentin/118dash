<script setup>
defineProps({
    label: String,
    value: [String, Number],
    sub: String,
    loading: Boolean,
})
</script>

<template>
    <div class="rounded-lg border border-gray-100 bg-white p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ label }}</p>
        <div v-if="loading" class="mt-1.5 h-7 w-20 animate-pulse rounded bg-gray-100" />
        <p v-else class="mt-1.5 text-2xl font-semibold text-gray-900">
            {{ value ?? '—' }}
        </p>
        <p v-if="sub && !loading" class="mt-1 text-xs text-gray-400">{{ sub }}</p>
    </div>
</template>
