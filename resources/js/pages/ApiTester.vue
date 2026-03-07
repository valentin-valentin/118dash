<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { Send, Copy, Trash2, CheckCircle, XCircle } from 'lucide-vue-next'

// ─── State ───────────────────────────────────────────────────────────────────
const sources = ref([])
const loading = ref(false)
const apiUrl = ref(localStorage.getItem('api_tester_url') || 'http://api.118.ae')
const selectedSourceId = ref(null)
const useFingerprint = ref(false)
const fingerprint = ref('')
const ip = ref('')
const response = ref(null)
const history = ref(JSON.parse(localStorage.getItem('api_tester_history') || '[]'))

// ─── Computed ────────────────────────────────────────────────────────────────
const selectedSource = computed(() => {
    if (!selectedSourceId.value) return null
    return sources.value.find(s => s.id === selectedSourceId.value)
})

const canSendRequest = computed(() => {
    if (!selectedSource.value) return false
    // If fingerprint/IP is being used (either by choice or requirement), at least one must be filled
    if ((useFingerprint.value || selectedSource.value.fingerprint_required) && !fingerprint.value && !ip.value) return false
    return true
})

const responseStatusClass = computed(() => {
    if (!response.value) return ''
    const status = response.value.status
    if (status >= 200 && status < 300) return 'text-green-600'
    if (status >= 400 && status < 500) return 'text-orange-600'
    if (status >= 500) return 'text-red-600'
    return ''
})

// ─── Methods ─────────────────────────────────────────────────────────────────
async function loadSources() {
    try {
        const res = await fetch('/data/api-tester/sources')
        sources.value = await res.json()
        console.log('Sources loaded:', sources.value)
    } catch (error) {
        console.error('Error loading sources:', error)
    }
}

async function sendRequest() {
    if (!canSendRequest.value) return

    loading.value = true
    response.value = null

    const url = `${apiUrl.value}/api/phonenumber`
    const startTime = Date.now()

    try {
        // Call backend proxy instead of external API directly
        const res = await fetch('/data/api-tester/proxy', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
            },
            body: JSON.stringify({
                api_url: apiUrl.value,
                source_id: selectedSource.value.id,
                fingerprint: fingerprint.value || null,
                ip: ip.value || null,
            }),
        })

        const proxyResponse = await res.json()

        response.value = {
            status: proxyResponse.status,
            statusText: proxyResponse.statusText,
            data: proxyResponse.data,
            duration: proxyResponse.duration,
            timestamp: new Date().toISOString(),
            debug: proxyResponse.debug,
            request: {
                url,
                headers: {
                    'X-Api-Key': selectedSource.value.api_key,
                    'Content-Type': 'application/json',
                },
                body: {
                    ...(fingerprint.value && { fingerprint: fingerprint.value }),
                    ...(ip.value && { ip: ip.value }),
                },
            },
        }

        // Add to history
        addToHistory({
            source: selectedSource.value.label,
            api_key: selectedSource.value.api_key,
            url,
            useFingerprint: useFingerprint.value,
            fingerprint: fingerprint.value,
            ip: ip.value,
            response: response.value,
        })
    } catch (error) {
        response.value = {
            status: 0,
            statusText: 'Network Error',
            data: { error: error.message },
            duration: Date.now() - startTime,
            timestamp: new Date().toISOString(),
        }
    } finally {
        loading.value = false
    }
}

function addToHistory(entry) {
    history.value.unshift(entry)
    if (history.value.length > 10) {
        history.value = history.value.slice(0, 10)
    }
    localStorage.setItem('api_tester_history', JSON.stringify(history.value))
}

function clearHistory() {
    history.value = []
    localStorage.removeItem('api_tester_history')
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text)
}

function loadFromHistory(entry) {
    selectedSourceId.value = sources.value.find(s => s.api_key === entry.api_key)?.id || null
    useFingerprint.value = entry.useFingerprint
    fingerprint.value = entry.fingerprint
    ip.value = entry.ip
    response.value = entry.response
}

function saveApiUrl() {
    localStorage.setItem('api_tester_url', apiUrl.value)
}

function formatJSON(obj) {
    return JSON.stringify(obj, null, 2)
}

function formatDate(isoString) {
    return new Date(isoString).toLocaleString('fr-FR')
}

// ─── Quick fill helpers ──────────────────────────────────────────────────────
function generateRandomFingerprint() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789'
    let result = ''
    for (let i = 0; i < 32; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length))
    }
    fingerprint.value = result
}

function setFingerprint(value) {
    fingerprint.value = value
}

function generateRandomIP() {
    const octet = () => Math.floor(Math.random() * 255) + 1
    ip.value = `${octet()}.${octet()}.${octet()}.${octet()}`
}

function setIP(value) {
    ip.value = value
}

// ─── Watchers ────────────────────────────────────────────────────────────────
// Auto-activate fingerprint when source requires it
watch(selectedSource, (newSource) => {
    if (newSource?.fingerprint_required) {
        useFingerprint.value = true
    }
})

// ─── Init ────────────────────────────────────────────────────────────────────
onMounted(() => {
    loadSources()
})
</script>

<template>
    <Head title="API Tester" />

    <AppLayout>
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <PageHeader
                :breadcrumbs="[
                    { title: 'Dashboard', href: '/' },
                    { title: 'API Tester', href: '/api-tester' }
                ]"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Configuration & Request -->
                <div class="space-y-6">
                    <!-- API Configuration -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                            <CardDescription>URL de l'API à tester</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="space-y-2">
                                <Label for="api_url">URL de l'API</Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="api_url"
                                        v-model="apiUrl"
                                        type="text"
                                        placeholder="http://api.118.ae"
                                        @blur="saveApiUrl"
                                    />
                                </div>
                                <p class="text-xs text-gray-500">
                                    Endpoint: <code class="rounded bg-gray-100 px-1 py-0.5">/api/phonenumber</code>
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Request Parameters -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Paramètres de la requête</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <!-- Source Selection -->
                            <div class="space-y-2">
                                <Label for="source">Source</Label>
                                <select
                                    id="source"
                                    v-model="selectedSourceId"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    <option :value="null">Sélectionnez une source...</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.label }}
                                        {{ source.fingerprint_required ? '(fingerprint requis)' : '' }}
                                    </option>
                                </select>
                            </div>

                            <!-- API Key Display -->
                            <div v-if="selectedSource" class="space-y-2">
                                <Label>API Key</Label>
                                <div class="flex items-center gap-2">
                                    <code class="flex-1 rounded bg-gray-100 px-3 py-2 text-xs font-mono">
                                        {{ selectedSource.api_key }}
                                    </code>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="copyToClipboard(selectedSource.api_key)"
                                    >
                                        <Copy class="h-4 w-4" />
                                    </Button>
                                </div>
                                <Badge v-if="selectedSource.fingerprint_required" variant="outline" class="bg-yellow-50">
                                    Fingerprint requis
                                </Badge>
                            </div>

                            <!-- Fingerprint Toggle (only show if not required by source) -->
                            <div v-if="selectedSource && !selectedSource.fingerprint_required" class="flex items-center space-x-2">
                                <Switch
                                    id="use_fingerprint"
                                    v-model:checked="useFingerprint"
                                />
                                <Label for="use_fingerprint">Utiliser fingerprint/IP</Label>
                            </div>

                            <!-- Fingerprint & IP Fields -->
                            <div v-if="selectedSource && (useFingerprint || selectedSource.fingerprint_required)" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="fingerprint">Fingerprint (optionnel)</Label>
                                    <Input
                                        id="fingerprint"
                                        v-model="fingerprint"
                                        type="text"
                                        placeholder="abc123def456"
                                        class="font-mono"
                                    />
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="generateRandomFingerprint"
                                        >
                                            Random
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setFingerprint('fingerprint_test_1')"
                                        >
                                            fingerprint_test_1
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setFingerprint('fingerprint_test_2')"
                                        >
                                            fingerprint_test_2
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setFingerprint('fingerprint_test_3')"
                                        >
                                            fingerprint_test_3
                                        </Button>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <Label for="ip">IP (optionnel)</Label>
                                    <Input
                                        id="ip"
                                        v-model="ip"
                                        type="text"
                                        placeholder="82.64.12.45"
                                        class="font-mono"
                                    />
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="generateRandomIP"
                                        >
                                            Random
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setIP('1.1.1.1')"
                                        >
                                            1.1.1.1
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setIP('2.2.2.2')"
                                        >
                                            2.2.2.2
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="setIP('3.3.3.3')"
                                        >
                                            3.3.3.3
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Send Button -->
                            <Button
                                class="w-full"
                                :disabled="!canSendRequest || loading"
                                @click="sendRequest"
                            >
                                <Send class="mr-2 h-4 w-4" />
                                {{ loading ? 'Envoi en cours...' : 'Envoyer la requête' }}
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <!-- Response & History -->
                <div class="space-y-6">
                    <!-- Response -->
                    <Card v-if="response">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle>Réponse</CardTitle>
                                <div class="flex items-center gap-2">
                                    <Badge
                                        :variant="response.status >= 200 && response.status < 300 ? 'default' : 'destructive'"
                                    >
                                        {{ response.status }} {{ response.statusText }}
                                    </Badge>
                                    <span class="text-xs text-gray-500">{{ response.duration }}ms</span>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                <!-- Status Icon -->
                                <div class="flex items-center gap-2">
                                    <CheckCircle
                                        v-if="response.status >= 200 && response.status < 300"
                                        class="h-5 w-5 text-green-600"
                                    />
                                    <XCircle v-else class="h-5 w-5 text-red-600" />
                                    <span class="text-sm font-medium" :class="responseStatusClass">
                                        {{ response.data.success ? 'Succès' : 'Erreur' }}
                                    </span>
                                </div>

                                <!-- Request Details -->
                                <div v-if="response.request" class="space-y-2">
                                    <p class="text-xs font-medium text-gray-700">Requête envoyée:</p>
                                    <div class="rounded-lg bg-gray-50 p-3 text-xs">
                                        <p><strong>URL:</strong> {{ response.request.url }}</p>
                                        <p><strong>X-Api-Key:</strong> {{ response.request.headers['X-Api-Key'] }}</p>
                                        <p v-if="Object.keys(response.request.body).length > 0">
                                            <strong>Body:</strong> {{ formatJSON(response.request.body) }}
                                        </p>
                                        <p v-else><strong>Body:</strong> (vide)</p>
                                    </div>
                                </div>

                                <!-- Debug Info (Backend) -->
                                <div v-if="response.debug" class="space-y-2">
                                    <p class="text-xs font-medium text-orange-700">Debug (Backend Proxy):</p>
                                    <div class="rounded-lg bg-orange-50 p-3 text-xs">
                                        <p><strong>Source:</strong> {{ response.debug.source_name }}</p>
                                        <p><strong>API Key envoyée:</strong> {{ response.debug.api_key_sent }}</p>
                                        <p><strong>URL réelle:</strong> {{ response.debug.url }}</p>
                                    </div>
                                </div>

                                <!-- JSON Response -->
                                <div>
                                    <p class="mb-2 text-xs font-medium text-gray-700">Réponse:</p>
                                    <div class="relative">
                                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>{{ formatJSON(response.data) }}</code></pre>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="absolute right-2 top-2"
                                            @click="copyToClipboard(formatJSON(response.data))"
                                        >
                                            <Copy class="h-3 w-3" />
                                        </Button>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-500">
                                    {{ formatDate(response.timestamp) }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- History -->
                    <Card v-if="history.length > 0">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle>Historique</CardTitle>
                                <Button variant="ghost" size="sm" @click="clearHistory">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <button
                                    v-for="(entry, index) in history"
                                    :key="index"
                                    class="w-full cursor-pointer rounded-lg border border-gray-200 p-3 text-left text-sm hover:bg-gray-50"
                                    @click="loadFromHistory(entry)"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium">{{ entry.source }}</span>
                                        <Badge
                                            :variant="entry.response.status >= 200 && entry.response.status < 300 ? 'default' : 'destructive'"
                                            class="text-xs"
                                        >
                                            {{ entry.response.status }}
                                        </Badge>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ formatDate(entry.response.timestamp) }}
                                        • {{ entry.response.duration }}ms
                                    </div>
                                </button>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Documentation -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Documentation</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <div>
                                <p class="font-medium">POST /api/phonenumber</p>
                                <p class="text-xs text-gray-500">Assigner un numéro à une source</p>
                            </div>

                            <div class="space-y-1">
                                <p class="text-xs font-medium text-gray-700">Codes de réponse:</p>
                                <ul class="space-y-1 text-xs text-gray-600">
                                    <li><Badge variant="default" class="mr-2">200</Badge>Numéro assigné avec succès</li>
                                    <li><Badge variant="destructive" class="mr-2">401</Badge>Clé API invalide</li>
                                    <li><Badge variant="destructive" class="mr-2">422</Badge>Fingerprint/IP manquant</li>
                                    <li><Badge variant="destructive" class="mr-2">503</Badge>Aucun numéro disponible</li>
                                </ul>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
