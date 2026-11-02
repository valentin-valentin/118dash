<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { ChevronRight, Database, LoaderCircle, Plus, Search, SendHorizontal } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'

/**
 * Assistant : on pose une question sur les stats, Claude interroge la base (lecture seule) et répond.
 * Chaque requête qu'il lance est visible sous la réponse, avec son résultat.
 * L'historique de toutes les conversations est à gauche.
 */
const conversations = ref([])
const search = ref('')
const current = ref(null) // { id, title, user_name, cost_usd }
const items = ref([])
const question = ref('')
const running = ref(false)
const loadingConversation = ref(false)
const thread = ref(null)
const input = ref(null)
const openQueries = ref(new Set())

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    return q ? conversations.value.filter((c) => c.title.toLowerCase().includes(q)) : conversations.value
})

function xsrfToken() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

async function loadConversations() {
    const res = await fetch('/data/assistant/conversations', { headers: { Accept: 'application/json' } })
    if (res.ok) conversations.value = await res.json()
}

async function openConversation(id) {
    if (current.value?.id === id) return
    followed = null
    running.value = false
    loadingConversation.value = true
    try {
        const res = await fetch(`/data/assistant/conversations/${id}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const data = await res.json()
        current.value = { id: data.id, title: data.title, user_name: data.user_name, cost_usd: data.cost_usd }
        items.value = data.items
        openQueries.value = new Set()
        scrollDown()
        // Question encore en cours (posée depuis un autre onglet, ou page rechargée) : on la suit
        if (data.run_id) {
            const res = await fetch(`/data/assistant/runs/${data.run_id}?after=999999`, { headers: { Accept: 'application/json' } })
            if (res.ok) items.value.push({ type: 'question', text: (await res.json()).question })
            follow(data.run_id)
        }
    } finally {
        loadingConversation.value = false
    }
}

function newConversation() {
    followed = null
    running.value = false
    current.value = null
    items.value = []
    question.value = ''
    nextTick(() => input.value?.focus())
}

async function ask() {
    const text = question.value.trim()
    if (!text || running.value) return
    question.value = ''
    items.value.push({ type: 'question', text })
    scrollDown()

    try {
        const res = await fetch('/data/assistant/ask', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ question: text, conversation_id: current.value?.id ?? null }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(data.message ?? `Erreur HTTP ${res.status}`)
        current.value = {
            id: data.conversation_id,
            title: current.value?.title ?? text,
            user_name: current.value?.user_name ?? null,
            cost_usd: current.value?.cost_usd ?? 0,
        }
        loadConversations()
        follow(data.run_id)
    } catch (e) {
        items.value.push({ type: 'error', text: e.message })
        scrollDown()
    }
}

/**
 * Suit un traitement en cours : le serveur travaille en arrière-plan, on récupère ses nouvelles étapes chaque seconde.
 */
let followed = null
async function follow(runId) {
    followed = runId
    running.value = true
    let received = 0
    try {
        for (;;) {
            const res = await fetch(`/data/assistant/runs/${runId}?after=${received}`, { headers: { Accept: 'application/json' } })
            if (followed !== runId) return
            if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
            const run = await res.json()
            received += run.events.length
            run.events.forEach(handleEvent)
            if (run.status !== 'running') {
                if (run.cost_usd !== null && current.value) current.value.cost_usd += run.cost_usd
                break
            }
            await new Promise((resolve) => setTimeout(resolve, 1000))
            if (followed !== runId) return
        }
    } catch (e) {
        items.value.push({ type: 'error', text: e.message })
    }
    if (followed !== runId) return
    followed = null
    running.value = false
    loadConversations()
    scrollDown()
    nextTick(() => input.value?.focus())
}

function handleEvent(event) {
    if (event.type === 'query') {
        items.value.push({ type: 'query', id: event.id, sql: event.sql, purpose: event.purpose })
    } else if (event.type === 'result') {
        const query = items.value.find((i) => i.type === 'query' && i.id === event.id)
        if (query) query.result = event
    } else if (event.type === 'text') {
        items.value.push({ type: 'text', text: event.text })
    } else if (event.type === 'error') {
        items.value.push({ type: 'error', text: event.message })
    }
    scrollDown()
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault()
        ask()
    }
}

function scrollDown() {
    nextTick(() => {
        if (thread.value) thread.value.scrollTop = thread.value.scrollHeight
    })
}

function toggleQuery(id) {
    const next = new Set(openQueries.value)
    next.has(id) ? next.delete(id) : next.add(id)
    openQueries.value = next
}

function markdown(text) {
    return DOMPurify.sanitize(marked.parse(text, { breaks: true }))
}

function resultSummary(result) {
    if (!result) return null
    if (result.error) return 'Erreur'
    const rows = result.row_count === 1 ? '1 ligne' : `${result.row_count.toLocaleString('fr-FR')} lignes`
    return `${rows} · ${formatMs(result.ms)}`
}

function formatMs(ms) {
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} s`
}

function formatCell(value) {
    return value === null ? '—' : String(value)
}

function formatDate(value) {
    const d = new Date(value)
    const today = new Date()
    const sameDay = d.toDateString() === today.toDateString()
    return sameDay
        ? d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })
}

function formatCost(usd) {
    return `${Number(usd).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} $`
}

onMounted(() => {
    loadConversations()
    input.value?.focus()
})
</script>

<template>
    <Head title="Assistant" />

    <AppLayout>
        <div class="p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Assistant' }]" />

            <div class="grid h-[calc(100vh-57px-3rem)] min-h-[30rem] grid-cols-1 gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <!-- Historique -->
                <aside class="hidden min-h-0 flex-col rounded-lg border border-gray-200 bg-white lg:flex">
                    <div class="space-y-2 border-b border-gray-100 p-3">
                        <button
                            type="button"
                            class="flex h-8 w-full items-center justify-center gap-1.5 rounded-md bg-gray-900 text-xs font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                            @click="newConversation"
                        >
                            <Plus class="size-3.5" /> Nouvelle conversation
                        </button>
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Rechercher"
                                class="h-8 w-full rounded-md border border-gray-200 pr-2 pl-8 text-xs outline-none focus:border-gray-400"
                            />
                        </div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-1.5">
                        <button
                            v-for="c in filtered"
                            :key="c.id"
                            type="button"
                            class="block w-full rounded-md px-2.5 py-2 text-left hover:bg-gray-50"
                            :class="current?.id === c.id ? 'bg-gray-100 hover:bg-gray-100' : ''"
                            @click="openConversation(c.id)"
                        >
                            <span class="line-clamp-2 text-xs font-medium text-gray-800">{{ c.title }}</span>
                            <span class="mt-0.5 block text-[0.6875rem] text-gray-400">
                                {{ c.user_name ?? '—' }} · {{ formatDate(c.updated_at) }}
                            </span>
                        </button>
                        <p v-if="!filtered.length" class="px-2.5 py-2 text-xs text-gray-400">—</p>
                    </div>
                </aside>

                <!-- Conversation -->
                <section class="flex min-h-0 min-w-0 flex-col rounded-lg border border-gray-200 bg-white">
                    <div class="flex h-11 shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-4">
                        <h1 class="truncate text-sm font-medium text-gray-900">{{ current?.title ?? 'Nouvelle conversation' }}</h1>
                        <div class="flex shrink-0 items-center gap-3">
                            <span v-if="current" class="text-xs text-gray-400 tabular-nums">Coût {{ formatCost(current.cost_usd) }}</span>
                            <button
                                type="button"
                                class="flex h-7 items-center gap-1 rounded-md border border-gray-200 px-2 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50 lg:hidden"
                                @click="newConversation"
                            >
                                <Plus class="size-3.5" /> Nouvelle
                            </button>
                        </div>
                    </div>

                    <div ref="thread" class="min-h-0 flex-1 overflow-y-auto">
                        <div class="mx-auto max-w-4xl space-y-4 px-4 py-5">
                            <div v-if="loadingConversation" class="flex justify-center py-10 text-gray-400">
                                <LoaderCircle class="size-5 animate-spin" />
                            </div>

                            <template v-for="(item, index) in items" :key="index">
                                <div v-if="item.type === 'question'" class="flex justify-end">
                                    <div class="max-w-[85%] rounded-lg bg-gray-100 px-3.5 py-2.5 text-sm whitespace-pre-wrap text-gray-900">{{ item.text }}</div>
                                </div>

                                <div v-else-if="item.type === 'text'" class="assistant-md text-sm text-gray-800" v-html="markdown(item.text)" />

                                <div v-else-if="item.type === 'query'" class="rounded-md border border-gray-200">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-500 hover:bg-gray-50"
                                        @click="toggleQuery(item.id)"
                                    >
                                        <ChevronRight class="size-3.5 shrink-0 transition-transform" :class="openQueries.has(item.id) ? 'rotate-90' : ''" />
                                        <Database class="size-3.5 shrink-0 text-gray-400" />
                                        <span class="min-w-0 flex-1 truncate">{{ item.purpose || 'Requête' }}</span>
                                        <LoaderCircle v-if="!item.result" class="size-3.5 shrink-0 animate-spin" />
                                        <span v-else class="shrink-0 tabular-nums" :class="item.result.error ? 'text-red-600' : ''">{{ resultSummary(item.result) }}</span>
                                    </button>
                                    <div v-if="openQueries.has(item.id)" class="space-y-2 border-t border-gray-100 p-3">
                                        <pre class="overflow-x-auto rounded bg-gray-50 p-2.5 text-[0.6875rem] leading-relaxed text-gray-700">{{ item.sql }}</pre>
                                        <p v-if="item.result?.error" class="text-xs text-red-600">{{ item.result.error }}</p>
                                        <div v-else-if="item.result?.columns?.length" class="max-h-72 overflow-auto rounded border border-gray-100">
                                            <table class="w-full text-[0.6875rem]">
                                                <thead class="sticky top-0 bg-gray-50">
                                                    <tr>
                                                        <th v-for="col in item.result.columns" :key="col" class="px-2 py-1 text-left font-medium whitespace-nowrap text-gray-500">{{ col }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="(row, r) in item.result.rows" :key="r" class="border-t border-gray-100">
                                                        <td v-for="(cell, c) in row" :key="c" class="px-2 py-1 whitespace-nowrap text-gray-700 tabular-nums">{{ formatCell(cell) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p v-if="item.result?.truncated" class="text-[0.6875rem] text-gray-400">
                                            Seules les {{ item.result.rows.length }} premières lignes sont affichées.
                                        </p>
                                    </div>
                                </div>

                                <p v-else-if="item.type === 'error'" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ item.text }}</p>
                            </template>

                            <div v-if="running" class="flex items-center gap-2 text-xs text-gray-400">
                                <LoaderCircle class="size-3.5 animate-spin" /> Claude travaille…
                            </div>
                        </div>
                    </div>

                    <div class="shrink-0 border-t border-gray-100 p-3">
                        <!-- Marge à droite : le bouton flottant du softphone est en bas à droite de l'écran -->
                        <div class="mx-auto flex max-w-4xl items-end gap-2 pr-14">
                            <textarea
                                ref="input"
                                v-model="question"
                                rows="2"
                                placeholder="Pose une question sur les stats…"
                                class="max-h-48 min-h-[2.75rem] flex-1 resize-y rounded-md border border-gray-200 px-3 py-2 text-sm outline-none focus:border-gray-400"
                                :disabled="running"
                                @keydown="onKeydown"
                            />
                            <button
                                type="button"
                                class="flex size-10 shrink-0 items-center justify-center rounded-md bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-40"
                                :disabled="running || !question.trim()"
                                aria-label="Envoyer"
                                @click="ask"
                            >
                                <SendHorizontal class="size-4" />
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.assistant-md :deep(p) {
    margin: 0.5rem 0;
    line-height: 1.6;
}
.assistant-md :deep(p:first-child) {
    margin-top: 0;
}
.assistant-md :deep(ul),
.assistant-md :deep(ol) {
    margin: 0.5rem 0;
    padding-left: 1.25rem;
}
.assistant-md :deep(ul) {
    list-style: disc;
}
.assistant-md :deep(ol) {
    list-style: decimal;
}
.assistant-md :deep(li) {
    margin: 0.2rem 0;
}
.assistant-md :deep(h1),
.assistant-md :deep(h2),
.assistant-md :deep(h3) {
    margin: 1rem 0 0.4rem;
    font-weight: 600;
    color: var(--color-gray-900);
}
.assistant-md :deep(strong) {
    font-weight: 600;
    color: var(--color-gray-900);
}
.assistant-md :deep(code) {
    border-radius: 0.25rem;
    background: var(--color-gray-100);
    padding: 0.05rem 0.3rem;
    font-size: 0.8em;
}
.assistant-md :deep(pre) {
    overflow-x: auto;
    border-radius: 0.375rem;
    background: var(--color-gray-50);
    padding: 0.6rem;
    font-size: 0.75rem;
}
.assistant-md :deep(pre code) {
    background: none;
    padding: 0;
}
.assistant-md :deep(table) {
    display: block;
    max-width: 100%;
    overflow-x: auto;
    margin: 0.6rem 0;
    border-collapse: collapse;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}
.assistant-md :deep(th),
.assistant-md :deep(td) {
    border-bottom: 1px solid var(--color-gray-100);
    padding: 0.3rem 0.75rem 0.3rem 0;
    text-align: left;
    white-space: nowrap;
}
.assistant-md :deep(th) {
    border-bottom-color: var(--color-gray-200);
    font-weight: 500;
    color: var(--color-gray-500);
}
</style>
