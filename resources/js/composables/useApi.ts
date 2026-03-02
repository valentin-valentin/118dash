import { reactive } from 'vue';

function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

function buildQuery(params: Record<string, unknown>): string {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    ) as Record<string, string>;
    return Object.keys(clean).length ? '?' + new URLSearchParams(clean).toString() : '';
}

export interface ApiState<T> {
    data: T | null;
    loading: boolean;
    error: string | null;
    load: (params?: Record<string, unknown>) => Promise<void>;
}

export function useApi<T = unknown>(endpoint: string): ApiState<T> {
    const state = reactive<ApiState<T>>({
        data: null,
        loading: false,
        error: null,
        async load(params: Record<string, unknown> = {}) {
            state.loading = true;
            state.error = null;
            try {
                const res = await fetch(endpoint + buildQuery(params), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                state.data = await res.json();
            } catch (e) {
                state.error = e instanceof Error ? e.message : 'Unknown error';
            } finally {
                state.loading = false;
            }
        },
    });

    return state as ApiState<T>;
}
