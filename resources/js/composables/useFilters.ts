import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';

/**
 * Manages filter state and triggers a debounced callback on change.
 *
 * @param defaults  Initial filter values
 * @param onChange  Called (debounced) whenever filters change
 * @param delay     Debounce delay in ms (default 300)
 */
export function useFilters<T extends Record<string, unknown>>(
    defaults: T,
    onChange?: (filters: T) => void,
    delay = 300,
) {
    const filters = reactive<T>({ ...defaults });

    if (onChange) {
        const debounced = useDebounceFn(onChange, delay);
        watch(filters, (val) => debounced({ ...val } as T), { deep: true });
    }

    function reset() {
        Object.assign(filters, defaults);
    }

    return { filters, reset };
}
