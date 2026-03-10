<script setup>
defineProps({
    title: {
        type: String,
        default: 'Filtres',
    },
    hasActiveFilters: {
        type: Boolean,
        default: false,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    isClearing: {
        type: Boolean,
        default: false,
    },
    showClearCache: {
        type: Boolean,
        default: false,
    },
    resultCount: {
        type: Number,
        default: null,
    },
    loadingResults: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['reset', 'clear-cache'])
</script>

<template>
    <div class="relative rounded-lg border border-gray-100 bg-white p-4">
        <!-- Overlay de chargement -->
        <div
            v-if="isLoading || isClearing"
            class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-white/80 backdrop-blur-[2px]"
        >
            <div class="flex items-center gap-2.5 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <svg class="h-4 w-4 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm font-medium text-gray-700">
                    {{ isClearing ? 'Vidage du cache...' : 'Chargement des filtres...' }}
                </span>
            </div>
        </div>

        <!-- En-tête -->
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-medium text-gray-700">{{ title }}</h3>
                <div v-if="resultCount !== null && hasActiveFilters" class="flex items-center gap-1.5">
                    <span class="text-xs text-gray-400">·</span>
                    <span v-if="loadingResults" class="flex items-center gap-1.5 text-xs text-gray-400">
                        <svg class="h-3 w-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Chargement...</span>
                    </span>
                    <span v-else class="text-xs font-medium text-gray-600">
                        {{ resultCount.toLocaleString() }} résultat{{ resultCount !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="showClearCache"
                    @click="emit('clear-cache')"
                    :disabled="isClearing || isLoading"
                    class="text-xs text-gray-500 transition-colors hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
                    title="Vider le cache des filtres"
                >
                    🗑️ Vider le cache
                </button>
                <button
                    v-if="hasActiveFilters"
                    @click="emit('reset')"
                    class="text-xs font-medium text-blue-600 transition-colors hover:text-blue-700"
                >
                    ↺ Réinitialiser
                </button>
            </div>
        </div>

        <!-- Contenu des filtres (slot) -->
        <slot />
    </div>
</template>
