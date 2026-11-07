<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pilotage des effectifs (GET /pilotage de voxnode118) : par demi-heure, clients pénalisés faute d'agent libre,
 * cause (effectif, répartition entre les plateformes, agents qui ne décrochent pas), agents libres ou en trop,
 * phrases de synthèse et recommandations. Périodes : aujourd'hui (en direct), un jour, 7 derniers jours,
 * ou moyennes sur 4 à 26 semaines.
 */
class PilotageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pilotage');
    }

    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,day,week,weeks',
            'date' => 'nullable|required_if:period,day|date_format:Y-m-d',
            'weeks' => 'nullable|integer|in:4,8,12,26',
            'team' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,40}$/'],
        ]);
        abort_unless(config('services.telephony_api.url') && config('services.telephony_api.token'), 404);

        $period = $validated['period'] ?? 'today';
        $params = ['period' => $period, 'team' => $validated['team'] ?? 'all'];
        if ($period === 'day') {
            $params['date'] = $validated['date'];
        }
        if ($period === 'weeks') {
            $params['weeks'] = (int) ($validated['weeks'] ?? 8);
        }

        // Courte durée : « Aujourd'hui » est relu toutes les 5 min
        $result = Cache::remember('telephony-api:pilotage:'.http_build_query($params), 60, function () use ($params) {
            try {
                $response = Http::withToken(config('services.telephony_api.token'))
                    ->acceptJson()
                    ->timeout(30)
                    ->get(rtrim(config('services.telephony_api.url'), '/').'/pilotage', $params);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Impossible de joindre le serveur des réglages de téléphonie.'];
            }

            return $response->successful()
                ? ['ok' => true, 'data' => $response->json()]
                : ['ok' => false, 'error' => $response->json('error') ?? $response->json('message') ?? "Erreur HTTP {$response->status()}"];
        });

        return response()->json($result);
    }
}
