<script setup>
import { computed, ref } from 'vue'
import { Input } from '@/components/ui/input'

const props = defineProps({
    modelValue: {
        type: [String, Array],
        default: '',
    },
    options: {
        type: Array,
        required: true,
    },
    placeholder: {
        type: String,
        default: 'Sélectionner...',
    },
    searchable: {
        type: Boolean,
        default: true,
    },
    multiple: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const search = ref('')
const isOpen = ref(false)

const filteredOptions = computed(() => {
    if (!props.options || !Array.isArray(props.options)) {
        return []
    }
    if (!props.searchable || !search.value) {
        return props.options
    }
    const searchLower = search.value.toLowerCase()
    return props.options.filter(option => {
        const value = typeof option === 'object' ? option.name || option.label : option
        return value.toLowerCase().includes(searchLower)
    })
})

const selectedLabel = computed(() => {
    if (!props.options || !Array.isArray(props.options)) {
        return props.placeholder
    }

    if (props.multiple) {
        const selected = Array.isArray(props.modelValue) ? props.modelValue : []
        if (selected.length === 0) return props.placeholder
        if (selected.length === 1) {
            const option = props.options.find(opt => {
                const val = typeof opt === 'object' ? (opt.id || opt.value) : opt
                return val == selected[0]
            })
            return option ? (typeof option === 'object' ? (option.name || option.label) : option) : props.placeholder
        }
        return `${selected.length} sélectionné${selected.length > 1 ? 's' : ''}`
    } else {
        if (!props.modelValue) return props.placeholder
        const option = props.options.find(opt => {
            const val = typeof opt === 'object' ? (opt.id || opt.value) : opt
            return val == props.modelValue
        })
        if (!option) return props.placeholder
        return typeof option === 'object' ? (option.name || option.label) : option
    }
})

function selectOption(option) {
    const value = typeof option === 'object' ? option.id || option.value : option

    if (props.multiple) {
        const selected = Array.isArray(props.modelValue) ? [...props.modelValue] : []
        const index = selected.indexOf(value)

        if (index > -1) {
            selected.splice(index, 1)
        } else {
            selected.push(value)
        }

        emit('update:modelValue', selected)
    } else {
        emit('update:modelValue', value)
        isOpen.value = false
        search.value = ''
    }
}

function isSelected(option) {
    const value = typeof option === 'object' ? option.id || option.value : option

    if (props.multiple) {
        const selected = Array.isArray(props.modelValue) ? props.modelValue : []
        return selected.includes(value)
    } else {
        return props.modelValue == value
    }
}

function clear() {
    emit('update:modelValue', props.multiple ? [] : '')
    search.value = ''
}

const hasValue = computed(() => {
    if (props.multiple) {
        return Array.isArray(props.modelValue) && props.modelValue.length > 0
    }
    return !!props.modelValue
})
</script>

<template>
    <div class="relative">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="flex h-8 w-full items-center justify-between rounded border border-gray-200 bg-white px-3 text-sm hover:border-gray-300 focus:border-gray-400 focus:outline-none"
        >
            <span class="truncate" :class="!hasValue ? 'text-gray-400' : ''">
                {{ selectedLabel }}
            </span>
            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div
            v-if="isOpen"
            class="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
            @click.stop
        >
            <div v-if="searchable" class="sticky top-0 border-b border-gray-100 bg-white p-2">
                <Input
                    v-model="search"
                    type="text"
                    placeholder="Rechercher..."
                    @click.stop
                />
            </div>

            <div class="py-1">
                <button
                    v-if="hasValue"
                    type="button"
                    @click="clear"
                    class="flex w-full items-center px-3 py-1.5 text-left text-sm text-gray-500 hover:bg-gray-50"
                >
                    <span class="italic">Effacer tout</span>
                </button>

                <button
                    v-for="(option, index) in filteredOptions"
                    :key="index"
                    type="button"
                    @click="selectOption(option)"
                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-sm hover:bg-gray-50"
                    :class="isSelected(option) ? 'bg-blue-50 font-medium text-blue-700' : ''"
                >
                    <span class="truncate">
                        {{ typeof option === 'object' ? option.name || option.label : option }}
                    </span>
                    <svg
                        v-if="isSelected(option)"
                        class="h-4 w-4 flex-shrink-0 text-blue-700"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>

                <div v-if="filteredOptions.length === 0" class="px-3 py-2 text-center text-sm text-gray-500">
                    Aucun résultat
                </div>
            </div>
        </div>

        <div v-if="isOpen" @click="isOpen = false" class="fixed inset-0 z-40" />
    </div>
</template>
