<?php

namespace App\Http\Controllers;

use App\Models\Phonenumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    // Tous nos numéros doivent être à 0,80 € / min (code tarif RSVA)
    private const EXPECTED_TARIFF = 'D080';

    public function index(): Response
    {
        return Inertia::render('RsvaAudit');
    }

    /**
     * Liste des numéros à vérifier, avec les mêmes filtres que la page Numéros.
     */
    public function numbers(Request $request): JsonResponse
    {
        $numbers = Phonenumber::with(['company:id,name,color', 'provider:id,name,color'])
            ->filter($request->all())
            ->orderBy('phonenumber')
            ->get()
            ->map(fn (Phonenumber $p) => [
                'id' => $p->id,
                'phonenumber' => $p->phonenumber,
                'assigned' => $p->assigned_at !== null,
                'will_be_deleted' => (bool) $p->will_be_deleted,
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
                    ? 'Inconnu'
                    : 'Aucun état en vigueur',
                'public_url' => $data['public_url'] ?? null,
            ]);
        }

        $tariff = $data['tariff']['code'] ?? null;

        return response()->json([
            // Retiré, fermé ou sans service déclaré : même chose pour nous.
            // En service mais pas au bon tarif : groupe à part.
            'group' => match ($data['status'] ?? null) {
                'active' => $tariff === self::EXPECTED_TARIFF ? 'active' : 'bad_tariff',
                'withdrawn', 'closed', 'undeclared' => 'inactive',
                default => 'unknown',
            },
            'status_label' => ($data['status'] ?? null) === 'active' ? 'En service' : ($data['status_label'] ?? null),
            'tariff_code' => $tariff,
            'tariff_label' => $data['tariff']['label'] ?? null,
            'tariff_ok' => $tariff === self::EXPECTED_TARIFF,
            'operator' => $data['operator']['name'] ?? null,
            'holder' => $data['holder']['name'] ?? null,
            'service' => $data['service']['name'] ?? null,
            'since' => $data['period']['from'] ?? null,
            'public_url' => $data['public_url'] ?? null,
        ]);
    }
}
