<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Copy, Check } from 'lucide-vue-next'

const props = defineProps({
    source: Object,
    apiKey: String,
    endpoints: Array,
})

const copiedStates = ref({})

function copyToClipboard(text, id) {
    navigator.clipboard.writeText(text)
    copiedStates.value[id] = true
    setTimeout(() => copiedStates.value[id] = false, 2000)
}

function isCopied(id) {
    return copiedStates.value[id] || false
}

function scrollToEndpoint(endpointId) {
    const element = document.getElementById(endpointId)
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
}
</script>

<template>
    <Head title="Documentation API" />

    <div class="min-h-screen bg-white flex">
        <!-- Sidebar -->
        <aside class="w-64 border-r border-gray-200 fixed h-screen overflow-y-auto bg-gray-50">
            <div class="p-4 border-b border-gray-200">
                <h1 class="text-lg font-bold text-gray-900">API Docs</h1>
                <p class="text-xs text-gray-600 mt-1">{{ source.name }}</p>
            </div>

            <nav class="p-3">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2 px-2">Endpoints</p>
                <div class="space-y-0.5">
                    <button
                        v-for="endpoint in endpoints"
                        :key="endpoint.id"
                        @click="scrollToEndpoint(endpoint.id)"
                        class="w-full text-left px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-200 rounded transition-colors flex items-center justify-between"
                    >
                        <span>{{ endpoint.title }}</span>
                        <span v-if="endpoint.coming_soon" class="text-xs bg-yellow-200 text-yellow-800 px-1.5 py-0.5 rounded">Soon</span>
                    </button>
                </div>
            </nav>
        </aside>

        <!-- Content -->
        <main class="ml-64 flex-1">
            <div class="max-w-4xl mx-auto px-8 py-8">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Documentation API</h1>
                    <p class="text-sm text-gray-600">Base URL : <code class="bg-gray-100 px-2 py-0.5 rounded text-xs">http://api.118.ae</code></p>
                </div>

                <!-- Endpoints -->
                <div class="space-y-8">
                    <section
                        v-for="endpoint in endpoints"
                        :key="endpoint.id"
                        :id="endpoint.id"
                        class="scroll-mt-4"
                    >
                        <!-- Endpoint Title -->
                        <div class="mb-4 pb-2 border-b border-gray-200">
                            <div class="flex items-center gap-2 mb-1">
                                <h2 class="text-xl font-bold text-gray-900">{{ endpoint.title }}</h2>
                                <span v-if="endpoint.coming_soon" class="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded font-medium">
                                    Bientôt disponible
                                </span>
                            </div>
                            <p class="text-sm text-gray-600">{{ endpoint.description }}</p>
                        </div>

                        <template v-if="!endpoint.coming_soon">
                            <!-- Methods -->
                            <div
                                v-for="(method, methodIndex) in endpoint.methods"
                                :key="methodIndex"
                                class="mb-6 border border-gray-200 rounded-lg"
                            >
                                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                                    <h3 class="text-sm font-semibold text-gray-900">{{ method.name }}</h3>
                                    <p class="text-xs text-gray-600 mt-0.5">{{ method.description }}</p>
                                </div>

                                <div class="p-4 space-y-3">
                                    <!-- Request -->
                                    <div>
                                        <div class="bg-gray-50 rounded px-3 py-2 flex items-center gap-2">
                                            <span class="px-2 py-0.5 bg-gray-900 text-white text-xs font-mono rounded">
                                                {{ method.http_method }}
                                            </span>
                                            <code class="text-xs font-mono text-gray-700">{{ method.url }}</code>
                                        </div>

                                        <!-- Headers -->
                                        <div v-if="method.headers && method.headers.length > 0" class="mt-2 space-y-1">
                                            <p class="text-xs font-medium text-gray-700">Headers:</p>
                                            <div class="bg-gray-50 rounded px-3 py-2 space-y-0.5">
                                                <div
                                                    v-for="header in method.headers"
                                                    :key="header.name"
                                                    class="text-xs font-mono"
                                                >
                                                    <span class="text-blue-600">{{ header.name }}:</span>
                                                    <span class="text-gray-700 ml-1">{{ header.value }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- cURL -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-medium text-gray-700">cURL</span>
                                            <button
                                                @click="copyToClipboard(method.curl, `curl-${endpoint.id}-${methodIndex}`)"
                                                class="text-xs text-gray-600 hover:text-gray-900 flex items-center gap-1"
                                            >
                                                <Check v-if="isCopied(`curl-${endpoint.id}-${methodIndex}`)" class="h-3 w-3" />
                                                <Copy v-else class="h-3 w-3" />
                                            </button>
                                        </div>
                                        <pre class="bg-gray-900 text-gray-100 px-3 py-2 rounded text-xs overflow-x-auto font-mono"><code>{{ method.curl }}</code></pre>
                                    </div>

                                    <!-- Examples -->
                                    <div
                                        v-for="(example, exampleIndex) in method.examples"
                                        :key="exampleIndex"
                                    >
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-medium text-gray-700">{{ example.language }}</span>
                                            <button
                                                @click="copyToClipboard(example.code, `example-${endpoint.id}-${methodIndex}-${exampleIndex}`)"
                                                class="text-xs text-gray-600 hover:text-gray-900 flex items-center gap-1"
                                            >
                                                <Check v-if="isCopied(`example-${endpoint.id}-${methodIndex}-${exampleIndex}`)" class="h-3 w-3" />
                                                <Copy v-else class="h-3 w-3" />
                                            </button>
                                        </div>
                                        <pre class="bg-gray-900 text-gray-100 px-3 py-2 rounded text-xs overflow-x-auto font-mono"><code>{{ example.code }}</code></pre>
                                    </div>
                                </div>
                            </div>

                            <!-- Response -->
                            <div class="border border-gray-200 rounded-lg">
                                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                                    <h3 class="text-sm font-semibold text-gray-900">Réponse</h3>
                                </div>
                                <div class="p-4">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-medium text-gray-700">JSON</span>
                                        <button
                                            @click="copyToClipboard(JSON.stringify(endpoint.response, null, 2), `response-${endpoint.id}`)"
                                            class="text-xs text-gray-600 hover:text-gray-900 flex items-center gap-1"
                                        >
                                            <Check v-if="isCopied(`response-${endpoint.id}`)" class="h-3 w-3" />
                                            <Copy v-else class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <pre class="bg-gray-900 text-gray-100 px-3 py-2 rounded text-xs overflow-x-auto font-mono"><code>{{ JSON.stringify(endpoint.response, null, 2) }}</code></pre>
                                </div>
                            </div>
                        </template>
                    </section>
                </div>
            </div>
        </main>
    </div>
</template>
