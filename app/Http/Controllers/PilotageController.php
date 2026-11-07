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
 * Pilotage des effectifs (GET /pilotage de voxnode118) : devenir des appels par plateforme, moment où les appelants
 * raccrochent, appels et agents demi-heure par demi-heure (agents nécessaires calculés par l'API pour décrocher 90 %
 * des appels en moins de « within » secondes), créneaux à revoir et jour par jour. Aucun calcul côté page.
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
            'within' => 'nullable|integer|between:10,300',
        ]);
        abort_unless(config('services.telephony_api.url') && config('services.telephony_api.token'), 404);

        $period = $validated['period'] ?? 'today';
        $params = ['period' => $period, 'team' => $validated['team'] ?? 'all'];
        if ($period === 'day') {
            $params['date'] = $validated['date'];
        }
        if ($period === 'weeks') {
            $params['weeks'] = (int) ($validated['weeks'] ?? 4);
        }
        // Attente maximale visée pour le calcul des agents nécessaires
        if (isset($validated['within'])) {
            $params['within'] = (int) $validated['within'];
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
