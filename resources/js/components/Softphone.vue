<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Delete, Mic, MicOff, Phone, PhoneOff, Volume2, X } from 'lucide-vue-next'
import { call, hangup, isInCall, normalizeNumber, sendDtmf, setMuted, setVolume, softphone } from '@/composables/useSoftphone'

/**
 * Widget du softphone, monté à la racine de l'application (app.ts), à côté des pages :
 * il reste affiché et l'appel continue quand on change de page.
 * N'apparaît que si SIP_ENABLED=true et qu'un utilisateur est connecté.
 */
const page = usePage()
const enabled = computed(() => !!page.props.softphone?.enabled)

const input = ref('')
const inCall = computed(() => isInCall())

// Chronomètre : durée depuis le décroché (ou depuis le début de l'annonce avant)
const now = ref(Date.now())
let timer = null
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 500)))
onUnmounted(() => clearInterval(timer))

function duration(from) {
    if (!from) return ''
    const s = Math.max(0, Math.floor((now.value - from) / 1000))
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

const STATUS = {
    idle: { label: 'Prêt', tone: 'text-gray-500' },
    connecting: { label: 'Connexion…', tone: 'text-gray-500' },
    calling: { label: 'Appel en cours…', tone: 'text-gray-600' },
    early: { label: 'Annonce / sonnerie', tone: 'text-amber-700' },
    ringing: { label: 'Ça sonne…', tone: 'text-gray-600' },
    established: { label: 'En ligne', tone: 'text-green-700' },
    ended: { label: 'Terminé', tone: 'text-gray-500' },
}
const statusInfo = computed(() => STATUS[softphone.status])
const statusTimer = computed(() => {
    if (softphone.status === 'established') return duration(softphone.answeredAt)
    if (softphone.status === 'early') return duration(softphone.earlyAt)
    return ''
})

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#']

function press(key) {
    if (inCall.value) sendDtmf(key) // menus vocaux pendant l'appel
    else input.value += key
}

function dial() {
    if (inCall.value) return
    call(input.value)
}

const canDial = computed(() => !inCall.value && !!normalizeNumber(input.value))

// Appel lancé depuis un tableau : le numéro apparaît dans le champ
watch(() => softphone.number, (n) => {
    if (n && inCall.value) input.value = n
})

// Prévenir avant de quitter ou recharger la page pendant un appel
function onBeforeUnload(e) {
    if (inCall.value) e.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload))
</script>

<template>
    <div v-if="enabled" class="fixed bottom-4 right-4 z-50 flex flex-col items-end gap-2 print:hidden">
        <!-- Panneau -->
        <div v-if="softphone.open" class="w-72 rounded-xl border border-gray-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                <div class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                    <span class="h-2 w-2 rounded-full" :class="softphone.connected ? 'bg-green-500' : 'bg-gray-300'" :title="softphone.connected ? 'Connecté au serveur' : 'Non connecté'" />
                    Softphone
                </div>
                <button type="button" class="text-gray-400 hover:text-gray-700" title="Réduire" @click="softphone.open = false">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="space-y-3 p-4">
                <!-- État de l'appel -->
                <div class="min-h-10 rounded-md bg-gray-50 px-3 py-2">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-medium" :class="statusInfo.tone">{{ statusInfo.label }}</span>
                        <span class="tabular-nums text-gray-500">{{ statusTimer }}</span>
                    </div>
                    <div v-if="softphone.number && softphone.status !== 'idle'" class="font-mono text-xs text-gray-600">{{ softphone.number }}</div>
                    <div v-if="softphone.status === 'ended' && softphone.endReason" class="text-xs text-gray-500">{{ softphone.endReason }}</div>
                </div>
                <p v-if="softphone.error && softphone.status !== 'ended'" class="text-xs text-red-600">{{ softphone.error }}</p>

                <!-- Numéro -->
                <div class="flex items-center gap-1">
                    <input
                        v-model="input"
                        type="tel"
                        inputmode="tel"
                        placeholder="0891 24 23 31"
                        class="h-9 min-w-0 flex-1 rounded-md border border-gray-200 px-3 font-mono text-sm focus:border-gray-400 focus:outline-none disabled:bg-gray-50"
                        :disabled="inCall"
                        @keydown.enter="dial"
                    />
                    <button
                        v-if="input && !inCall"
                        type="button"
                        class="flex h-9 w-9 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        title="Effacer"
                        @click="input = input.slice(0, -1)"
                    >
                        <Delete class="h-4 w-4" />
                    </button>
                </div>

                <!-- Clavier (envoie des touches DTMF pendant un appel) -->
                <div class="grid grid-cols-3 gap-1.5">
                    <button
                        v-for="key in KEYS"
                        :key="key"
                        type="button"
                        class="h-9 rounded-md border border-gray-100 font-mono text-sm text-gray-800 hover:bg-gray-50 active:bg-gray-100"
                        @click="press(key)"
                    >
                        {{ key }}
                    </button>
                </div>

                <!-- Appeler / raccrocher -->
                <button
                    v-if="!inCall"
                    type="button"
                    class="flex h-10 w-full items-center justify-center gap-2 rounded-md bg-green-600 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-40"
                    :disabled="!canDial"
                    @click="dial"
                >
                    <Phone class="h-4 w-4" />
                    Appeler
                </button>
                <button
                    v-else
                    type="button"
                    class="flex h-10 w-full items-center justify-center gap-2 rounded-md bg-red-600 text-sm font-medium text-white hover:bg-red-700"
                    @click="hangup"
                >
                    <PhoneOff class="h-4 w-4" />
                    Raccrocher
                </button>

                <!-- Micro et volume -->
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-gray-200 hover:bg-gray-50 disabled:opacity-40"
                        :class="softphone.muted ? 'bg-red-50 text-red-600' : 'text-gray-600'"
                        :disabled="softphone.status !== 'established'"
                        :title="softphone.muted ? 'Réactiver le micro' : 'Couper le micro'"
                        @click="setMuted(!softphone.muted)"
                    >
                        <MicOff v-if="softphone.muted" class="h-4 w-4" />
                        <Mic v-else class="h-4 w-4" />
                    </button>
                    <Volume2 class="h-4 w-4 shrink-0 text-gray-400" />
                    <input
                        type="range"
                        min="0"
                        max="1"
                        step="0.05"
                        :value="softphone.volume"
                        class="w-full accent-gray-900"
                        title="Volume"
                        @input="setVolume(Number($event.target.value))"
                    />
                </div>

                <!-- Derniers numéros + test audio -->
                <div class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
                    <button
                        v-for="n in softphone.history.filter((x) => x !== '600')"
                        :key="n"
                        type="button"
                        class="rounded border border-gray-200 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 hover:bg-gray-50 disabled:opacity-40"
                        :disabled="inCall"
                        :title="`Appeler ${n}`"
                        @click="input = n"
                    >
                        {{ n }}
                    </button>
                    <button
                        type="button"
                        class="ml-auto text-[11px] text-gray-500 underline hover:text-gray-800 disabled:opacity-40"
                        :disabled="inCall"
                        title="Appelle un écho sur le serveur : vous entendez votre propre voix (ne passe pas par OVH)"
                        @click="call('600')"
                    >
                        Test audio
                    </button>
                </div>
            </div>
        </div>

        <!-- Bouton flottant : pastille d'appel en cours ou simple bouton -->
        <button
            v-if="inCall && !softphone.open"
            type="button"
            class="flex items-center gap-3 rounded-full bg-gray-900 py-2 pl-4 pr-2 text-sm text-white shadow-lg"
            @click="softphone.open = true"
        >
            <span class="flex items-center gap-2">
                <span class="h-2 w-2 animate-pulse rounded-full" :class="softphone.status === 'established' ? 'bg-green-400' : 'bg-amber-400'" />
                <span class="font-mono">{{ softphone.number }}</span>
                <span class="tabular-nums text-gray-300">{{ statusTimer }}</span>
            </span>
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-red-600 hover:bg-red-700" title="Raccrocher" @click.stop="hangup">
                <PhoneOff class="h-4 w-4" />
            </span>
        </button>
        <button
            v-else-if="!softphone.open"
            type="button"
            class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-900 text-white shadow-lg hover:bg-gray-800"
            title="Softphone"
            @click="softphone.open = true"
        >
            <Phone class="h-5 w-5" />
        </button>
    </div>
</template>
