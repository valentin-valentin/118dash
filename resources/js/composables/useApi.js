import { reactive } from 'vue'

function xsrfToken() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

function buildQuery(params) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    )
    return Object.keys(clean).length ? '?' + new URLSearchParams(clean).toString() : ''
}

export function useApi(endpoint) {
    const state = reactive({
        data: null,
        loading: false,
        error: null,
        async load(params = {}) {
            state.loading = true
            state.error = null
            try {
                const res = await fetch(endpoint + buildQuery(params), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                })
                if (!res.ok) throw new Error(`HTTP ${res.status}`)
                state.data = await res.json()
            } catch (e) {
                state.error = e?.message ?? 'Unknown error'
            } finally {
                state.loading = false
            }
        },
    })

    return state
}
