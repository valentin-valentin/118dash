<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Softphone du panel : identifiants WebRTC pour SIP.js.
 * Réservé aux utilisateurs connectés (groupe auth) ; 404 si SIP_ENABLED=false.
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
}
