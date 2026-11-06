<script setup>
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Check, Copy, ExternalLink, Upload } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { xsrfToken } from '@/lib/csrf'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

/**
 * Superviseur : réglages de la page de supervision de Fred (/superviseur).
 *   - lien d'accès avec la clé, pour l'ouvrir sans compte du panel ;
 *   - clé d'accès (changer la clé coupe les anciens liens) ;
 *   - page HTML de Fred : téléverser ou coller un nouvel index.html, qui remplace le précédent.
 */
const props = defineProps({
    accessKey: { type: String, required: true },
    customKey: { type: Boolean, default: false },
    htmlSize: { type: Number, default: 0 },
    htmlIsDefault: { type: Boolean, default: true },
    htmlUpdatedAt: { type: String, default: null },
    guide: { type: String, default: null },
})

const MAX_BYTES = 950 * 1024
const currentKey = ref(props.accessKey)
const size = ref(props.htmlSize)
const isDefault = ref(props.htmlIsDefault)
const updatedAt = ref(props.htmlUpdatedAt)
const message = ref(null) // { type: 'ok' | 'error', text }

const link = computed(() => `${window.location.origin}/superviseur?key=${encodeURIComponent(currentKey.value)}`)
const copied = ref(false)
async function copyLink() {
    try {
        await navigator.clipboard.writeText(link.value)
        copied.value = true
        setTimeout(() => (copied.value = false), 1500)
    } catch {
        // presse-papiers indisponible
    }
}

async function send(url, body) {
    const res = await fetch(url, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(body),
    })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(json.message || json.errors?.[Object.keys(json.errors)[0]]?.[0] || `Erreur HTTP ${res.status}`)
    return json
}

// ─── Clé d'accès ──────────────────────────────────────────────────────────────
const keyInput = ref(props.accessKey)
const keyDirty = computed(() => keyInput.value.trim() !== currentKey.value)
const confirmKey = ref(false)
function generateKey() {
    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)
    keyInput.value = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
async function saveKey() {
    confirmKey.value = false
    try {
        currentKey.value = (await send('/data/superviseur/key', { key: keyInput.value.trim() })).key
        message.value = { type: 'ok', text: 'Nouvelle clé enregistrée. Les anciens liens ne marchent plus.' }
    } catch (e) {
        message.value = { type: 'error', text: e.message }
    }
}

// ─── Page HTML ────────────────────────────────────────────────────────────────
const pasted = ref('')
const showPaste = ref(false)
const uploading = ref(false)
async function saveHtml(html) {
    if (new Blob([html]).size > MAX_BYTES) {
        message.value = { type: 'error', text: 'Fichier trop gros (950 Ko maximum).' }
        return
    }
    uploading.value = true
    try {
        size.value = (await send('/data/superviseur/html', { html })).size
        isDefault.value = false
        updatedAt.value = new Date().toISOString()
        pasted.value = ''
        showPaste.value = false
        message.value = { type: 'ok', text: 'Nouvelle page en ligne.' }
    } catch (e) {
        message.value = { type: 'error', text: e.message }
    } finally {
        uploading.value = false
    }
}
async function onFile(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (file) await saveHtml(await file.text())
}

// Guide des données (markdown de voxnode118), affiché lisible ; « Copier » copie le markdown brut
const guideHtml = computed(() => (props.guide ? DOMPurify.sanitize(marked.parse(props.guide)) : ''))
const guideCopied = ref(false)
async function copyGuide() {
    try {
        await navigator.clipboard.writeText(props.guide)
        guideCopied.value = true
        setTimeout(() => (guideCopied.value = false), 1500)
    } catch {
        // presse-papiers indisponible
    }
}

const formatSize = (bytes) => `${Math.round(bytes / 1024).toLocaleString('fr-FR')} Ko`
const formatDate = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')
</script>

<template>
    <Head title="Superviseur" />

    <AppLayout>
        <div class="max-w-4xl space-y-5 p-6">
            <PageHeader :breadcrumbs="[{ title: 'Dashboard', href: '/' }, { title: 'Superviseur' }]">
                <template #actions>
                    <Button as-child>
                        <a href="/superviseur" target="_blank" rel="noopener"><ExternalLink class="h-4 w-4" /> Ouvrir la supervision</a>
                    </Button>
                </template>
            </PageHeader>

            <p v-if="message" class="rounded-md px-3 py-2 text-sm" :class="message.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'">{{ message.text }}</p>

            <!-- Lien d'accès -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold text-gray-900">Lien d'accès</h2>
                    <p class="text-sm text-gray-500">Ouvre la supervision sans compte du panel. À donner seulement aux personnes qui doivent la voir.</p>
                </header>
                <div class="flex items-center gap-2 px-5 py-4">
                    <Input :model-value="link" readonly class="h-9 font-mono text-xs" @focus="(e) => e.target.select()" />
                    <Button type="button" variant="outline" size="sm" class="shrink-0" @click="copyLink">
                        <Check v-if="copied" class="h-4 w-4 text-green-600" />
                        <Copy v-else class="h-4 w-4" />
                        Copier
                    </Button>
                </div>
            </section>

            <!-- Clé d'accès -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold text-gray-900">Clé d'accès</h2>
                    <p class="text-sm text-gray-500">Changer la clé coupe tous les liens donnés avec l'ancienne.</p>
                </header>
                <div class="flex flex-wrap items-center gap-2 px-5 py-4">
                    <Input v-model="keyInput" class="h-9 w-96 max-w-full font-mono text-xs" aria-label="Clé d'accès" />
                    <Button type="button" variant="outline" size="sm" @click="generateKey">Générer une nouvelle clé</Button>
                    <span v-if="keyDirty" class="ml-auto flex gap-2">
                        <Button type="button" variant="outline" size="sm" @click="keyInput = currentKey">Annuler</Button>
                        <Button type="button" size="sm" @click="confirmKey = true">Enregistrer</Button>
                    </span>
                </div>
            </section>

            <!-- Page HTML de Fred -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="border-b border-gray-100 px-5 py-3">
                    <h2 class="font-semibold text-gray-900">Page de supervision</h2>
                    <p class="text-sm text-gray-500">
                        Le fichier template, servi tel quel. Un nouveau fichier remplace le précédent et est en ligne immédiatement.
                    </p>
                </header>
                <div class="space-y-3 px-5 py-4 text-sm">
                    <p class="text-gray-700">
                        Version en ligne : {{ formatSize(size) }},
                        {{ isDefault ? 'fichier livré avec le panel' : `mise à jour le ${formatDate(updatedAt)}` }}.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800" :class="uploading && 'pointer-events-none opacity-50'">
                            <Upload class="h-4 w-4" /> Upload un index.html
                            <input type="file" accept=".html,text/html" class="hidden" @change="onFile" />
                        </label>
                        <Button type="button" variant="outline" size="sm" @click="showPaste = !showPaste">Coller le HTML</Button>
                    </div>
                    <div v-if="showPaste" class="space-y-2">
                        <Textarea v-model="pasted" rows="10" class="font-mono text-xs" placeholder="<!doctype html>…" />
                        <div class="flex justify-end">
                            <Button type="button" size="sm" :disabled="uploading || !pasted.trim()" @click="saveHtml(pasted)">Mettre en ligne</Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Guide des données, pour modifier la page (à coller à ChatGPT) -->
            <section class="rounded-lg border border-gray-100 bg-white">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-3">
                    <div>
                        <h2 class="font-semibold text-gray-900">Guide des données</h2>
                        <p class="text-sm text-gray-500">Les données reçues par la page et ses contraintes. À donner à ChatGPT pour modifier la page.</p>
                    </div>
                    <Button v-if="guide" type="button" variant="outline" size="sm" @click="copyGuide">
                        <Check v-if="guideCopied" class="h-4 w-4 text-green-600" />
                        <Copy v-else class="h-4 w-4" />
                        Copier le guide
                    </Button>
                </header>
                <div v-if="guide" class="guide-md max-h-[32rem] overflow-y-auto px-5 py-4 text-sm text-gray-700" v-html="guideHtml" />
                <p v-else class="px-5 py-4 text-sm text-gray-500">Guide indisponible.</p>
            </section>
        </div>

        <Dialog v-model:open="confirmKey">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Changer la clé d'accès ?</DialogTitle>
                    <DialogDescription>Les liens donnés avec l'ancienne clé ne marcheront plus. Il faudra envoyer le nouveau lien.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirmKey = false">Annuler</Button>
                    <Button @click="saveKey">Changer la clé</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
.guide-md :deep(h1),
.guide-md :deep(h2),
.guide-md :deep(h3) {
    margin: 1rem 0 0.4rem;
    font-weight: 600;
    color: var(--color-gray-900);
}
.guide-md :deep(h1:first-child),
.guide-md :deep(h2:first-child) {
    margin-top: 0;
}
.guide-md :deep(p),
.guide-md :deep(ul),
.guide-md :deep(ol) {
    margin: 0.5rem 0;
}
.guide-md :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}
.guide-md :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}
.guide-md :deep(code) {
    border-radius: 0.25rem;
    background: var(--color-gray-100);
    padding: 0.05rem 0.3rem;
    font-size: 0.8em;
}
.guide-md :deep(pre) {
    overflow-x: auto;
    border-radius: 0.375rem;
    background: var(--color-gray-50);
    padding: 0.6rem;
    font-size: 0.75rem;
}
.guide-md :deep(pre code) {
    background: none;
    padding: 0;
}
.guide-md :deep(table) {
    display: block;
    overflow-x: auto;
    margin: 0.6rem 0;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.guide-md :deep(th),
.guide-md :deep(td) {
    border-bottom: 1px solid var(--color-gray-100);
    padding: 0.3rem 0.75rem 0.3rem 0;
    text-align: left;
    vertical-align: top;
}
.guide-md :deep(th) {
    font-weight: 500;
    color: var(--color-gray-500);
}
</style>
