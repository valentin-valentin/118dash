<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Softphone du panel : identifiants WebRTC pour SIP.js, et historique de ses appels.
 * Réservé aux utilisateurs connectés (groupe auth) ; 404 si SIP_ENABLED=false.
 *
 * Historique : le serveur SIP enregistre chaque appel du softphone (audio dès la composition, fiche, journal
 * Asterisk) sous l'identifiant envoyé dans l'en-tête X-Panel-Call-Id. Le navigateur y ajoute son propre journal
 * (traces SIP, WebRTC, statistiques) en fin d'appel. Le token du serveur SIP reste ici, jamais dans le navigateur.
 */
class SoftphoneController extends Controller
{
    public function credentials(): JsonResponse
    {
        $config = config('services.softphone');

        abort_unless($config['enabled'], 404);

        // Activé mais incomplet : souvent un .env modifié sans relancer `php artisan config:cache`
        $missing = collect(['SIP_WS_URL' => 'ws_url', 'SIP_WEBRTC_URI' => 'uri', 'SIP_WEBRTC_USERNAME' => 'username', 'SIP_WEBRTC_PASSWORD' => 'password'])
            ->filter(fn ($key) => empty($config[$key]))
            ->keys();
        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'Softphone mal configuré : '.$missing->implode(', ').' manquant(s). Après une modification du .env, relancer php artisan config:cache.',
            ], 503);
        }

        return response()->json([
            'ws_url' => $config['ws_url'],
            'uri' => $config['uri'],
            'username' => $config['username'],
            'password' => $config['password'],
        ])->header('Cache-Control', 'no-store');
    }

    public function history(): Response
    {
        return Inertia::render('SoftphoneHistory');
    }

    /**
     * Appels du softphone des N derniers jours, plus récents d'abord (fiches du serveur SIP).
     */
    public function calls(Request $request): JsonResponse
    {
        $days = $request->validate(['days' => 'nullable|integer|min:1|max:90'])['days'] ?? 7;

        try {
            $response = $this->sipApi()->get('/panel-calls', ['days' => $days]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'Serveur SIP injoignable'], 502);
        }
        if (!$response->successful()) {
            return response()->json(['message' => $response->json('error') ?? "Serveur SIP : erreur HTTP {$response->status()}"], 502);
        }

        return response()->json(['calls' => $response->json('calls') ?? []]);
    }

    /**
     * Journal du navigateur d'un appel (texte brut), transmis au serveur SIP qui le range avec la fiche.
     * number et started_at servent quand l'appel n'a jamais atteint le serveur : il crée alors une fiche minimale.
     */
    public function browserLog(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9.\-]{1,64}$/', $id), 422, 'Identifiant d\'appel invalide');
        $query = $request->validate([
            'number' => 'nullable|string|max:32',
            'started_at' => 'nullable|integer',
        ]);
        $log = $request->getContent();
        abort_if(strlen($log) > 5 * 1024 * 1024, 413, 'Journal trop volumineux (5 Mo max)');

        try {
            $response = $this->sipApi()
                ->withBody($log, 'text/plain; charset=utf-8')
                ->post('/panel-calls/' . $id . '/browser-log?' . http_build_query(array_filter($query, fn ($v) => $v !== null)));
        } catch (ConnectionException) {
            return response()->json(['message' => 'Serveur SIP injoignable'], 502);
        }

        return response()->json($response->json() ?? [], $response->successful() ? 201 : 502);
    }

    /**
     * Contenu d'un journal (serveur ou navigateur) pour l'afficher dans la page : l'URL vient de la fiche
     * de l'appel, jamais du navigateur.
     */
    public function log(Request $request, string $id, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['server_log', 'browser_log'], true), 404);
        try {
            $calls = $this->sipApi()->get('/panel-calls', ['days' => 90])->json('calls') ?? [];
            $call = collect($calls)->firstWhere('id', $id);
            $url = $call[$kind . '_url'] ?? null;
            abort_unless($url, 404, 'Journal pas encore disponible');

            $response = Http::timeout(20)->get($url);
        } catch (ConnectionException) {
            return response()->json(['message' => 'Journal injoignable'], 502);
        }
        abort_unless($response->successful(), 502, "Journal : erreur HTTP {$response->status()}");

        return response()->json(['text' => $response->body()]);
    }

    private function sipApi()
    {
        abort_unless(config('services.sip_api.url') && config('services.sip_api.token'), 503, 'API du serveur SIP non configurée');

        return Http::withToken(config('services.sip_api.token'))->timeout(20)->baseUrl(config('services.sip_api.url'));
    }
}
