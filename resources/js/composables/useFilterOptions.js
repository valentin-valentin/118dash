import { ref } from 'vue'

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return match ? decodeURIComponent(match[1]) : ''
}

export function useFilterOptions(endpoint, initialStructure = {}) {
    const options = ref({ ...initialStructure })
    const isLoading = ref(false)
    const isClearing = ref(false)

    async function load() {
        isLoading.value = true
        try {
            const response = await fetch(endpoint, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)
            options.value = await response.json()
        } catch (error) {
            console.error('Error loading filter options:', error)
        } finally {
            isLoading.value = false
        }
    }

    async function clearCache(clearEndpoint) {
        if (!clearEndpoint) return

        isClearing.value = true
        try {
            const response = await fetch(clearEndpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)
            options.value = { ...initialStructure }
            await load()
        } catch (error) {
            console.error('Error clearing cache:', error)
            alert('Erreur lors du vidage du cache: ' + error.message)
        } finally {
            isClearing.value = false
        }
    }

    return {
        options,
        isLoading,
        isClearing,
        load,
        clearCache,
    }
}
