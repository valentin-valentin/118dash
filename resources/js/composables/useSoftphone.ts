import { reactive } from 'vue';
import { Inviter, SessionState, UserAgent, Web } from 'sip.js';
import type { Session } from 'sip.js';

/**
 * Softphone du panel (SIP.js → WebRTC → Asterisk sip.118.ae → ligne OVH).
 *
 * Un seul état pour toute l'application (module singleton) : le widget est monté à la racine,
 * à côté des pages Inertia, donc l'appel continue quand on change de page.
 * Pas de REGISTER : le panel ne fait que des appels sortants (INVITE authentifiés par digest).
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
    error: null as string | null,
    muted: false,
    volume: 1,
    open: false,
    history: readHistory(),
});

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
    if (userAgent?.isConnected()) return userAgent;

    if (!userAgent) {
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
            logLevel: 'error',
            delegate: {
                onConnect: () => (softphone.connected = true),
                onDisconnect: () => (softphone.connected = false),
            },
        });
        await userAgent.start();
    } else {
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

    Object.assign(softphone, {
        status: 'connecting',
        number,
        startedAt: Date.now(),
        earlyAt: null,
        answeredAt: null,
        endReason: null,
        error: null,
        muted: false,
    });
    rememberNumber(number);

    try {
        const ua = await ensureConnected();
        const target = UserAgent.makeURI(`sip:${number}@${domain}`);
        if (!target) throw new Error('Numéro invalide');

        const inviter = new Inviter(ua, target, {
            // Indispensable pour entendre le MGIT : l'audio du 183 est joué avant le décroché
            earlyMedia: true,
            sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } },
        });
        session = inviter;

        inviter.stateChange.addListener((state) => {
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
                    const hasSdp = !!response.message.body;
                    if (code === 183 || hasSdp) {
                        softphone.status = 'early';
                        softphone.earlyAt ??= Date.now();
                    } else if (code === 180 && softphone.status !== 'early') {
                        softphone.status = 'ringing';
                    }
                    attachRemoteAudio();
                },
                onReject: (response) => {
                    const { statusCode, reasonPhrase } = response.message;
                    softphone.endReason = rejectLabel(statusCode ?? 0, reasonPhrase);
                },
            },
        });
        attachRemoteAudio();
    } catch (e) {
        const message = e instanceof Error ? e.message : String(e);
        const denied = /NotAllowed|Permission/i.test(message);
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
    handler?.sendDtmf(tone);
}

export function setMuted(muted: boolean): void {
    const handler = session?.sessionDescriptionHandler as Web.SessionDescriptionHandler | undefined;
    handler?.localMediaStream.getAudioTracks().forEach((track) => (track.enabled = !muted));
    softphone.muted = muted;
}

export function setVolume(volume: number): void {
    softphone.volume = volume;
    if (remoteAudio) remoteAudio.volume = volume;
}

export function useSoftphone() {
    return { softphone, call, hangup, sendDtmf, setMuted, setVolume, isInCall: inCall };
}
