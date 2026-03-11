<script setup>
import { computed } from 'vue'
import { Button } from '@/components/ui/button'

const props = defineProps({
    currentPage: {
        type: Number,
        required: true,
    },
    lastPage: {
        type: Number,
        required: true,
    },
    total: {
        type: Number,
        required: true,
    },
    perPage: {
        type: Number,
        default: 50,
    },
})

const emit = defineEmits(['update:page'])

function goToPage(page) {
    if (page >= 1 && page <= props.lastPage) {
        emit('update:page', page)
    }
}

const from = computed(() => {
    if (props.total === 0) return 0
    return (props.currentPage - 1) * props.perPage + 1
})

const to = computed(() => {
    return Math.min(props.currentPage * props.perPage, props.total)
})

const pages = computed(() => {
    const current = props.currentPage
    const last = props.lastPage
    const delta = 2
    const left = current - delta
    const right = current + delta + 1
    const range = []
    const rangeWithDots = []
    let l

    for (let i = 1; i <= last; i++) {
        if (i === 1 || i === last || (i >= left && i < right)) {
            range.push(i)
        }
    }

    for (let i of range) {
        if (l) {
            if (i - l === 2) {
                rangeWithDots.push(l + 1)
            } else if (i - l !== 1) {
                rangeWithDots.push('...')
            }
        }
        rangeWithDots.push(i)
        l = i
    }

    return rangeWithDots
})
</script>

<template>
    <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
        <div class="flex flex-1 justify-between sm:hidden">
            <Button
                variant="outline"
                size="sm"
                :disabled="currentPage === 1"
                @click="goToPage(currentPage - 1)"
            >
                Précédent
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="currentPage === lastPage"
                @click="goToPage(currentPage + 1)"
            >
                Suivant
            </Button>
        </div>
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Affichage
                    <span class="font-medium">{{ from }}</span>
                    à
                    <span class="font-medium">{{ to }}</span>
                    sur
                    <span class="font-medium">{{ total }}</span>
                    résultat{{ total !== 1 ? 's' : '' }}
                </p>
            </div>
            <div>
                <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                    <button
                        type="button"
                        :disabled="currentPage === 1"
                        @click="goToPage(currentPage - 1)"
                        class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span class="sr-only">Précédent</span>
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <template v-for="(page, index) in pages" :key="index">
                        <button
                            v-if="page === '...'"
                            type="button"
                            disabled
                            class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 focus:outline-offset-0"
                        >
                            ...
                        </button>
                        <button
                            v-else
                            type="button"
                            @click="goToPage(page)"
                            :class="[
                                'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0',
                                page === currentPage
                                    ? 'z-10 bg-blue-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
                                    : 'text-gray-900 hover:bg-gray-50'
                            ]"
                        >
                            {{ page }}
                        </button>
                    </template>

                    <button
                        type="button"
                        :disabled="currentPage === lastPage"
                        @click="goToPage(currentPage + 1)"
                        class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span class="sr-only">Suivant</span>
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>
