<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Testeur de softphone : le panel joue le client et appelle notre serveur SIP (sip:test@…, en-têtes X-Test-*).
 * L'appel suit le vrai parcours et ne fait sonner que les comptes de test (file « test »).
 *   - comptes de test et numéros proposés : GET /softphone-tester de voxnode118 ;
 *   - suivi : GET /calls du serveur SIP, seulement les appels du testeur (caller_name = TEST, même linkedid) ;
 *   - raccrocher côté serveur : POST /calls/{uniqueid}/hangup, seulement sur un appel du testeur.
 */
class SoftphoneTesterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SoftphoneTester');
    }

    public function data(): JsonResponse
    {
        abort_unless(config('services.telephony_api.url') && config('services.telephony_api.token'), 503, 'API de téléphonie non configurée');
        try {
            $response = Http::withToken(config('services.telephony_api.token'))->acceptJson()->timeout(10)
                ->get(rtrim(config('services.telephony_api.url'), '/').'/softphone-tester');
        } catch (ConnectionException) {
            return response()->json(['message' => 'Impossible de joindre le serveur des réglages de téléphonie.'], 502);
        }
        if (!$response->successful()) {
            return response()->json(['message' => $response->json('message') ?? "Erreur HTTP {$response->status()}"], 502);
        }

        return response()->json($response->json());
    }

    /** Canaux des appels du testeur en cours, groupés par linkedid. */
    public function calls(): JsonResponse
    {
        try {
            $channels = $this->testChannels();
        } catch (ConnectionException) {
            return response()->json(['message' => 'Serveur SIP injoignable'], 502);
        }

        return response()->json(['channels' => $channels, 'at' => now()->toIso8601String()]);
    }

    /** Raccroche tous les canaux d'un appel du testeur (appel bloqué). */
    public function hangup(string $linkedid): JsonResponse
    {
        abort_unless(preg_match('/^[\d.]+$/', $linkedid), 422, 'Identifiant d\'appel invalide');
        try {
            $channels = collect($this->testChannels())->where('linkedid', $linkedid);
            abort_if($channels->isEmpty(), 404, 'Appel de test introuvable ou déjà terminé');
            foreach ($channels as $channel) {
                $this->sipApi()->post('/calls/'.rawurlencode($channel['uniqueid']).'/hangup');
            }
        } catch (ConnectionException) {
            return response()->json(['message' => 'Serveur SIP injoignable'], 502);
        }
        Log::info('[Testeur de softphone] raccroché côté serveur', ['linkedid' => $linkedid, 'channels' => $channels->count(), 'user' => auth()->user()?->email]);

        return response()->json(['hungUp' => $channels->count()]);
    }

    /** Canaux du serveur SIP qui appartiennent à un appel du testeur (un canal caller_name = TEST dans le linkedid). */
    private function testChannels(): array
    {
        $channels = $this->sipApi()->get('/calls')->json('calls') ?? [];
        $testIds = collect($channels)->where('caller_name', 'TEST')->map(fn ($c) => $c['linkedid'] ?? $c['uniqueid'])->unique()->all();

        return collect($channels)->filter(fn ($c) => in_array($c['linkedid'] ?? $c['uniqueid'], $testIds, true))->values()->all();
    }

    private function sipApi()
    {
        abort_unless(config('services.sip_api.url') && config('services.sip_api.token'), 503, 'API du serveur SIP non configurée');

        return Http::withToken(config('services.sip_api.token'))->acceptJson()->timeout(10)->baseUrl(config('services.sip_api.url'));
    }
}
