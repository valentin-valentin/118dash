<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

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

        $live = Cache::remember('sip-api:live', self::LIVE_TTL, fn () => $this->fetch(['health', 'agents', 'calls']));
        $slow = Cache::remember('sip-api:slow', self::SLOW_TTL, fn () => $this->fetch(['trusted-ips', 'sounds', 'welcome']));

        return response()->json([
            'configured' => true,
            'fetched_at' => now()->toIso8601String(),
            ...$live,
            ...$slow,
        ]);
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

    public function uploadSound(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200',
            'name' => ['nullable', 'string', 'regex:/^[a-z0-9_-]+$/'],
        ], [
            'name.regex' => 'Nom : lettres minuscules, chiffres, - et _ uniquement.',
            'file.max' => 'Fichier trop gros (50 Mo maximum).',
        ]);
        abort_unless($this->configured(), 404);

        $file = $request->file('file');
        $response = $this->client()->timeout(120)
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post('/sounds', array_filter(['name' => $request->input('name')]));

        return $this->afterWrite($response, 'Ajout du son '.($request->input('name') ?: $file->getClientOriginalName()));
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
     * Accueil des appels entrants : introduction, audio d'attente, attente forcée (min / max).
     * Seuls les champs envoyés sont modifiés.
     */
    public function updateWelcome(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'intro' => 'sometimes|array',
            'intro.*' => 'string',
            'hold' => 'sometimes|array',
            'hold.*' => 'string',
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
