<script setup>
import { ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'

/**
 * Autres noms à ajouter à une marque : une case par nom proposé (source affichée), plus un champ pour en ajouter un.
 * v-model = [{ name, source, checked }]
 */
const options = defineModel({ type: Array, required: true })
defineProps({ id: { type: String, required: true } })

const extra = ref('')
function add() {
    const name = extra.value.trim()
    if (name && !options.value.some((o) => o.name.toLowerCase() === name.toLowerCase())) {
        options.value = [...options.value, { name, source: 'ajouté', checked: true }]
    }
    extra.value = ''
}
</script>

<template>
    <div class="space-y-2">
        <label v-for="(o, i) in options" :key="o.name" class="flex items-center gap-2 text-sm">
            <Checkbox :model-value="o.checked" @update:model-value="options[i].checked = !!$event" />
            <span class="text-gray-900">{{ o.name }}</span>
            <span class="text-gray-500">{{ o.source }}</span>
        </label>
        <!-- Pas de <form> : le sélecteur est aussi utilisé à l'intérieur du formulaire de création -->
        <div class="flex items-center gap-2">
            <Input :id="`${id}-extra`" v-model="extra" class="h-9 w-72" placeholder="Ajouter un nom" @keydown.enter.prevent="add" />
            <Button type="button" size="sm" variant="outline" :disabled="!extra.trim()" @click="add">Ajouter</Button>
        </div>
    </div>
</template>
