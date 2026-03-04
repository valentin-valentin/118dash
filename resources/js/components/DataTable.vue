<script setup>
import { ChevronDown, ChevronUp } from 'lucide-vue-next'

/**
 * @prop {Array}    columns      [{ key, label, sortable?, class?, headerClass? }]
 * @prop {Array}    rows         Données
 * @prop {Boolean}  loading      Affiche le skeleton
 * @prop {String}   sortKey      Colonne triée actuellement
 * @prop {String}   sortDir      'asc' | 'desc'
 * @prop {String}   emptyMessage Message si pas de résultat
 * @prop {Function} rowClass     Fonction qui prend une row et retourne une classe CSS
 *
 * Slots nommés par clé de colonne : #nom-de-colonne="{ row, value }"
 */
defineProps({
    columns: Array,
    rows: Array,
    loading: Boolean,
    sortKey: String,
    sortDir: String,
    emptyMessage: String,
    rowClass: Function,
})

const emit = defineEmits(['sort'])
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="whitespace-nowrap px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-400"
                        :class="[col.headerClass, col.sortable && 'cursor-pointer select-none hover:text-gray-700']"
                        @click="col.sortable && emit('sort', col.key)"
                    >
                        <span class="flex items-center gap-1">
                            {{ col.label }}
                            <template v-if="col.sortable">
                                <ChevronUp v-if="sortKey === col.key && sortDir === 'asc'" class="size-3" />
                                <ChevronDown v-else-if="sortKey === col.key && sortDir === 'desc'" class="size-3" />
                                <span v-else class="size-3 opacity-20">⇅</span>
                            </template>
                        </span>
                    </th>
                </tr>
            </thead>

            <tbody>
                <!-- Skeleton loading -->
                <template v-if="loading">
                    <tr v-for="n in 8" :key="n" class="border-b border-gray-50">
                        <td v-for="col in columns" :key="col.key" class="px-4 py-3">
                            <div class="h-4 animate-pulse rounded bg-gray-50" style="width: 65%" />
                        </td>
                    </tr>
                </template>

                <!-- Vide -->
                <template v-else-if="rows.length === 0">
                    <tr>
                        <td :colspan="columns.length" class="px-4 py-10 text-center text-sm text-gray-300">
                            {{ emptyMessage ?? 'No data' }}
                        </td>
                    </tr>
                </template>

                <!-- Lignes -->
                <template v-else>
                    <tr
                        v-for="(row, i) in rows"
                        :key="i"
                        class="border-b border-gray-50 transition-colors hover:bg-gray-100/80"
                        :class="rowClass ? rowClass(row) : ''"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-2.5 text-gray-700"
                            :class="col.class"
                        >
                            <slot :name="col.key" :row="row" :value="row[col.key]">
                                {{ row[col.key] }}
                            </slot>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
