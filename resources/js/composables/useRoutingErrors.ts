import { ref } from 'vue';

/**
 * Erreurs de routage des dernières 24 h (pastille rouge du menu « Routing Logs ») : un seul état pour toute l'application
 * (module), pour que la pastille ne disparaisse pas à chaque changement de page. Relu toutes les 10 s par le menu.
 */
const count = ref<number | null>(null);
let pending: Promise<void> | null = null;

export function refreshRoutingErrors(): Promise<void> {
    pending ??= fetch('/data/routing-logs/has-errors', { headers: { Accept: 'application/json' } })
        .then(async (res) => {
            if (res.ok) count.value = (await res.json()).count ?? 0;
        })
        .catch(() => {})
        .finally(() => (pending = null));
    return pending;
}

export function useRoutingErrors() {
    return { count, refresh: refreshRoutingErrors };
}
