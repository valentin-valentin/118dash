<script setup>
defineProps({
    title: String,
    description: String,
})
</script>

<template>
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">{{ title }}</h1>
            <p v-if="description" class="mt-0.5 text-sm text-gray-400">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
