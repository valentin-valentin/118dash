import { reactive } from 'vue';
import { Inviter, SessionState, UserAgent, Web } from 'sip.js';
import type { Session } from 'sip.js';

/**
 * Softphone du panel (SIP.js → WebRTC → Asterisk sip.118.ae → ligne OVH).
 *
 * Un seul état pour toute l'application (module singleton) : le widget est monté à la racine,
 * à côté des pages Inertia, donc l'appel continue quand on change de page.
 * Pas de REGISTER : le panel ne fait que des appels sortants (INVITE authentifiés par digest).
 *
 * Journal d'appel : chaque appel a un identifiant (en-tête X-Panel-Call-Id), avec lequel le serveur SIP range
 * l'enregistrement, la fiche et son journal Asterisk. Le navigateur note tout, du clic jusqu'à la fin : traces SIP.js
 * complètes (messages SIP envoyés et reçus), états du softphone, WebSocket, ICE, micro, statistiques audio.
 * À la fin, le journal part au serveur SIP via le panel (page Historique du softphone).
 */

export type SoftphoneStatus =
    | 'idle' // aucun appel
    | 'connecting' // connexion au serveur
    | 'calling' // INVITE envoyé
    | 'early' // 183 : annonce (MGIT) ou sonnerie diffusée avant le décroché
    | 'ringing' // 180 sans audio
    | 'established' // décroché
    | 'ended'; // terminé (voir endReason)

interface Credentials {
    ws_url: string;
    uri: string;
    username: string;
    password: string;
}

const HISTORY_KEY = 'softphone-history';

function readHistory(): string[] {
    try {
        return JSON.parse(localStorage.getItem(HISTORY_KEY) ?? '[]');
    } catch {
        return [];
    }
}

export const softphone = reactive({
    status: 'idle' as SoftphoneStatus,
    connected: false,
    number: '',
    // Horodatages (ms) pour afficher les durées
    startedAt: null as number | null,
    earlyAt: null as number | null,
    answeredAt: null as number | null,
    endReason: null as string | null,
    // Réponse SIP de refus (code et motif), pour le testeur de softphone
    rejected: null as { code: number; reason: string } | null,
    error: null as string | null,
    muted: false,
    volume: 1,
    open: false,
    history: readHistory(),
});

// Journal de l'appel en cours : null hors appel (les traces SIP.js entre deux appels ne sont pas gardées)
interface CallLog {
    id: string;
    number: string;
    startedAt: number;
    lines: string[];
    sent: boolean;
}
let callLog: CallLog | null = null;
let statsTimer: ReturnType<typeof setInterval> | null = null;

function newCallId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** Ajoute une ligne horodatée (ms) au journal de l'appel en cours. */
function log(source: string, message: string) {
    if (!callLog) return;
    const now = Date.now();
    const elapsed = ((now - callLog.startedAt) / 1000).toFixed(3).padStart(8);
    callLog.lines.push(`${new Date(now).toISOString()} +${elapsed}s [${source}] ${message}`);
}

/** Envoie le journal d'un appel au serveur SIP (une seule fois par appel). */
function sendCallLog(keepalive = false) {
    const current = callLog;
    if (!current || current.sent) return;
    current.sent = true;
    callLog = null;
    stopStats();
    clearTimeout(sendTimer);

    const query = new URLSearchParams({ number: current.number, started_at: String(Math.floor(current.startedAt / 1000)) });
    const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    fetch(`/data/softphone/calls/${current.id}/browser-log?${query}`, {
        method: 'POST',
        keepalive,
        headers: {
            'Content-Type': 'text/plain; charset=utf-8',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf[1]) : '',
        },
        body: current.lines.join('\n') + '\n',
    }).catch(() => {});
}

// Fin d'appel : on note encore 2 s (réponse au BYE ou au CANCEL, fermeture WebRTC) avant d'envoyer
let sendTimer: ReturnType<typeof setTimeout> | undefined;
function sendCallLogSoon() {
    stopStats();
    clearTimeout(sendTimer);
    sendTimer = setTimeout(() => sendCallLog(), 2000);
}

// Page fermée ou rechargée pendant un appel : on tente quand même d'envoyer le journal
if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => {
        log('panel', 'page fermée ou rechargée pendant l\'appel');
        sendCallLog(true);
    });
}

/** Statistiques WebRTC toutes les 5 s : pertes, gigue, aller-retour. */
function startStats(peerConnection: RTCPeerConnection) {
    stopStats();
    statsTimer = setInterval(async () => {
        try {
            const report = await peerConnection.getStats();
            const parts: string[] = [];
            report.forEach((stat: any) => {
                if (stat.type === 'inbound-rtp' && stat.kind === 'audio') {
                    parts.push(`reçu paquets=${stat.packetsReceived} perdus=${stat.packetsLost} gigue=${Math.round((stat.jitter ?? 0) * 1000)}ms`);
                } else if (stat.type === 'outbound-rtp' && stat.kind === 'audio') {
                    parts.push(`envoyé paquets=${stat.packetsSent}`);
                } else if (stat.type === 'remote-inbound-rtp' && stat.kind === 'audio') {
                    parts.push(`distant perdus=${stat.packetsLost} gigue=${Math.round((stat.jitter ?? 0) * 1000)}ms rtt=${Math.round((stat.roundTripTime ?? 0) * 1000)}ms`);
                } else if (stat.type === 'candidate-pair' && stat.nominated && stat.state === 'succeeded') {
                    parts.push(`paire ICE rtt=${Math.round((stat.currentRoundTripTime ?? 0) * 1000)}ms`);
                }
            });
            if (parts.length) log('stats', parts.join(' | '));
        } catch (e) {
            log('stats', `getStats impossible : ${e instanceof Error ? e.message : e}`);
        }
    }, 5000);
}

function stopStats() {
    if (statsTimer) clearInterval(statsTimer);
    statsTimer = null;
}

/** Événements de la connexion WebRTC : ICE, connexion, pistes reçues. */
function watchPeerConnection(peerConnection: RTCPeerConnection) {
    peerConnection.addEventListener('icegatheringstatechange', () => log('ice', `collecte ${peerConnection.iceGatheringState}`));
    peerConnection.addEventListener('iceconnectionstatechange', () => log('ice', `connexion ${peerConnection.iceConnectionState}`));
    peerConnection.addEventListener('connectionstatechange', () => {
        log('webrtc', `connexion ${peerConnection.connectionState}`);
        if (peerConnection.connectionState === 'connected') startStats(peerConnection);
    });
    peerConnection.addEventListener('signalingstatechange', () => log('webrtc', `signalisation ${peerConnection.signalingState}`));
    peerConnection.addEventListener('icecandidate', (e) => {
        log('ice', e.candidate ? `candidat local ${e.candidate.candidate}` : 'fin des candidats locaux');
    });
    peerConnection.addEventListener('icecandidateerror', (e: any) => log('ice', `erreur candidat ${e.errorCode} ${e.errorText ?? ''} ${e.url ?? ''}`));
    peerConnection.addEventListener('track', (e) => log('webrtc', `piste reçue ${e.track.kind}`));
}

let userAgent: UserAgent | null = null;
let session: Session | null = null;
let domain = '';
const remoteAudio = typeof Audio !== 'undefined' ? new Audio() : null;
if (remoteAudio) remoteAudio.autoplay = true;

const inCall = () => ['connecting', 'calling', 'early', 'ringing', 'established'].includes(softphone.status);
export const isInCall = inCall;

/**
 * Nettoie la saisie : espaces, points, tirets retirés.
 * Formats acceptés par le serveur : 0XXXXXXXXX, +33XXXXXXXXX, 0033XXXXXXXXX, 118XXX, 600 (test d'écho).
 */
export function normalizeNumber(input: string): string | null {
    const value = input.replace(/[\s.\-()]/g, '');
    return /^(?:(?:\+33|0033|0)[1-9]\d{8}|118\d{3}|600)$/.test(value) ? value : null;
}

async function ensureConnected(): Promise<UserAgent> {
    if (userAgent?.isConnected()) {
        log('websocket', 'déjà connecté');
        return userAgent;
    }

    if (!userAgent) {
        log('panel', 'récupération des identifiants du softphone');
        const response = await fetch('/data/softphone/credentials', { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            const body = await response.json().catch(() => null);
            throw new Error(body?.message || `softphone indisponible (HTTP ${response.status})`);
        }
        const credentials: Credentials = await response.json();
        const uri = UserAgent.makeURI(credentials.uri);
        if (!uri) throw new Error('Configuration du softphone invalide');
        domain = uri.host;

        userAgent = new UserAgent({
            uri,
            transportOptions: { server: credentials.ws_url },
            authorizationUsername: credentials.username,
            authorizationPassword: credentials.password,
            displayName: 'Panel',
            // Traces complètes (messages SIP compris) vers le journal de l'appel ; la console ne garde que les erreurs
            logLevel: 'debug',
            logBuiltinEnabled: false,
            logConnector: (level, category, label, content) => {
                if (level === 'error') console.error(`[${category}]`, content);
                log(`sip.js ${level} ${category}${label ? ` ${label}` : ''}`, content);
            },
            delegate: {
                onConnect: () => {
                    softphone.connected = true;
                    log('websocket', 'connecté');
                },
                onDisconnect: (error) => {
                    softphone.connected = false;
                    log('websocket', `déconnecté${error ? ` : ${error.message}` : ''}`);
                },
            },
        });
        log('websocket', `connexion à ${credentials.ws_url}`);
        await userAgent.start();
    } else {
        log('websocket', 'reconnexion');
        await userAgent.reconnect();
    }
    softphone.connected = true;
    return userAgent;
}

function attachRemoteAudio() {
    const handler = session?.sessionDescriptionHandler as Web.SessionDescriptionHandler | undefined;
    if (!handler || !remoteAudio) return;
    // Même MediaStream pendant tout l'appel : les pistes reçues (183 puis 200) s'y ajoutent
    if (remoteAudio.srcObject !== handler.remoteMediaStream) {
        remoteAudio.srcObject = handler.remoteMediaStream;
    }
    remoteAudio.volume = softphone.volume;
    remoteAudio.play().catch(() => {});
}

function finish(reason: string | null) {
    log('panel', `fin de l'appel : ${reason ?? '—'}`);
    sendCallLogSoon();
    softphone.status = 'ended';
    softphone.endReason = reason;
    softphone.muted = false;
    session = null;
    if (remoteAudio) remoteAudio.srcObject = null;
}

function rememberNumber(number: string) {
    softphone.history = [number, ...softphone.history.filter((n) => n !== number)].slice(0, 6);
    try {
        localStorage.setItem(HISTORY_KEY, JSON.stringify(softphone.history));
    } catch {
        // stockage indisponible : pas d'historique, sans conséquence
    }
}

/** Lance un appel. Ouvre le widget pour suivre l'appel. */
export async function call(input: string): Promise<void> {
    const number = normalizeNumber(input);
    softphone.open = true;
    if (!number) {
        softphone.error = 'Numéro invalide : 0XXXXXXXXX, +33…, 0033… ou 118XXX';
        return;
    }
    if (inCall()) {
        softphone.error = 'Un appel est déjà en cours';
        return;
    }
    rememberNumber(number);
    await dial(number, number, []);
}

export interface TestCallOptions {
    caller: string; // numéro appelant, vide = masqué
    called: string; // numéro appelé
    skipWait: boolean; // saute l'attente forcée
    realTransfer: boolean; // transfert réel vers la marque (sinon simulé)
}

/**
 * Appel du testeur de softphone : sip:test@… avec les en-têtes X-Test-*. Le serveur SIP joue le parcours d'un client
 * (appelant et appelé choisis) et ne fait sonner que les comptes de test.
 */
export async function callTest(options: TestCallOptions): Promise<boolean> {
    if (inCall()) {
        softphone.error = 'Un appel est déjà en cours';
        return false;
    }
    const caller = options.caller.replace(/[^\d+]/g, '');
    const called = options.called.replace(/[^\d+]/g, '');
    if (!called) {
        softphone.error = 'Numéro appelé obligatoire';
        return false;
    }
    const headers = [`X-Test-Caller: ${caller}`, `X-Test-Called: ${called}`];
    if (options.skipWait) headers.push('X-Test-Skip-Wait: 1');
    if (options.realTransfer) headers.push('X-Test-Real-Transfer: 1');
    await dial('test', `Test ${caller || 'masqué'} → ${called}`, headers);
    return true;
}

/** Appel sortant vers sip:<target>@domaine ; display = ce que le widget affiche. */
async function dial(target: string, display: string, extraHeaders: string[]): Promise<void> {
    const number = display;

    sendCallLog();
    const startedAt = Date.now();
    callLog = { id: newCallId(), number, startedAt, lines: [], sent: false };
    log('panel', `appel ${number} (id ${callLog.id})`);
    log('panel', `navigateur ${navigator.userAgent}`);
    try {
        const permission = await navigator.permissions?.query({ name: 'microphone' as PermissionName });
        log('micro', `permission ${permission?.state ?? 'inconnue'}`);
    } catch {
        log('micro', 'permission non consultable');
    }

    Object.assign(softphone, {
        status: 'connecting',
        number,
        startedAt,
        earlyAt: null,
        answeredAt: null,
        endReason: null,
        rejected: null,
        error: null,
        muted: false,
    });
    try {
        const ua = await ensureConnected();
        const uri = UserAgent.makeURI(`sip:${target}@${domain}`);
        if (!uri) throw new Error('Numéro invalide');

        const callId = callLog?.id ?? newCallId();
        const inviter = new Inviter(ua, uri, {
            // Indispensable pour entendre le MGIT : l'audio du 183 est joué avant le décroché
            earlyMedia: true,
            // Relie l'enregistrement et le journal du serveur SIP à ce journal
            extraHeaders: [`X-Panel-Call-Id: ${callId}`, ...extraHeaders],
            delegate: {
                onSessionDescriptionHandler: (handler) => {
                    const peerConnection = (handler as Web.SessionDescriptionHandler).peerConnection;
                    if (peerConnection) watchPeerConnection(peerConnection);
                },
            },
            sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } },
        });
        session = inviter;

        inviter.stateChange.addListener((state) => {
            log('session', `état ${state}`);
            if (state === SessionState.Established) {
                softphone.status = 'established';
                softphone.answeredAt = Date.now();
                attachRemoteAudio();
            } else if (state === SessionState.Terminated && session === inviter) {
                finish(softphone.answeredAt ? 'Appel terminé' : (softphone.endReason ?? 'Appel terminé'));
            }
        });

        softphone.status = 'calling';
        await inviter.invite({
            requestDelegate: {
                onProgress: (response) => {
                    const code = response.message.statusCode ?? 0;
                    log('session', `réponse provisoire ${code} ${response.message.reasonPhrase ?? ''}`);
                    const hasSdp = !!response.message.body;
                    if (code === 183 || hasSdp) {
                        softphone.status = 'early';
                        softphone.earlyAt ??= Date.now();
                    } else if (code === 180 && softphone.status !== 'early') {
                        softphone.status = 'ringing';
                    }
                    attachRemoteAudio();
                },
                onAccept: (response) => log('session', `réponse ${response.message.statusCode} ${response.message.reasonPhrase ?? ''}`),
                onReject: (response) => {
                    const { statusCode, reasonPhrase } = response.message;
                    log('session', `refus ${statusCode} ${reasonPhrase ?? ''}`);
                    softphone.endReason = rejectLabel(statusCode ?? 0, reasonPhrase);
                    softphone.rejected = { code: statusCode ?? 0, reason: reasonPhrase ?? '' };
                },
            },
        });
        attachRemoteAudio();
    } catch (e) {
        const message = e instanceof Error ? e.message : String(e);
        const denied = /NotAllowed|Permission/i.test(message);
        log('erreur', e instanceof Error ? `${e.name} : ${e.message}` : message);
        softphone.error = denied ? 'Accès au micro refusé par le navigateur' : `Appel impossible : ${message}`;
        finish(softphone.error);
    }
}

function rejectLabel(code: number, reason?: string): string {
    if (code === 486 || code === 600) return 'Occupé';
    if (code === 404 || code === 484) return 'Numéro inconnu ou refusé';
    if (code === 403) return 'Appel refusé par le serveur';
    if (code === 480 || code === 408) return 'Pas de réponse';
    if (code === 503) return 'Ligne indisponible';
    return `Refusé (${code}${reason ? ` ${reason}` : ''})`;
}

/** Raccroche, annule ou refuse selon l'état de l'appel. */
export function hangup(): void {
    log('panel', 'clic raccrocher');
    const current = session;
    if (!current) {
        if (softphone.status === 'connecting') finish('Annulé');
        return;
    }
    if (current.state === SessionState.Established) {
        current.bye().catch(() => {});
    } else if (current instanceof Inviter && [SessionState.Initial, SessionState.Establishing].includes(current.state)) {
        current.cancel().catch(() => {});
        softphone.endReason = 'Annulé';
    }
}

/** Touches pendant l'appel (menus vocaux) : DTMF RFC 4733. */
export function sendDtmf(tone: string): void {
    const handler = session?.sessionDescriptionHandler as Web.SessionDescriptionHandler | undefined;
    log('panel', `touche ${tone}`);
    handler?.sendDtmf(tone);
}

export function setMuted(muted: boolean): void {
    const handler = session?.sessionDescriptionHandler as Web.SessionDescriptionHandler | undefined;
    handler?.localMediaStream.getAudioTracks().forEach((track) => (track.enabled = !muted));
    softphone.muted = muted;
    log('panel', muted ? 'micro coupé' : 'micro réactivé');
}

export function setVolume(volume: number): void {
    softphone.volume = volume;
    if (remoteAudio) remoteAudio.volume = volume;
}

export function useSoftphone() {
    return { softphone, call, callTest, hangup, sendDtmf, setMuted, setVolume, isInCall: inCall };
}
