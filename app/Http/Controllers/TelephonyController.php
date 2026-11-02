<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            // En cas de panne : active = numéros et agents basculent sur Voxnode, simulate = journalisé seulement
            'pbx_failover_mode' => 'sometimes|in:active,simulate',
            // Tout transfert sur notre serveur part au service interne (la marque choisie reste enregistrée)
            'selfhosted_transfers_internal' => 'sometimes|boolean',
            // Test des agents IA : environ N appels par jour envoyés au prestataire, 0 = coupé
            'ai_daily_calls' => 'sometimes|integer|between:0,1000',
            // Régulateur : déplacements automatiques des agents entre les plateformes
            'regulator_max_moves' => 'sometimes|integer|between:1,10',
            'regulator_cooldown_minutes' => 'sometimes|integer|between:0,60',
            'regulator_wait_seconds' => 'sometimes|integer|between:0,600',
            // Appelants prioritaires : pas d'attente forcée
            'priority_min_duration' => 'sometimes|integer|between:1,3600',
            'priority_window_hours' => 'sometimes|integer|between:1,720',
            // Attente forcée par opérateur de l'appelant (code calls.carrier) : {"SFR0": {"min": 60, "max": 90}, …},
            // objet complet à chaque envoi, {} = aucune exception (attente de base pour tous)
            'forced_wait_by_carrier' => ['sometimes', 'array', function ($attribute, $value, $fail) {
                if ($error = self::carrierWaitError($value)) {
                    $fail("Attente par opérateur : {$error}");
                }
            }],
            // Horaires d'ouverture : toujours la semaine entière, null = fermé toute la journée
            'opening_hours' => ['sometimes', 'array', function ($attribute, $value, $fail) {
                if ($error = self::openingHoursError($value)) {
                    $fail("Horaires d'ouverture : {$error}");
                }
            }],
        ], [
            'selfhosted_percent.between' => 'Part du trafic : entre 0 et 100 %.',
            'pbx_recovery_minutes.between' => 'Délai de retour : entre 1 et 120 minutes.',
            'ai_daily_calls.between' => 'Agents IA : entre 0 et 1000 appels par jour.',
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

    private static function carrierWaitError(array $value): ?string
    {
        if (count($value) > 50) {
            return '50 opérateurs au plus.';
        }
        foreach ($value as $code => $wait) {
            if (!is_string($code) || !preg_match('/^[A-Z0-9]{2,6}$/', $code)) {
                return "code opérateur « {$code} » invalide (2 à 6 lettres majuscules ou chiffres).";
            }
            $min = $wait['min'] ?? null;
            $max = $wait['max'] ?? null;
            if (!is_int($min) || !is_int($max) || $min < 0 || $max > 600 || $min > $max) {
                return "{$code} : durées entières entre 0 et 600 secondes, minimum inférieur ou égal au maximum.";
            }
        }

        return null;
    }

    /**
     * Opérateurs des appelants vus sur 30 jours (codes de calls.carrier), pour proposer les codes dans la configuration.
     */
    public function carriers(): JsonResponse
    {
        $rows = DB::table('calls')
            ->where('called_at', '>=', now()->subDays(30))
            ->whereRaw("carrier REGEXP '^[A-Z0-9]{2,6}$'")
            ->groupBy('carrier')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw('carrier AS code, COUNT(*) AS calls')
            ->get();

        return response()->json($rows);
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

    /**
     * Réseau des agents sur une période (table agent_network_quality de voxnode118, tranches de 5 min) :
     * aller-retour vers l'app (HTTP), réponse SIP OPTIONS du serveur où l'agent est connecté,
     * OPTIONS sans réponse et coupures SIP. Une ligne par agent et par plateforme.
     * Repères : SIP > 600 ms, OPTIONS perdus ou coupures répétés = connexion à surveiller.
     */
    public function network(Request $request): JsonResponse
    {
        $hours = (int) ($request->validate(['hours' => 'nullable|integer|in:24,168,720'])['hours'] ?? 24);

        // FROM_UNIXTIME : period_start est un TIMESTAMP, comparé quel que soit le fuseau du serveur MySQL
        $rows = DB::table('agent_network_quality as q')
            ->leftJoin('agents as a', 'a.id', '=', 'q.agent_id')
            ->whereRaw('q.period_start >= FROM_UNIXTIME(?)', [now()->subHours($hours)->getTimestamp()])
            ->groupBy('q.agent_id', 'q.platform', 'a.voxnode_username', 'a.name')
            ->selectRaw('
                q.agent_id, q.platform, a.voxnode_username AS username, a.name,
                SUM(q.samples) AS samples,
                SUM(q.http_sum) AS http_sum, SUM(q.http_count) AS http_count, MAX(q.http_max) AS http_max,
                SUM(q.sip_sum) AS sip_sum, SUM(q.sip_count) AS sip_count, MAX(q.sip_max) AS sip_max,
                SUM(q.sip_lost) AS sip_lost, SUM(q.disconnects) AS disconnects,
                UNIX_TIMESTAMP(MAX(q.period_start)) AS last_period
            ')
            ->get()
            ->map(fn ($r) => [
                'agent_id' => (int) $r->agent_id,
                'username' => $r->username,
                'name' => $r->name,
                'platform' => $r->platform === 'selfhosted' ? 'selfhosted' : 'voxnode',
                'samples' => (int) $r->samples,
                'http_avg' => $r->http_count ? (int) round($r->http_sum / $r->http_count) : null,
                'http_max' => $r->http_count ? (int) $r->http_max : null,
                'sip_avg' => $r->sip_count ? (int) round($r->sip_sum / $r->sip_count) : null,
                'sip_max' => $r->sip_count ? (int) $r->sip_max : null,
                'sip_count' => (int) $r->sip_count,
                'sip_lost' => (int) $r->sip_lost,
                'disconnects' => (int) $r->disconnects,
                'last_at' => $r->last_period ? date(DATE_ATOM, (int) $r->last_period) : null,
            ])
            ->values();

        return response()->json(['hours' => $hours, 'agents' => $rows]);
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
