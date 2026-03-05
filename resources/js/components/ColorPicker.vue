<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: String,
})

const emit = defineEmits(['update:modelValue'])

const colors = [
    { name: 'slate', class: 'bg-slate-500' },
    { name: 'gray', class: 'bg-gray-500' },
    { name: 'zinc', class: 'bg-zinc-500' },
    { name: 'red', class: 'bg-red-500' },
    { name: 'orange', class: 'bg-orange-500' },
    { name: 'amber', class: 'bg-amber-500' },
    { name: 'yellow', class: 'bg-yellow-500' },
    { name: 'lime', class: 'bg-lime-500' },
    { name: 'green', class: 'bg-green-500' },
    { name: 'emerald', class: 'bg-emerald-500' },
    { name: 'teal', class: 'bg-teal-500' },
    { name: 'cyan', class: 'bg-cyan-500' },
    { name: 'sky', class: 'bg-sky-500' },
    { name: 'blue', class: 'bg-blue-500' },
    { name: 'indigo', class: 'bg-indigo-500' },
    { name: 'violet', class: 'bg-violet-500' },
    { name: 'purple', class: 'bg-purple-500' },
    { name: 'fuchsia', class: 'bg-fuchsia-500' },
    { name: 'pink', class: 'bg-pink-500' },
    { name: 'rose', class: 'bg-rose-500' },
]

function selectColor(colorName) {
    emit('update:modelValue', colorName)
}
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="color in colors"
            :key="color.name"
            type="button"
            class="h-8 w-8 rounded-md transition-all hover:scale-110"
            :class="[
                color.class,
                modelValue === color.name
                    ? 'ring-2 ring-gray-900 ring-offset-2'
                    : 'opacity-70 hover:opacity-100'
            ]"
            :title="color.name"
            @click="selectColor(color.name)"
        />
    </div>
</template>
