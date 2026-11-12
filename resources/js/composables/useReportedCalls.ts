import { ref } from 'vue';

/**
 * Appels signalés par l'agent aujourd'hui (pastille rouge du menu « Appels à vérifier ») : un seul état pour toute
 * l'application (module), pour que la pastille ne disparaisse pas à chaque changement de page. Relu au plus toutes les 60 s.
 */
const count = ref<number | null>(null);
let loadedAt = 0;
let pending: Promise<void> | null = null;

export function refreshReportedCalls(force = false): Promise<void> {
    if (!force && Date.now() - loadedAt < 60000) return Promise.resolve();
    pending ??= fetch('/data/calls-to-review/reported-today', { headers: { Accept: 'application/json' } })
        .then(async (res) => {
            if (res.ok) {
                count.value = (await res.json()).count;
                loadedAt = Date.now();
            }
        })
        .catch(() => {})
        .finally(() => (pending = null));
    return pending;
}

export function useReportedCalls() {
    return { count, refresh: refreshReportedCalls };
}
