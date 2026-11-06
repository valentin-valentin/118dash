<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Page « Serveur SIP » : état du serveur Asterisk (sip.118.ae) via son API de contrôle,
 * et gestion de ce qui se fait à la main : IP des providers, sons et playlist du service interne.
 * Agents, appels, routage et basculement sont gérés par voxnode118 (affichés ici en lecture).
 * Le token reste côté serveur : le navigateur n'appelle que les routes du panel.
 */
class SipServerController extends Controller
{
    // L'API limite à 20 req/s : le temps réel est mis en cache quelques secondes,
    // les listes qui bougent peu (IP, sons) une minute.
    private const LIVE_TTL = 4;

    private const SLOW_TTL = 60;

    // IP de Voxnode volontairement exclue des providers autorisés
    private const FORBIDDEN_IPS = ['13.36.113.246'];

    public function index(): Response
    {
        return Inertia::render('SipServer');
    }

    /**
     * Configuration (rarement modifiée) : service interne, IP des providers, infos du serveur.
     */
    public function settings(): Response
    {
        return Inertia::render('SipServerSettings');
    }

    public function status(): JsonResponse
    {
        if (!$this->configured()) {
            return response()->json(['configured' => false]);
        }

        $live = Cache::remember('sip-api:live', self::LIVE_TTL, fn () => $this->fetch(['health', 'agents', 'calls', 'opening-hours', 'queue']));
        $slow = Cache::remember('sip-api:slow', self::SLOW_TTL, fn () => $this->fetch(['trusted-ips', 'sounds', 'welcome']));

        return response()->json([
            'configured' => true,
            'fetched_at' => now()->toIso8601String(),
            ...$live,
            ...$slow,
            // Appels en cours chez Voxnode (relus toutes les 10 s au plus)
            'voxnode' => Cache::remember('voxnode-cc:ongoing', 10, fn () => $this->voxnodeOngoingCalls()),
            'numbers' => $this->activeNumbers(),
        ]);
    }

    /**
     * Numéros actifs par plateforme : routés en ce moment vers notre serveur ou vers Voxnode
     * (phonenumbers.current_endpoint, dernier routage réussi), hors numéros supprimés.
     */
    private function activeNumbers(): array
    {
        $endpoints = config('services.sip_endpoints');
        $counts = DB::table('phonenumbers')
            ->whereNull('deleted_at')
            ->whereIn('current_endpoint', [$endpoints['selfhosted'], $endpoints['voxnode']])
            ->groupBy('current_endpoint')
            ->selectRaw('current_endpoint, COUNT(*) AS n')
            ->pluck('n', 'current_endpoint');

        return [
            'selfhosted' => (int) ($counts[$endpoints['selfhosted']] ?? 0),
            'voxnode' => (int) ($counts[$endpoints['voxnode']] ?? 0),
        ];
    }

    /**
     * Appels bruts de Voxnode. Voxnode limite le nombre de requêtes : on passe par voxnode118, qui les relit
     * une seule fois toutes les 10 s pour toute l'application (régulateur compris). Appel direct à Voxnode
     * seulement si cette route n'existe pas encore (voxnode118 pas déployé) ou si l'API téléphonie n'est pas branchée.
     */
    private function voxnodeRawCalls(): array
    {
        $url = config('services.telephony_api.url');
        $token = config('services.telephony_api.token');
        if ($url && $token) {
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(5)->get($url . '/voxnode-calls');
                if ($response->status() !== 404) {
                    return $response->successful() && is_array($response->json('calls'))
                        ? ['ok' => true, 'calls' => $response->json('calls')]
                        : ['ok' => false, 'error' => $response->json('error') ?? "Erreur HTTP {$response->status()}"];
                }
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'API téléphonie injoignable'];
            }
        }

        // Secours : appel direct (Voxnode répond parfois {"error": …} en HTTP 200, limite de requêtes atteinte)
        try {
            $response = Http::withHeaders(['API-KEY' => config('services.voxnode_cc.key')])
                ->acceptJson()->timeout(5)->get(config('services.voxnode_cc.url'));
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'API Voxnode injoignable'];
        }
        if (!$response->successful()) {
            return ['ok' => false, 'error' => "Erreur HTTP {$response->status()}"];
        }
        if ($response->json('error') || !is_array($response->json('calls'))) {
            return ['ok' => false, 'error' => $response->json('error') ?? 'Réponse Voxnode inattendue'];
        }

        return ['ok' => true, 'calls' => $response->json('calls')];
    }

    /**
     * Appels en cours chez Voxnode, appelant au format +33.
     * stage : welcome (accueil) | forced (attente forcée) | queue (en attente d'un agent) | waiting (attente sans
     * détail : ancien format « queue ») | agent | transferred | agent_or_transferred (tant que Voxnode n'envoie
     * jamais « agent », « transferred » couvre les appels avec un agent et les transférés).
     */
    private function voxnodeOngoingCalls(): array
    {
        $raw = $this->voxnodeRawCalls();
        if (!$raw['ok']) {
            return $raw;
        }

        $stages = [
            'ivr' => 'welcome',
            'queue-artificial' => 'forced',
            'queue-natural' => 'queue',
            'queue' => 'waiting',
            'agent' => 'agent',
            'transferred' => 'transferred',
        ];
        $raw = collect($raw['calls']);
        // Les appels avec un agent et transférés construits par voxnode118 depuis nos données (source « ours ») sont
        // fiables ; sinon, tant que Voxnode n'envoie pas « agent », « transferred » regroupe les deux cas
        $agentStatusSent = $raw->contains(fn ($c) => ($c['status'] ?? null) === 'agent' || ($c['source'] ?? null) === 'ours');
        $calls = $raw->map(function ($c) use ($stages, $agentStatusSent) {
            $agent = ($c['agentExtension'] ?? '') ?: null;
            $stage = $stages[$c['status'] ?? ''] ?? 'waiting';
            if ($stage === 'transferred' && !$agentStatusSent) {
                $stage = 'agent_or_transferred';
            }

            return [
                'id' => $c['id'] ?? null,
                // 06... → +336...
                'caller' => preg_replace('/^0(\d{9})$/', '+33$1', (string) ($c['caller'] ?? '')),
                'called' => $c['called'] ?? null,
                'agent' => $agent,
                'stage' => $stage,
                'started_at' => isset($c['timestamp']) ? (int) $c['timestamp'] : null,
            ];
        })->values();

        return ['ok' => true, 'calls' => $calls];
    }

    /**
     * Relais d'un son du service interne vers le lecteur audio du navigateur.
     */
    public function sound(string $name): SymfonyResponse
    {
        abort_unless($this->configured() && preg_match('/^[A-Za-z0-9_.\-]+$/', $name), 404);

        $response = $this->client()->get('/sounds/'.rawurlencode($name));
        abort_unless($response->successful(), $response->status() === 404 ? 404 : 502);

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'audio/wav',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    // ─── IP des providers ────────────────────────────────────────────────────────

    public function addIp(Request $request): JsonResponse
    {
        $ip = trim((string) $request->input('ip'));
        if (in_array(explode('/', $ip)[0], self::FORBIDDEN_IPS, true)) {
            return response()->json(['message' => "{$ip} est l'IP de Voxnode : elle est exclue volontairement des providers autorisés."], 422);
        }

        return $this->write('post', '/trusted-ips', ['ip' => $ip], "Ajout de l'IP provider {$ip}");
    }

    public function deleteIp(string $ip): JsonResponse
    {
        return $this->write('delete', '/trusted-ips/'.rawurlencode($ip), [], "Retrait de l'IP provider {$ip}");
    }

    // ─── Sons et playlist du service interne ─────────────────────────────────────

    // Envoi d'un son, toujours en morceaux : le serveur web du panel refuse les requêtes de plus d'1 Mo (413).
    // Le navigateur envoie des morceaux de 512 Ko. Au dernier, la réponse part tout de suite ; l'assemblage et
    // l'envoi au serveur SIP (conversion comprise) se font ensuite, hors délai de nginx. Le navigateur suit le
    // résultat avec uploadSoundStatus().
    private const CHUNK_DIR = 'tmp/sound-uploads';

    private const SOUND_MAX_BYTES = 50 * 1024 * 1024;

    public function uploadSoundChunk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1|max:120',
            'chunk' => 'required|file|max:1024',
            'filename' => 'required|string|max:255',
            // Facultatif : le serveur SIP le nettoie et ajoute un suffixe unique (le nom final est dans sa réponse)
            'name' => 'nullable|string|max:100',
        ], [
            'chunk.max' => 'Morceau trop gros (1 Mo maximum).',
            'total.max' => 'Fichier trop gros (50 Mo maximum).',
        ]);
        abort_unless($this->configured(), 404);
        abort_if($validated['index'] >= $validated['total'], 422, 'Morceau hors du fichier.');

        $this->purgeOldChunks();
        $dir = storage_path('app/' . self::CHUNK_DIR . '/' . $validated['upload_id']);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $request->file('chunk')->move($dir, sprintf('%04d', $validated['index']));

        // Taille cumulée : même limite qu'un envoi direct
        $parts = glob($dir . '/[0-9][0-9][0-9][0-9]');
        if (array_sum(array_map('filesize', $parts)) > self::SOUND_MAX_BYTES) {
            $this->removeDir($dir);

            return response()->json(['message' => 'Fichier trop gros (50 Mo maximum).'], 422);
        }

        // Tant que tous les morceaux ne sont pas arrivés, on attend la suite
        if (count($parts) < $validated['total']) {
            return response()->json(['received' => count($parts), 'total' => $validated['total']]);
        }

        Cache::put(self::uploadKey($validated['upload_id']), ['status' => 'sending'], 3600);
        $uploadId = $validated['upload_id'];
        $filename = $validated['filename'];
        $name = $validated['name'] ?? null;
        $total = $validated['total'];
        defer(function () use ($dir, $uploadId, $filename, $name, $total) {
            ignore_user_abort(true);
            set_time_limit(0);
            try {
                $response = $this->assembleAndSend($dir, $total, $filename, $name);
                $result = ['status' => 'done', 'code' => $response->getStatusCode(), 'body' => $response->getData(true)];
            } catch (Throwable $e) {
                report($e);
                $result = ['status' => 'done', 'code' => 500, 'body' => ['message' => 'Envoi du son impossible : ' . $e->getMessage()]];
            } finally {
                $this->removeDir($dir);
            }
            Cache::put(self::uploadKey($uploadId), $result, 3600);
        });

        // Longueur explicite : le navigateur a la réponse sans attendre la fin de l'envoi au serveur SIP
        $body = json_encode(['status' => 'sending']);

        return response()->json(['status' => 'sending'], 202)->header('Content-Length', (string) strlen($body));
    }

    /**
     * Résultat de l'envoi au serveur SIP d'un son reçu en morceaux : en cours, puis la réponse du serveur SIP.
     */
    public function uploadSoundStatus(string $uploadId): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]{8,64}$/', $uploadId), 404);
        $state = Cache::get(self::uploadKey($uploadId));
        abort_unless($state, 404, 'Envoi inconnu ou expiré.');

        return $state['status'] === 'done'
            ? response()->json($state['body'], $state['code'])
            : response()->json(['status' => 'sending'], 202);
    }

    private static function uploadKey(string $uploadId): string
    {
        return "sound-upload:{$uploadId}";
    }

    /** Assemble les morceaux dans l'ordre, puis transmet le fichier entier au serveur SIP. */
    private function assembleAndSend(string $dir, int $total, string $filename, ?string $name): JsonResponse
    {
        $assembled = $dir . '/assembled';
        $out = fopen($assembled, 'wb');
        for ($i = 0; $i < $total; $i++) {
            $part = sprintf('%s/%04d', $dir, $i);
            if (!is_file($part)) {
                fclose($out);

                return response()->json(['message' => "Morceau {$i} manquant : renvoyer le fichier."], 422);
            }
            $in = fopen($part, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        return $this->sendSound($assembled, $filename, $name);
    }

    private function sendSound(string $path, string $originalName, ?string $name): JsonResponse
    {
        try {
            $response = $this->client()->timeout(600)
                ->attach('file', fopen($path, 'r'), $originalName)
                ->post('/sounds', array_filter(['name' => $name]));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Serveur SIP injoignable pendant l\'envoi du son.'], 502);
        }

        return $this->afterWrite($response, 'Ajout du son ' . ($name ?: $originalName));
    }

    // Morceaux abandonnés (onglet fermé en cours d'envoi) : supprimés au bout d'une heure
    private function purgeOldChunks(): void
    {
        foreach (glob(storage_path('app/' . self::CHUNK_DIR . '/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 3600) {
                $this->removeDir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function deleteSound(string $name): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9_.\-]+$/', $name), 404);

        return $this->write('delete', '/sounds/'.rawurlencode($name), [], "Suppression du son {$name}");
    }

    public function updatePlaylist(Request $request): JsonResponse
    {
        $validated = $request->validate(['sounds' => 'present|array', 'sounds.*' => 'string']);

        return $this->write('put', '/internal-playlist', ['sounds' => array_values($validated['sounds'])], 'Playlist du service interne : '.(implode(', ', $validated['sounds']) ?: '(vide)'));
    }

    /**
     * Accueil des appels entrants : introduction, audio d'attente, attente forcée (min / max), message avant transfert.
     * Seuls les champs envoyés sont modifiés.
     */
    public function updateWelcome(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'intro' => 'sometimes|array',
            'intro.*' => 'string',
            'hold' => 'sometimes|array',
            'hold.*' => 'string',
            // Message joué au client avant un transfert vers une marque
            'transfer_announcement' => 'sometimes|array',
            'transfer_announcement.*' => 'string',
            'wait_min' => 'sometimes|integer|between:0,1800',
            'wait_max' => 'sometimes|integer|between:0,1800|gte:wait_min',
        ], [
            'wait_max.gte' => 'La durée maximale doit être supérieure ou égale à la durée minimale.',
            'wait_min.between' => 'Durée minimale : entre 0 et 1800 secondes.',
            'wait_max.between' => 'Durée maximale : entre 0 et 1800 secondes.',
        ]);
        abort_if(empty($validated), 422, 'Aucun réglage à modifier.');

        $summary = collect($validated)
            ->map(fn ($value, $key) => $key.' = '.(is_array($value) ? '['.implode(', ', $value).']' : $value))
            ->implode(', ');

        return $this->write('put', '/welcome', $validated, "Accueil des appels : {$summary}");
    }

    /**
     * Requête d'écriture vers l'API, journalisée avec l'utilisateur du panel.
     */
    private function write(string $method, string $path, array $payload, string $action): JsonResponse
    {
        abort_unless($this->configured(), 404);

        try {
            $response = $this->client()->acceptJson()->{$method}($path, $payload);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Serveur SIP injoignable.'], 502);
        }

        return $this->afterWrite($response, $action);
    }

    private function afterWrite(HttpResponse $response, string $action): JsonResponse
    {
        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('error') ?? "Erreur du serveur SIP (HTTP {$response->status()})",
            ], $response->status() >= 500 ? 502 : $response->status());
        }

        Log::info("[Serveur SIP] {$action}", ['user' => auth()->user()?->email]);
        // Les listes changent : la page doit les relire tout de suite
        Cache::forget('sip-api:slow');

        return response()->json($response->json() ?? ['ok' => true]);
    }

    /**
     * Appels GET en parallèle. Chaque route renvoie { ok, status, data | error }.
     */
    private function fetch(array $paths): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($path) => $pool->as($path)->withToken(config('services.sip_api.token'))
                    ->acceptJson()->timeout(5)->get(config('services.sip_api.url').'/'.$path),
                $paths,
            ));
        } catch (Throwable $e) {
            return array_fill_keys($paths, ['ok' => false, 'status' => 0, 'error' => 'API injoignable']);
        }

        $result = [];
        foreach ($paths as $path) {
            $response = $responses[$path] ?? null;
            $result[$path] = $response instanceof HttpResponse
                ? [
                    'ok' => $response->successful(),
                    'status' => $response->status(),
                    'data' => $response->json(),
                    'error' => $response->successful() ? null : ($response->json('error') ?? "Erreur HTTP {$response->status()}"),
                ]
                : ['ok' => false, 'status' => 0, 'error' => 'API injoignable'];
        }

        return $result;
    }

    private function client()
    {
        return Http::withToken(config('services.sip_api.token'))->timeout(10)->baseUrl(config('services.sip_api.url'));
    }

    private function configured(): bool
    {
        return config('services.sip_api.url') && config('services.sip_api.token');
    }
}
