import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';

/**
 * Check if value is different from default
 */
function isDifferentFromDefault(value: unknown, defaultValue: unknown): boolean {
    // Arrays: compare length and content
    if (Array.isArray(value) && Array.isArray(defaultValue)) {
        if (value.length !== defaultValue.length) return true;
        if (value.length === 0) return false; // Both empty
        return value.some((v, i) => v !== defaultValue[i]);
    }

    // Arrays vs non-arrays
    if (Array.isArray(value)) {
        return value.length > 0; // Non-empty array is different from any non-array default
    }

    // Regular values
    return value !== defaultValue && value !== '' && value !== null && value !== undefined;
}

/**
 * Parse URL query params and merge with defaults
 */
function parseUrlParams<T extends Record<string, unknown>>(defaults: T): T {
    const params = new URLSearchParams(window.location.search);
    const result = { ...defaults };

    for (const key in defaults) {
        if (params.has(key)) {
            const value = params.get(key);
            if (value === null) continue;

            // If default is array, split by comma
            if (Array.isArray(defaults[key])) {
                result[key] = value.split(',').filter(v => v.trim() !== '') as any;
            } else {
                result[key] = value as any;
            }
        }
    }

    return result;
}

/**
 * Update URL with current filter values
 */
function updateUrl<T extends Record<string, unknown>>(filters: T, defaults: T) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        // Skip values that are same as default
        if (isDifferentFromDefault(value, defaults[key])) {
            // Handle arrays (multi-select): join with comma
            if (Array.isArray(value)) {
                params.set(key, value.join(','));
            } else {
                params.set(key, String(value));
            }
        }
    }

    const queryString = params.toString();
    const newUrl = queryString ? `${window.location.pathname}?${queryString}` : window.location.pathname;

    window.history.replaceState({}, '', newUrl);
}

/**
 * Manages filter state and triggers a debounced callback on change.
 * Syncs filters with URL query parameters.
 *
 * @param defaults  Initial filter values
 * @param onChange  Called (debounced) whenever filters change
 * @param delay     Debounce delay in ms (default 300)
 * @param syncUrl   Whether to sync with URL (default true)
 */
export function useFilters<T extends Record<string, unknown>>(
    defaults: T,
    onChange?: (filters: T) => void,
    delay = 300,
    syncUrl = true,
) {
    // Initialize from URL params if syncUrl is enabled
    const initialValues = syncUrl ? parseUrlParams(defaults) : { ...defaults };
    const filters = reactive<T>(initialValues);

    if (onChange) {
        const debounced = useDebounceFn((val: T) => {
            onChange(val);
            if (syncUrl) {
                updateUrl(val, defaults);
            }
        }, delay);

        watch(filters, (val) => debounced({ ...val } as T), { deep: true });
    } else if (syncUrl) {
        // If no onChange, still update URL
        const debouncedUrlUpdate = useDebounceFn((val: T) => updateUrl(val, defaults), delay);
        watch(filters, (val) => debouncedUrlUpdate({ ...val } as T), { deep: true });
    }

    function reset() {
        Object.assign(filters, defaults);
    }

    return { filters, reset };
}
