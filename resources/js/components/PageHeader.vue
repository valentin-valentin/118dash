<script setup>
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { SidebarTrigger } from '@/components/ui/sidebar'

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
        <div class="fixed top-0 inset-x-0 z-[5] border-b border-gray-200 bg-white h-[57px]">
            <div class="ml-0 md:ml-64 px-6 h-full flex items-center">
                <div class="flex items-center justify-between gap-3 w-full">
                    <div class="flex items-center gap-3">
                        <!-- Bouton hamburger mobile -->
                        <SidebarTrigger class="lg:hidden" />
                        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" />
                    </div>
                    <div v-if="$slots.actions" class="flex items-center gap-2">
                        <div class="topbar-actions contents">
                            <slot name="actions" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Masquer le texte des boutons sur mobile, garder uniquement les icônes */
@media (max-width: 1023px) {
    div.topbar-actions button.inline-flex,
    div.topbar-actions a.inline-flex {
        width: 2.5rem !important;
        height: 2.5rem !important;
        min-width: 2.5rem !important;
        max-width: 2.5rem !important;
        padding: 0 !important;
        font-size: 0 !important;
        gap: 0 !important;
    }

    div.topbar-actions button.inline-flex svg,
    div.topbar-actions a.inline-flex svg {
        font-size: 1rem !important;
        width: 1rem !important;
        height: 1rem !important;
        margin: 0 !important;
    }
}
</style>
