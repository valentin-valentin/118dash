<script setup>
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Check, Copy, ExternalLink, LoaderCircle, Play } from 'lucide-vue-next'
import ColorBadge from '@/components/ColorBadge.vue'
import { formatQuality, qualityClass } from '@/lib/quality'
import {
    WHO_HANGUP,
    agentLabel,
    carrierName,
    centerOf,
    durationParts,
    formatDateTime,
    formatDuration,
    formatEuros,
    formatPhone,
    outcome,
    platformLabel,
} from '@/lib/calls'

/**
 * Fiche d'un appel : écoute des enregistrements, déroulé, appelant (et ses autres appels), numéro appelé,
 * agent, argent, qualité audio, remarques de l'agent, évaluation et SMS.
 * Utilisée dans le panneau de la page Appels et sur la page d'un appel.
 */
const props = defineProps({
    callId: { type: Number, required: true },
    centers: { type: Array, default: () => [] },
    // Dans le panneau : les autres appels du numéro s'ouvrent dans le panneau ; sur la page, par un lien
    inPanel: { type: Boolean, default: false },
})
const emit = defineEmits(['open'])

const detail = ref(null)
const loading = ref(false)
const error = ref(null)
let requestId = 0

async function load(id) {
    const current = ++requestId
    loading.value = true
    error.value = null
    try {
        const res = await fetch(`/data/calls/${id}/detail`, { headers: { Accept: 'application/json' } })
        if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
        const data = await res.json()
        if (current === requestId) detail.value = data
    } catch (e) {
        if (current === requestId) error.value = e.message
    } finally {
        if (current === requestId) loading.value = false
    }
}
watch(() => props.callId, (id) => id && load(id), { immediate: true })

const call = computed(() => detail.value?.call ?? null)
const parts = computed(() => (call.value ? durationParts(call.value) : null))
const result = computed(() => (call.value ? outcome(call.value) : null))
const center = computed(() => (call.value ? centerOf(call.value.agent_name, props.centers) : null))
const source = computed(() => call.value?.source ?? call.value?.phonenumber?.source ?? null)
const quality = computed(() => detail.value?.quality ?? null)
const mos = computed(() => (call.value?.audio_quality === null || call.value?.audio_quality === undefined ? null : Number(call.value.audio_quality)))

const recordings = computed(() => {
    const c = call.value
    if (!c) return []
    return [
        c.record_agent && { label: "Conversation avec l'agent", url: c.record_agent, length: parts.value.agent },
        c.record_transfer && { label: 'Transfert vers la marque', url: c.record_transfer, length: parts.value.transfer },
    ].filter(Boolean)
})

// Part de chaque étape dans la barre du déroulé
const segments = computed(() => {
    const p = parts.value
    if (!p || !p.total) return []
    return [
        { key: 'wait', label: 'Attente', seconds: p.wait, class: 'bg-amber-300' },
        { key: 'agent', label: 'Agent', seconds: p.agent, class: 'bg-gray-900' },
        { key: 'transfer', label: 'Transfert', seconds: p.transfer, class: 'bg-blue-400' },
    ].filter((s) => s.seconds > 0)
})

// Remarques de l'agent saisies dans son poste
const agentNotes = computed(() => {
    const c = call.value
    if (!c) return []
    return [
        c.phone_agent_hangup && { label: "L'agent a raccroché", reason: c.phone_agent_hangup_reason },
        c.phone_reported_by_agent && { label: "Signalé par l'agent", reason: c.phone_reported_by_agent_reason },
        c.phone_cant_provide_service && { label: 'Service impossible à rendre', reason: c.phone_cant_provide_service_reason },
        c.callback_for_offer && { label: 'Rappel pour une offre', reason: null },
    ].filter(Boolean)
})

const RATING_ISSUES = {
    ratings_issue_microphone_quality: 'Qualité du micro',
    ratings_issue_bad_lang: 'Langage',
    ratings_issue_bad_understanding: 'Compréhension',
    ratings_issue_bad_answers: 'Réponses',
    ratings_issue_not_happy: 'Client mécontent',
    ratings_issue_swearing: 'Insultes',
    ratings_issue_duration: 'Durée',
}
const ratingIssues = computed(() => Object.entries(RATING_ISSUES).filter(([k]) => call.value?.[k]).map(([, label]) => label))
const hasRating = computed(() => {
    const c = call.value
    return !!c && (c.ratings_note || c.ratings_reviewer || c.ratings_warning || c.ratings_danger || c.ratings_not_rated || ratingIssues.value.length)
})

const SMS_REVIEWS = {
    support_sms_review_wait_too_long: 'Attente trop longue',
    support_sms_review_agent_need_to_improve: "L'agent doit s'améliorer",
    support_sms_review_agent_bad: 'Mauvais agent',
    support_sms_review_service_price: 'Prix du service',
    support_sms_review_didnt_know_price: 'Ne connaissait pas le prix',
    support_sms_review_didnt_transfered: 'Pas transféré',
    support_sms_review_call_ended_before_transfer: 'Coupé avant le transfert',
}
const smsReviews = computed(() => Object.entries(SMS_REVIEWS).filter(([k]) => call.value?.[k]).map(([, label]) => label))
const hasSms = computed(() => {
    const c = call.value
    return !!c && (c.support_sms_sent || c.support_sms_to_be_sent || c.info_sms_sent)
})

const copied = ref(null)
async function copy(text) {
    try {
        await navigator.clipboard.writeText(text)
        copied.value = text
        setTimeout(() => (copied.value = null), 1500)
    } catch {
        // presse-papiers indisponible : rien à faire
    }
}

// Valeur réseau avec son unité, « — » si non mesurée
const unit = (v, suffix) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}${suffix}`)

function fullDate(value) {
    if (!value) return '—'
    return new Date(value).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>

<template>
    <div class="text-sm">
        <div v-if="loading && !call" class="flex justify-center py-16 text-gray-400"><LoaderCircle class="size-5 animate-spin" /></div>
        <p v-else-if="error" class="rounded-md bg-red-50 px-3 py-2 text-red-700">{{ error }}</p>

        <div v-else-if="call" class="space-y-6" :class="loading && 'opacity-60'">
            <!-- En-tête -->
            <header>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="result.tone">{{ result.label }}</span>
                    <span class="text-xs text-gray-500">{{ platformLabel(call.platform) }}</span>
                    <span v-if="call.blacklist_id" class="rounded bg-red-600 px-1.5 py-0.5 text-xs font-medium text-white">Blacklist</span>
                </div>
                <p class="mt-1.5 text-base font-semibold text-gray-900 first-letter:uppercase">{{ fullDate(call.called_at) }}</p>
                <p class="text-xs text-gray-400">
                    Appel #{{ call.id }}<template v-if="call.voxnode_id"> · Voxnode {{ call.voxnode_id }}</template>
                    <Link v-if="inPanel" :href="`/calls/${call.id}`" class="ml-2 inline-flex items-center gap-0.5 text-gray-500 hover:text-gray-800">
                        Ouvrir la page <ExternalLink class="size-3" />
                    </Link>
                </p>
            </header>

            <!-- Enregistrements -->
            <section>
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Écoute</h3>
                <div v-if="recordings.length" class="space-y-3">
                    <div v-for="r in recordings" :key="r.url">
                        <div class="mb-1 flex items-baseline justify-between text-xs">
                            <span class="font-medium text-gray-700">{{ r.label }}</span>
                            <span class="flex items-center gap-2 text-gray-400">
                                {{ formatDuration(r.length) }}
                                <a :href="r.url" target="_blank" rel="noopener" class="hover:text-gray-700" title="Ouvrir le fichier"><ExternalLink class="size-3.5" /></a>
                            </span>
                        </div>
                        <audio :key="r.url" :src="r.url" controls preload="none" class="h-9 w-full" />
                    </div>
                </div>
                <p v-else class="text-gray-500">Pas d'enregistrement : {{ result.key === 'lost' ? "aucun agent n'a décroché" : "aucun fichier pour cet appel" }}.</p>
            </section>

            <!-- Déroulé -->
            <section>
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Déroulé · {{ formatDuration(parts.total) }}</h3>
                <div v-if="segments.length" class="flex h-2.5 overflow-hidden rounded-full bg-gray-100">
                    <div v-for="s in segments" :key="s.key" :class="s.class" :style="{ width: `${(s.seconds / parts.total) * 100}%` }" />
                </div>
                <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                    <div><dt class="flex items-center gap-1.5 text-gray-500"><span class="h-2 w-2 rounded-full bg-amber-300" />Attente</dt><dd class="tabular-nums text-gray-900">{{ formatDuration(parts.wait) }}</dd></div>
                    <div><dt class="flex items-center gap-1.5 text-gray-500"><span class="h-2 w-2 rounded-full bg-gray-900" />Agent</dt><dd class="tabular-nums text-gray-900">{{ parts.agent ? formatDuration(parts.agent) : '—' }}</dd></div>
                    <div><dt class="flex items-center gap-1.5 text-gray-500"><span class="h-2 w-2 rounded-full bg-blue-400" />Transfert</dt><dd class="tabular-nums text-gray-900">{{ parts.transfer ? formatDuration(parts.transfer) : '—' }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">
                    Raccroché par : <span class="text-gray-800">{{ WHO_HANGUP[call.who_hangup] ?? (call.who_hangup || '—') }}</span>
                    <template v-if="call.phone_duration_agent !== null && call.phone_duration_agent !== undefined">
                        · Conversation {{ formatDuration(call.phone_duration_agent) }}, mise en attente {{ formatDuration(call.phone_duration_pause) }}
                    </template>
                </p>
            </section>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <!-- Appelant -->
                <section>
                    <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Appelant</h3>
                    <div class="flex items-center gap-1.5">
                        <span class="font-mono text-gray-900">{{ formatPhone(call.caller) }}</span>
                        <button v-if="call.caller" type="button" class="text-gray-400 hover:text-gray-700" title="Copier le numéro" @click="copy(call.caller)">
                            <Check v-if="copied === call.caller" class="size-3.5 text-green-600" />
                            <Copy v-else class="size-3.5" />
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">{{ carrierName(call.carrier) ?? 'Opérateur inconnu' }}</p>
                    <p v-if="call.blacklist" class="mt-1 text-xs text-red-700">
                        Blacklisté{{ call.blacklist.source ? ` (${call.blacklist.source})` : '' }}{{ call.blacklist.note ? ` : ${call.blacklist.note}` : '' }}
                    </p>
                </section>

                <!-- Numéro appelé -->
                <section>
                    <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Numéro appelé</h3>
                    <span class="font-mono text-gray-900">{{ formatPhone(call.called) }}</span>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <ColorBadge v-if="source" :color="source.color" :label="source.name" />
                        <ColorBadge v-if="call.phonenumber?.company" :color="call.phonenumber.company.color" :label="call.phonenumber.company.name" />
                        <ColorBadge v-if="call.phonenumber?.provider" :color="call.phonenumber.provider.color" :label="call.phonenumber.provider.name" />
                    </div>
                </section>

                <!-- Agent et marque -->
                <section>
                    <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Agent et marque</h3>
                    <p class="text-gray-900">
                        {{ agentLabel(call.agent_name) ?? 'Aucun agent' }}
                        <span v-if="center" class="ml-1 text-xs text-gray-500">{{ center.label }}</span>
                    </p>
                    <p class="text-xs text-gray-500">
                        Marque : <span class="text-gray-800">{{ call.brand_name || '—' }}</span>
                        <template v-if="call.brand_phonenumber"> · {{ formatPhone(call.brand_phonenumber) }}</template>
                    </p>
                </section>

                <!-- Argent -->
                <section>
                    <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Argent</h3>
                    <dl class="grid grid-cols-3 gap-2 text-xs">
                        <div><dt class="text-gray-500">CA</dt><dd class="tabular-nums text-gray-900">{{ formatEuros(call.payout) }}</dd></div>
                        <div><dt class="text-gray-500">Reverse</dt><dd class="tabular-nums text-gray-900">{{ formatEuros(call.payout_source) }}</dd></div>
                        <div>
                            <dt class="text-gray-500">Bénéfice</dt>
                            <dd class="font-medium tabular-nums text-gray-900">{{ call.payout === null ? '—' : formatEuros(Number(call.payout) - Number(call.payout_source || 0)) }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <!-- Qualité audio -->
            <section v-if="mos !== null || quality">
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Qualité audio</h3>
                <p v-if="mos !== null" :class="qualityClass(mos) || 'text-gray-900'">{{ formatQuality(mos, 1) }}</p>
                <dl v-if="quality" class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-4">
                    <div><dt class="text-gray-500">Pertes reçues</dt><dd class="tabular-nums text-gray-900">{{ unit(quality.loss_pct, ' %') }}</dd></div>
                    <div><dt class="text-gray-500">Pertes envoyées</dt><dd class="tabular-nums text-gray-900">{{ unit(quality.sent_loss_pct, ' %') }}</dd></div>
                    <div><dt class="text-gray-500">Gigue médiane / max</dt><dd class="tabular-nums text-gray-900">{{ unit(quality.jitter_median_ms, ' ms') }} / {{ unit(quality.jitter_max_ms, ' ms') }}</dd></div>
                    <div><dt class="text-gray-500">Aller-retour moy. / max</dt><dd class="tabular-nums text-gray-900">{{ unit(quality.rtt_avg_ms, ' ms') }} / {{ unit(quality.rtt_max_ms, ' ms') }}</dd></div>
                </dl>
            </section>

            <!-- Remarques de l'agent -->
            <section v-if="agentNotes.length">
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Remarques de l'agent</h3>
                <ul class="space-y-1">
                    <li v-for="n in agentNotes" :key="n.label">
                        <span class="font-medium text-gray-900">{{ n.label }}</span>
                        <span v-if="n.reason" class="text-gray-600"> : {{ n.reason }}</span>
                    </li>
                </ul>
            </section>

            <!-- Évaluation -->
            <section v-if="hasRating">
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">Évaluation</h3>
                <p class="text-gray-900">
                    <span v-if="call.ratings_note === 'up'" class="text-green-700">Positive</span>
                    <span v-else-if="call.ratings_note === 'down'" class="text-red-700">Négative</span>
                    <span v-else-if="call.ratings_not_rated">Non notée{{ call.ratings_not_rated_reason ? ` : ${call.ratings_not_rated_reason}` : '' }}</span>
                    <span v-else>—</span>
                    <span v-if="call.ratings_reviewer" class="ml-1 text-xs text-gray-500">par {{ call.ratings_reviewer }}</span>
                </p>
                <div class="mt-1 flex flex-wrap gap-1">
                    <span v-if="call.ratings_warning" class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">Avertissement</span>
                    <span v-if="call.ratings_danger" class="rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-700">Grave{{ call.ratings_danger_reason ? ` : ${call.ratings_danger_reason}` : '' }}</span>
                    <span v-for="i in ratingIssues" :key="i" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700">{{ i }}</span>
                </div>
            </section>

            <!-- SMS -->
            <section v-if="hasSms">
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">SMS</h3>
                <ul class="space-y-1 text-xs text-gray-700">
                    <li v-if="call.support_sms_sent || call.support_sms_to_be_sent">
                        SMS de suivi :
                        {{ call.support_sms_sent ? 'envoyé' : 'programmé' }}{{ call.support_sms_clicked ? `, ouvert ${call.support_sms_clicked_count > 1 ? `${call.support_sms_clicked_count} fois` : ''}` : '' }}{{ call.support_sms_filled ? ', formulaire rempli' : '' }}
                        <template v-if="call.support_sms_request_recontact"> · demande à être recontacté{{ call.support_sms_recontacted ? ' (fait)' : '' }}</template>
                    </li>
                    <li v-if="smsReviews.length || call.support_sms_review_free" class="text-gray-900">
                        Avis : {{ [...smsReviews, call.support_sms_review_free].filter(Boolean).join(' · ') }}
                    </li>
                    <li v-if="call.info_sms_sent">SMS d'information : envoyé{{ call.info_sms_clicked ? ', ouvert' : '' }}</li>
                </ul>
            </section>

            <!-- Autres appels du même numéro -->
            <section v-if="call.caller">
                <h3 class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">
                    Appels de ce numéro · {{ detail.caller_total }}
                </h3>
                <p v-if="!detail.caller_calls.length" class="text-gray-500">Premier appel de ce numéro.</p>
                <ul v-else class="divide-y divide-gray-100 rounded-md border border-gray-100">
                    <li v-for="o in detail.caller_calls" :key="o.id">
                        <component
                            :is="inPanel ? 'button' : Link"
                            v-bind="inPanel ? { type: 'button' } : { href: `/calls/${o.id}` }"
                            class="flex w-full items-center gap-3 px-3 py-1.5 text-left text-xs hover:bg-gray-50"
                            @click="inPanel && emit('open', o.id)"
                        >
                            <span class="w-24 shrink-0 tabular-nums text-gray-700">{{ formatDateTime(o.called_at) }}</span>
                            <span class="w-16 shrink-0"><span class="rounded px-1 py-0.5" :class="outcome(o).tone">{{ outcome(o).label }}</span></span>
                            <span class="min-w-0 flex-1 truncate text-gray-600">{{ [agentLabel(o.agent_name), o.brand_name].filter(Boolean).join(' · ') || '—' }}</span>
                            <Play v-if="o.record_agent" class="size-3 shrink-0 text-gray-400" />
                            <span class="w-10 shrink-0 text-right tabular-nums text-gray-700">{{ formatDuration(o.total_duration) }}</span>
                        </component>
                    </li>
                </ul>
                <p v-if="detail.caller_total > detail.caller_calls.length + 1" class="mt-1 text-xs text-gray-400">
                    Les {{ detail.caller_calls.length }} plus récents.
                </p>
            </section>
        </div>
    </div>
</template>
