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

        abort_unless($config['enabled'] && $config['ws_url'] && $config['password'], 404);

        return response()->json([
            'ws_url' => $config['ws_url'],
            'uri' => $config['uri'],
            'username' => $config['username'],
            'password' => $config['password'],
        ])->header('Cache-Control', 'no-store');
    }
}
