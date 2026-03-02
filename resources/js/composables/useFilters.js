import { useDebounceFn } from '@vueuse/core'
import { reactive, watch } from 'vue'

/**
 * Gère l'état des filtres et déclenche un callback debouncé à chaque changement.
 *
 * @param {object}   defaults  Valeurs initiales des filtres
 * @param {function} onChange  Appelé (debouncé) à chaque modification
 * @param {number}   delay     Délai en ms (défaut 300)
 */
export function useFilters(defaults, onChange, delay = 300) {
    const filters = reactive({ ...defaults })

    if (onChange) {
        const debounced = useDebounceFn(onChange, delay)
        watch(filters, (val) => debounced({ ...val }), { deep: true })
    }

    function reset() {
        Object.assign(filters, defaults)
    }

    return { filters, reset }
}
