<script setup>
import Breadcrumbs from '@/components/Breadcrumbs.vue'

defineProps({
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <div>
        <!-- Spacer pour réserver l'espace -->
        <div class="h-[57px] -mt-6 mb-6" />

        <!-- Topbar fixed -->
        <div class="fixed top-0 inset-x-0 z-[5] border-b border-gray-200 bg-white">
            <div class="ml-0 md:ml-64 px-6 py-4">
                <div class="flex items-center justify-between">
                    <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" />
                    <div v-if="$slots.actions" class="flex items-center gap-2">
                        <slot name="actions" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
