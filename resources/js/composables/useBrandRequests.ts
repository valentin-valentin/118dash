import { ref } from 'vue';

/**
 * Marques demandées à traiter : un seul état pour toute l'application (module), pour que la pastille du menu et l'encart
 * ne disparaissent pas à chaque changement de page (le menu est remonté avec chaque page). Relu au plus toutes les 60 s.
 */
const count = ref<{ brands: number; calls: number } | null>(null);
let loadedAt = 0;
let pending: Promise<void> | null = null;

export function refreshBrandRequests(force = false): Promise<void> {
    if (!force && Date.now() - loadedAt < 60000) return Promise.resolve();
    pending ??= fetch('/data/brand-requests/count', { headers: { Accept: 'application/json' } })
        .then(async (res) => {
            if (res.ok) {
                count.value = await res.json();
                loadedAt = Date.now();
            }
        })
        .catch(() => {})
        .finally(() => (pending = null));
    return pending;
}

export function useBrandRequests() {
    return { count, refresh: refreshBrandRequests };
}
