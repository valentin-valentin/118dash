<?php

namespace App\Http\Controllers;

use App\Models\Phonenumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit RSVA : vérifie auprès de l'API interne Numero08 que nos numéros
 * sont toujours officiellement en service. La page appelle check() pour
 * chaque numéro, l'un après l'autre.
 */
class RsvaAuditController extends Controller
{
    private const API_URL = 'https://numero08.com/internal/number-status';

    private const API_TOKEN = '84571f727c1a5d0d1dfeac41b094b81e0b390086d264bd9f';

    public function index(): Response
    {
        return Inertia::render('RsvaAudit');
    }

    /**
     * Liste des numéros à vérifier.
     */
    public function numbers(): JsonResponse
    {
        $numbers = Phonenumber::with(['company:id,name,color', 'provider:id,name,color'])
            ->orderBy('phonenumber')
            ->get()
            ->map(fn (Phonenumber $p) => [
                'id' => $p->id,
                'phonenumber' => $p->phonenumber,
                'assigned' => $p->assigned_at !== null,
                'company' => $p->company?->only(['name', 'color']),
                'provider' => $p->provider?->only(['name', 'color']),
            ]);

        return response()->json($numbers);
    }

    /**
     * Statut officiel d'un numéro.
     */
    public function check(Phonenumber $phonenumber): JsonResponse
    {
        try {
            $response = Http::timeout(15)->acceptJson()->get(self::API_URL, [
                'token' => self::API_TOKEN,
                'number' => $phonenumber->phonenumber,
            ]);
        } catch (ConnectionException $e) {
            return response()->json(['group' => 'error', 'error' => 'Connexion impossible à l\'API']);
        }

        // Limite de 120 appels / minute atteinte : la page attend puis réessaie
        if ($response->status() === 429) {
            return response()->json(['message' => 'Limite de débit atteinte'], 429);
        }

        if (!$response->successful()) {
            return response()->json([
                'group' => 'error',
                'error' => match ($response->status()) {
                    404 => 'API inaccessible (jeton invalide)',
                    422 => 'Numéro refusé par l\'API : ' . ($response->json('error') ?? $response->body()),
                    default => "Erreur HTTP {$response->status()}",
                },
            ]);
        }

        $data = $response->json();

        if (empty($data['known']) || empty($data['found'])) {
            return response()->json([
                'group' => 'unknown',
                'status_label' => ($data['reason'] ?? null) === 'not_in_registry'
                    ? 'Absent des données officielles'
                    : 'Aucun état officiel en vigueur',
                'data_as_of' => $data['data_as_of'] ?? null,
                'public_url' => $data['public_url'] ?? null,
            ]);
        }

        return response()->json([
            'group' => in_array($data['status'] ?? null, ['active', 'undeclared', 'withdrawn', 'closed'], true)
                ? $data['status']
                : 'unknown',
            'status_label' => $data['status_label'] ?? null,
            'operator' => $data['operator']['name'] ?? null,
            'holder' => $data['holder']['name'] ?? null,
            'service' => $data['service']['name'] ?? null,
            'since' => $data['period']['from'] ?? null,
            'data_as_of' => $data['data_as_of'] ?? null,
            'public_url' => $data['public_url'] ?? null,
        ]);
    }
}
