// Qualité audio des appels : score MOS (1 à 5) estimé à partir des statistiques WebRTC du softphone agent.
// Affichée en pourcentage, score réel entre parenthèses : « 97 % (4,27) ».
// 4,4 = appel parfait sur un réseau parfait (100 %) : un appel téléphonique n'atteint jamais 5.

export const MAX_MOS = 4.4
export const DEGRADED_MOS = 3.1

export const qualityPct = (mos) => Math.min(100, Math.round((mos / MAX_MOS) * 100))

// « 97 % (4,27) » ; tiret si rien n'a été mesuré
export function formatQuality(mos, decimals = 2) {
    if (mos === null || mos === undefined) return '—'
    return `${qualityPct(mos)} % (${Number(mos).toFixed(decimals).replace('.', ',')})`
}

// Niveau lisible d'un score, pour les infobulles
export function qualityLevel(mos) {
    if (mos === null || mos === undefined) return null
    if (mos >= 4.3) return 'excellent'
    if (mos >= 4.0) return 'très bon'
    if (mos >= 3.6) return 'correct'
    if (mos >= DEGRADED_MOS) return 'médiocre'
    return 'mauvais'
}

// Couleur du texte : neutre si c'est bon, orange si médiocre, rouge si mauvais
export function qualityClass(mos) {
    if (mos === null || mos === undefined || mos >= 3.6) return ''
    return mos >= DEGRADED_MOS ? 'text-amber-700' : 'text-red-600'
}

export const QUALITY_SCALE = [
    'Qualité audio estimée (score MOS, 4,4 = appel parfait = 100 %) :',
    '≥ 4,3 excellent (~98 %)',
    '4,0 – 4,3 très bon (~91 %)',
    '3,6 – 4,0 correct (~82 %)',
    '3,1 – 3,6 médiocre (~70 %)',
    '< 3,1 mauvais : appel compté comme dégradé',
    'Mesurée sur les appels décrochés d\'au moins 5 s.',
].join('\n')
