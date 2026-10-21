<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pilotage de la téléphonie via l'API de voxnode118 (vn.118.ae/api/telephony) :
 * répartition du trafic entre notre serveur et Voxnode, santé de notre serveur, mode des agents.
 * Le token reste côté serveur ; chaque modification est journalisée avec l'utilisateur.
 */
class TelephonyController extends Controller
{
    private const CACHE_KEY = 'telephony-api:status';

    private const CACHE_TTL = 4;

    public const PINS = ['selfhosted', 'voxnode'];

    public function status(): JsonResponse
    {
        if (!$this->configured()) {
            return response()->json(['configured' => false]);
        }

        $result = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                // URL complète : get('') ajouterait une barre finale à la base
                $response = $this->client()->get(config('services.telephony_api.url'));
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'API téléphonie injoignable'];
            }

            return $response->successful()
                ? ['ok' => true, 'data' => $response->json()]
                : ['ok' => false, 'error' => $response->json('message') ?? $response->json('error') ?? "Erreur HTTP {$response->status()}"];
        });

        return response()->json(['configured' => true, 'fetched_at' => now()->toIso8601String(), ...$result]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selfhosted_enabled' => 'sometimes|boolean',
            'selfhosted_percent' => 'sometimes|integer|between:0,100',
            'selfhosted_manual_off' => 'sometimes|boolean',
            'voxnode_manual_off' => 'sometimes|boolean',
            'pbx_recovery_minutes' => 'sometimes|integer|between:1,120',
        ], [
            'selfhosted_percent.between' => 'Part du trafic : entre 0 et 100 %.',
            'pbx_recovery_minutes.between' => 'Délai de retour : entre 1 et 120 minutes.',
        ]);
        abort_if(empty($validated), 422, 'Aucun réglage à modifier.');

        $summary = collect($validated)
            ->map(fn ($value, $key) => $key.' = '.var_export($value, true))
            ->implode(', ');

        return $this->write('put', '/settings', $validated, "Réglages : {$summary}");
    }

    public function updateAgent(Request $request, int $agent): JsonResponse
    {
        $validated = $request->validate(
            ['pin' => 'present|nullable|in:'.implode(',', self::PINS)],
            ['pin.in' => 'Mode inconnu : automatique, selfhosted ou voxnode.'],
        );

        return $this->write('put', "/agents/{$agent}", $validated, "Agent #{$agent}, mode = ".($validated['pin'] ?? 'automatique'));
    }

    private function write(string $method, string $path, array $payload, string $action): JsonResponse
    {
        abort_unless($this->configured(), 404);

        try {
            $response = $this->client()->{$method}($path, $payload);
        } catch (Throwable $e) {
            return response()->json(['message' => 'API téléphonie injoignable.'], 502);
        }

        return $this->afterWrite($response, $action);
    }

    private function afterWrite(HttpResponse $response, string $action): JsonResponse
    {
        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('message') ?? $response->json('error') ?? "Erreur de l'API téléphonie (HTTP {$response->status()})",
            ], $response->status() >= 500 ? 502 : $response->status());
        }

        Log::info("[Téléphonie] {$action}", ['user' => auth()->user()?->email]);
        Cache::forget(self::CACHE_KEY);

        return response()->json($response->json());
    }

    private function client()
    {
        return Http::withToken(config('services.telephony_api.token'))
            ->acceptJson()
            ->timeout(10)
            ->baseUrl(config('services.telephony_api.url'));
    }

    private function configured(): bool
    {
        return config('services.telephony_api.url') && config('services.telephony_api.token');
    }
}
