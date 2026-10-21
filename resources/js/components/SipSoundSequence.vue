<script setup>
import { Button } from '@/components/ui/button'
import { ArrowDown, ArrowUp, Pause, Play, Plus } from 'lucide-vue-next'

/**
 * Liste ordonnée de sons du serveur SIP (introduction, audio d'attente, service interne).
 * Chaque action est remontée au parent, qui l'applique immédiatement sur le serveur.
 *
 * @prop {String}  title
 * @prop {String}  description   Ce qu'entend l'appelant, en une phrase
 * @prop {Array}   names         Noms des sons, dans l'ordre de lecture
 * @prop {Array}   library       Tous les sons ({ name, duration }), pour afficher les durées
 * @prop {String}  emptyText     Ce qui est joué quand la liste est vide
 * @prop {String}  playing       Son en cours d'écoute
 * @prop {Boolean} busy
 * @emits change(list, message), add, toggle-play(name)
 */
const props = defineProps({
    title: String,
    description: String,
    names: { type: Array, default: () => [] },
    library: { type: Array, default: () => [] },
    emptyText: String,
    playing: String,
    busy: Boolean,
    loading: Boolean,
})
const emit = defineEmits(['change', 'add', 'toggle-play'])

const duration = (name) => {
    const d = props.library.find((s) => s.name === name)?.duration
    return d != null ? `${Number(d).toLocaleString('fr-FR')} s` : '—'
}

function move(index, to) {
    const list = [...props.names]
    const [item] = list.splice(index, 1)
    list.splice(to, 0, item)
    emit('change', list, 'Ordre mis à jour')
}

function remove(index) {
    const name = props.names[index]
    if (!confirm(`Ne plus jouer « ${name} » ici ?\n\nLe fichier est conservé, il pourra être remis plus tard.`)) return
    emit('change', props.names.filter((_, i) => i !== index), `« ${name} » retiré`)
}
</script>

<template>
    <div>
        <div class="mb-3 flex items-end justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">{{ title }}</h3>
                <p class="mt-0.5 text-sm text-gray-500">{{ description }}</p>
            </div>
            <Button size="sm" variant="secondary" class="shrink-0" :disabled="busy || loading" @click="emit('add')">
                <Plus class="mr-1.5 h-4 w-4" />
                Ajouter un son
            </Button>
        </div>
        <div class="rounded-lg border border-gray-100 bg-white">
            <div v-if="loading" class="h-12 animate-pulse" />
            <div v-else-if="!names.length" class="px-4 py-6 text-center text-sm text-gray-500">{{ emptyText }}</div>
            <ol v-else>
                <li v-for="(name, i) in names" :key="`${name}-${i}`" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2.5 text-sm last:border-b-0">
                    <span class="w-5 tabular-nums text-gray-400">{{ i + 1 }}.</span>
                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0" :title="playing === name ? 'Arrêter' : 'Écouter'" @click="emit('toggle-play', name)">
                        <Pause v-if="playing === name" class="size-3.5" />
                        <Play v-else class="size-3.5" />
                    </Button>
                    <span class="flex-1 font-medium text-gray-900">{{ name }}</span>
                    <span class="tabular-nums text-gray-500">{{ duration(name) }}</span>
                    <div class="flex items-center gap-0.5">
                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="busy || i === 0" title="Jouer plus tôt" @click="move(i, i - 1)"><ArrowUp class="size-3.5" /></Button>
                        <Button variant="ghost" size="sm" class="h-7 w-7 p-0 text-gray-400" :disabled="busy || i === names.length - 1" title="Jouer plus tard" @click="move(i, i + 1)"><ArrowDown class="size-3.5" /></Button>
                        <Button variant="secondary" size="sm" class="ml-1 h-7 px-2 text-xs" :disabled="busy" @click="remove(i)">Retirer</Button>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</template>
