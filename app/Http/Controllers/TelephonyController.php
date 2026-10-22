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
            // Régulateur : déplacements automatiques des agents entre les plateformes
            'regulator_max_moves' => 'sometimes|integer|between:1,10',
            'regulator_cooldown_minutes' => 'sometimes|integer|between:0,60',
            'regulator_wait_seconds' => 'sometimes|integer|between:0,600',
            // Appelants prioritaires : pas d'attente forcée
            'priority_min_duration' => 'sometimes|integer|between:1,3600',
            'priority_window_hours' => 'sometimes|integer|between:1,720',
            // Horaires d'ouverture : toujours la semaine entière, null = fermé toute la journée
            'opening_hours' => ['sometimes', 'array', function ($attribute, $value, $fail) {
                if ($error = self::openingHoursError($value)) {
                    $fail("Horaires d'ouverture : {$error}");
                }
            }],
        ], [
            'selfhosted_percent.between' => 'Part du trafic : entre 0 et 100 %.',
            'pbx_recovery_minutes.between' => 'Délai de retour : entre 1 et 120 minutes.',
            'regulator_max_moves.between' => 'Agents déplacés par cycle : entre 1 et 10.',
            'regulator_cooldown_minutes.between' => 'Délai entre deux déplacements : entre 0 et 60 minutes.',
            'regulator_wait_seconds.between' => "Seuil d'attente : entre 0 et 600 secondes.",
            'priority_min_duration.between' => 'Durée minimale d\'appel : entre 1 et 3600 secondes.',
            'priority_window_hours.between' => 'Période : entre 1 et 720 heures.',
        ]);
        abort_if(empty($validated), 422, 'Aucun réglage à modifier.');

        $summary = collect($validated)
            ->map(fn ($value, $key) => $key.' = '.(is_array($value) ? json_encode($value) : var_export($value, true)))
            ->implode(', ');

        return $this->write('put', '/settings', $validated, "Réglages : {$summary}");
    }

    public const DAYS = [
        'monday' => 'lundi', 'tuesday' => 'mardi', 'wednesday' => 'mercredi', 'thursday' => 'jeudi',
        'friday' => 'vendredi', 'saturday' => 'samedi', 'sunday' => 'dimanche',
    ];

    /**
     * Mêmes règles que l'API : les 7 jours, chacun null ou {open, close} en HH:MM, open < close.
     */
    private static function openingHoursError(array $week): ?string
    {
        if (array_diff(array_keys($week), array_keys(self::DAYS)) || count($week) !== 7) {
            return 'les 7 jours sont obligatoires.';
        }
        foreach (self::DAYS as $day => $label) {
            $hours = $week[$day];
            if ($hours === null) {
                continue;
            }
            $keys = is_array($hours) ? array_keys($hours) : [];
            sort($keys);
            if ($keys !== ['close', 'open']) {
                return "{$label} : ouverture et fermeture attendues.";
            }
            foreach ($hours as $time) {
                if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    return "{$label} : heure invalide (format HH:MM, 23:59 au plus tard).";
                }
            }
            if ($hours['open'] >= $hours['close']) {
                return "{$label} : l'ouverture doit précéder la fermeture.";
            }
        }

        return null;
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
