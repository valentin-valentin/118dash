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
 * Affluence et effectifs : par jour de semaine et par demi-heure, agents au travail face aux agents nécessaires
 * (GET /staffing de voxnode118, statistiques calculées chaque nuit jusqu'à la veille).
 */
class StaffingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Staffing');
    }

    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weeks' => 'nullable|integer|between:1,26',
            'team' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,40}$/'],
        ]);
        abort_unless(config('services.telephony_api.url') && config('services.telephony_api.token'), 404);

        $params = ['weeks' => (int) ($validated['weeks'] ?? 8), 'team' => $validated['team'] ?? 'all'];

        // Courte durée : un changement de l'occupation visée doit se voir vite
        $result = Cache::remember('telephony-api:staffing:'.http_build_query($params), 60, function () use ($params) {
            try {
                $response = Http::withToken(config('services.telephony_api.token'))
                    ->acceptJson()
                    ->timeout(30)
                    ->get(rtrim(config('services.telephony_api.url'), '/').'/staffing', $params);
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
