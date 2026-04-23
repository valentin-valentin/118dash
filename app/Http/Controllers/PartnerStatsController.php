<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerStatsController extends Controller
{
    // Salt en dur pour la génération du hash
    private const HASH_SALT = 'partner_stats_secure_salt_2024_voxnode';

    /**
     * Génère le hash pour une liste de sources
     */
    public static function generateHash(string $sources): string
    {
        return md5($sources . self::HASH_SALT);
    }

    /**
     * Valide le hash pour une liste de sources
     */
    private function validateHash(string $sources, string $hash): bool
    {
        return self::generateHash($sources) === $hash;
    }

    /**
     * Parse la chaîne de sources en tableau d'IDs
     */
    private function parseSourceIds(string $sources): array
    {
        return array_map('intval', explode(',', $sources));
    }

    /**
     * Affiche la page des statistiques partenaires
     */
    public function show(string $sources, string $hash): Response
    {
        // Validation du hash
        if (!$this->validateHash($sources, $hash)) {
            abort(403, 'Invalid access token');
        }

        $sourceIds = $this->parseSourceIds($sources);

        // Récupérer les sources pour afficher les noms
        $sourcesData = Source::whereIn('id', $sourceIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($s) => ['value' => $s->id, 'label' => $s->name]);

        return Inertia::render('PartnerStats', [
            'sources' => $sourcesData,
            'sourceIds' => $sourceIds,
            'sourcesParam' => $sources,
            'hash' => $hash,
        ]);
    }

    /**
     * Breakdown jour par jour pour le mois sélectionné
     */
    public function dailyBreakdown(Request $request, string $sources, string $hash): JsonResponse
    {
        // Validation du hash
        if (!$this->validateHash($sources, $hash)) {
            abort(403, 'Invalid access token');
        }

        $sourceIds = $this->parseSourceIds($sources);

        // Récupérer le mois depuis la requête (format: YYYY-MM)
        $month = $request->input('month', now()->format('Y-m'));
        [$year, $monthNum] = explode('-', $month);

        $startDate = \Carbon\Carbon::createFromDate($year, $monthNum, 1)->setTimezone('Europe/Paris')->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->endOfDay();

        // Date de comparaison (même période mois précédent)
        $prevStartDate = $startDate->copy()->subMonth();
        $prevEndDate = $endDate->copy()->subMonth();

        $today = \Carbon\Carbon::now('Europe/Paris');

        // Créer closure pour appliquer les filtres
        $applyFilters = function($query) use ($request, $sourceIds) {
            // Filtrer par sources autorisées
            $selectedSources = $request->filled('source_id')
                ? array_intersect($this->parseMultiSelect($request->source_id), $sourceIds)
                : $sourceIds;

            $query->where(function ($q) use ($selectedSources) {
                $q->whereIn('source_id', $selectedSources)
                  ->orWhere(function ($sq) use ($selectedSources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($selectedSources) {
                             $psq->whereIn('source_id', $selectedSources);
                         });
                  });
            });

            // PARTENAIRES : Uniquement les appels >= 10 secondes
            $query->where('total_duration', '>=', 10);
        };

        // Générer les jours du mois jusqu'à aujourd'hui (pas au-delà)
        $lastDay = $today->lt($endDate) ? $today : $endDate;
        $allDays = collect();
        $currentDay = $startDate->copy();
        while ($currentDay->lte($lastDay)) {
            $allDays->push($currentDay->format('Y-m-d'));
            $currentDay->addDay();
        }

        $items = [];
        foreach ($allDays as $dateStr) {
            // Parser la date en timezone Europe/Paris
            $dayDate = \Carbon\Carbon::parse($dateStr, 'Europe/Paris');
            $prevDate = $dayDate->copy()->subMonth();

            // Stats du jour actuel - convertir en UTC pour la requête - UNE SEULE requête
            $dayStart = $dayDate->copy()->startOfDay()->utc();
            $dayEnd = $dayDate->copy()->endOfDay()->utc();

            $dayQuery = Call::query();
            $applyFilters($dayQuery);
            $dayData = $dayQuery->whereBetween('called_at', [$dayStart, $dayEnd])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $calls = $dayData ? (int) $dayData->calls : 0;
            $reverse = $dayData ? (float) $dayData->reverse : 0;

            // Stats du même jour mois précédent - convertir en UTC - UNE SEULE requête
            $prevDayStart = $prevDate->copy()->startOfDay()->utc();
            $prevDayEnd = $prevDate->copy()->endOfDay()->utc();

            $prevDayQuery = Call::query();
            $applyFilters($prevDayQuery);
            $prevDayData = $prevDayQuery->whereBetween('called_at', [$prevDayStart, $prevDayEnd])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $prevCalls = $prevDayData ? (int) $prevDayData->calls : 0;
            $prevReverse = $prevDayData ? (float) $prevDayData->reverse : 0;

            // Calcul des variations
            $callsVar = ($prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $reverseVar = ($prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;

            $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$dayDate->dayOfWeek];
            $prevDayName = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$prevDate->dayOfWeek];

            $items[] = [
                'date' => $dateStr,
                'date_label' => $dayName . ' ' . $dayDate->format('d/m'),
                'comparison_label' => $prevDayName . ' ' . $prevDate->format('d/m'),
                'calls' => $calls,
                'reverse' => $reverse,
                'prev_calls' => $prevCalls,
                'prev_reverse' => $prevReverse,
                'calls_var' => $callsVar,
                'reverse_var' => $reverseVar,
            ];
        }

        // Calculer les totaux du mois (convertir en UTC) - UNE SEULE requête
        $totalQuery = Call::query();
        $applyFilters($totalQuery);
        $totalData = $totalQuery->whereBetween('called_at', [$startDate->copy()->utc(), $endDate->copy()->utc()])
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
            ->first();

        $totalCalls = $totalData ? (int) $totalData->calls : 0;
        $totalReverse = $totalData ? (float) $totalData->reverse : 0;

        $prevTotalQuery = Call::query();
        $applyFilters($prevTotalQuery);
        $prevTotalData = $prevTotalQuery->whereBetween('called_at', [$prevStartDate->copy()->utc(), $prevEndDate->copy()->utc()])
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
            ->first();

        $prevTotalCalls = $prevTotalData ? (int) $prevTotalData->calls : 0;
        $prevTotalReverse = $prevTotalData ? (float) $prevTotalData->reverse : 0;

        $totalCallsVar = ($prevTotalCalls > 0) ? round((($totalCalls - $prevTotalCalls) / $prevTotalCalls) * 100, 1) : null;
        $totalReverseVar = ($prevTotalReverse > 0) ? round((($totalReverse - $prevTotalReverse) / $prevTotalReverse) * 100, 1) : null;

        return response()->json([
            'items' => $items,
            'totals' => [
                'calls' => $totalCalls,
                'reverse' => $totalReverse,
                'prev_calls' => $prevTotalCalls,
                'prev_reverse' => $prevTotalReverse,
                'calls_var' => $totalCallsVar,
                'reverse_var' => $totalReverseVar,
            ],
        ]);
    }

    /**
     * Breakdown heure par heure pour un jour donné
     */
    public function hourlyBreakdown(Request $request, string $sources, string $hash): JsonResponse
    {
        // Validation du hash
        if (!$this->validateHash($sources, $hash)) {
            abort(403, 'Invalid access token');
        }

        $sourceIds = $this->parseSourceIds($sources);

        $date = $request->input('date');
        if (!$date) {
            return response()->json(['error' => 'Date requise'], 400);
        }

        $currentDate = \Carbon\Carbon::parse($date, 'Europe/Paris');
        $previousWeekDate = $currentDate->copy()->subDays(7);

        // Closure pour appliquer les filtres
        $applyFilters = function($query) use ($request, $sourceIds) {
            $selectedSources = $request->filled('source_id')
                ? array_intersect($this->parseMultiSelect($request->source_id), $sourceIds)
                : $sourceIds;

            $query->where(function ($q) use ($selectedSources) {
                $q->whereIn('source_id', $selectedSources)
                  ->orWhere(function ($sq) use ($selectedSources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($selectedSources) {
                             $psq->whereIn('source_id', $selectedSources);
                         });
                  });
            });

            // PARTENAIRES : Uniquement les appels >= 10 secondes
            $query->where('total_duration', '>=', 10);
        };

        // Générer les heures 8-21 (heures d'ouverture)
        $hours = range(8, 21);
        $hourlyData = [];

        foreach ($hours as $hour) {
            // Créer les dates en Europe/Paris puis convertir en UTC pour la requête
            $hourStart = $currentDate->copy()->setTime($hour, 0, 0)->utc();
            $hourEnd = $currentDate->copy()->setTime($hour, 59, 59)->utc();

            // Stats heure actuelle - UNE SEULE requête
            $currentQuery = Call::query();
            $applyFilters($currentQuery);
            $currentData = $currentQuery->whereBetween('called_at', [$hourStart, $hourEnd])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $calls = $currentData ? (int) $currentData->calls : 0;
            $reverse = $currentData ? (float) $currentData->reverse : 0;

            // Stats même heure semaine précédente - UNE SEULE requête
            $prevHourStart = $previousWeekDate->copy()->setTime($hour, 0, 0)->utc();
            $prevHourEnd = $previousWeekDate->copy()->setTime($hour, 59, 59)->utc();

            $prevQuery = Call::query();
            $applyFilters($prevQuery);
            $prevData = $prevQuery->whereBetween('called_at', [$prevHourStart, $prevHourEnd])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $prevCalls = $prevData ? (int) $prevData->calls : 0;
            $prevReverse = $prevData ? (float) $prevData->reverse : 0;

            // Variations
            $callsVar = ($prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $reverseVar = ($prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;

            $hourlyData[] = [
                'hour' => sprintf('%02dh-%02dh', $hour, ($hour + 1) % 24),
                'calls' => $calls,
                'reverse' => $reverse,
                'prev_calls' => $prevCalls,
                'prev_reverse' => $prevReverse,
                'calls_var' => $callsVar,
                'reverse_var' => $reverseVar,
            ];
        }

        // Totaux de la journée (0h-23h59) - convertir en UTC - UNE SEULE requête
        $dayStart = $currentDate->copy()->startOfDay()->utc();
        $dayEnd = $currentDate->copy()->endOfDay()->utc();

        $totalQuery = Call::query();
        $applyFilters($totalQuery);
        $totalData = $totalQuery->whereBetween('called_at', [$dayStart, $dayEnd])
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
            ->first();

        $totalCalls = $totalData ? (int) $totalData->calls : 0;
        $totalReverse = $totalData ? (float) $totalData->reverse : 0;

        $prevDayStart = $previousWeekDate->copy()->startOfDay()->utc();
        $prevDayEnd = $previousWeekDate->copy()->endOfDay()->utc();

        $prevTotalQuery = Call::query();
        $applyFilters($prevTotalQuery);
        $prevTotalData = $prevTotalQuery->whereBetween('called_at', [$prevDayStart, $prevDayEnd])
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
            ->first();

        $prevTotalCalls = $prevTotalData ? (int) $prevTotalData->calls : 0;
        $prevTotalReverse = $prevTotalData ? (float) $prevTotalData->reverse : 0;

        $totalCallsVar = ($prevTotalCalls > 0) ? round((($totalCalls - $prevTotalCalls) / $prevTotalCalls) * 100, 1) : null;
        $totalReverseVar = ($prevTotalReverse > 0) ? round((($totalReverse - $prevTotalReverse) / $prevTotalReverse) * 100, 1) : null;

        $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$currentDate->dayOfWeek];
        $prevDayName = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$previousWeekDate->dayOfWeek];

        // Heure actuelle en France
        $nowInFrance = \Carbon\Carbon::now('Europe/Paris');
        $currentHour = $nowInFrance->hour;

        return response()->json([
            'date' => $date,
            'date_label' => $dayName . ' ' . $currentDate->format('d/m/Y'),
            'comparison_date' => $previousWeekDate->format('Y-m-d'),
            'comparison_label' => $prevDayName . ' ' . $previousWeekDate->format('d/m/Y'),
            'current_hour' => $currentHour,
            'items' => $hourlyData,
            'totals' => [
                'calls' => $totalCalls,
                'reverse' => $totalReverse,
                'prev_calls' => $prevTotalCalls,
                'prev_reverse' => $prevTotalReverse,
                'calls_var' => $totalCallsVar,
                'reverse_var' => $totalReverseVar,
            ],
        ]);
    }

    /**
     * Breakdown mois par mois pour l'année sélectionnée
     */
    public function monthlyBreakdown(Request $request, string $sources, string $hash): JsonResponse
    {
        if (!$this->validateHash($sources, $hash)) {
            abort(403, 'Invalid access token');
        }

        $sourceIds = $this->parseSourceIds($sources);

        $year = (int) $request->input('year', now()->year);

        $today = \Carbon\Carbon::now('Europe/Paris');

        $applyFilters = function($query) use ($request, $sourceIds) {
            $selectedSources = $request->filled('source_id')
                ? array_intersect($this->parseMultiSelect($request->source_id), $sourceIds)
                : $sourceIds;

            $query->where(function ($q) use ($selectedSources) {
                $q->whereIn('source_id', $selectedSources)
                  ->orWhere(function ($sq) use ($selectedSources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($selectedSources) {
                             $psq->whereIn('source_id', $selectedSources);
                         });
                  });
            });

            $query->where('total_duration', '>=', 10);
        };

        $monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        $items = [];
        $totalCalls = 0;
        $totalReverse = 0.0;

        for ($monthNum = 1; $monthNum <= 12; $monthNum++) {
            $monthStart = \Carbon\Carbon::createFromDate($year, $monthNum, 1, 'Europe/Paris')->startOfDay();

            // Ne pas inclure les mois futurs
            if ($monthStart->gt($today)) {
                break;
            }

            $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
            $effectiveEnd = $today->lt($monthEnd) ? $today->copy()->endOfDay() : $monthEnd;

            // Stats du mois
            $monthQuery = Call::query();
            $applyFilters($monthQuery);
            $monthData = $monthQuery->whereBetween('called_at', [$monthStart->copy()->utc(), $effectiveEnd->copy()->utc()])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $calls = $monthData ? (int) $monthData->calls : 0;
            $reverse = $monthData ? (float) $monthData->reverse : 0.0;

            // Stats même mois année précédente
            $prevMonthStart = $monthStart->copy()->subYear();
            $prevMonthEnd = $monthEnd->copy()->subYear();

            $prevMonthQuery = Call::query();
            $applyFilters($prevMonthQuery);
            $prevMonthData = $prevMonthQuery->whereBetween('called_at', [$prevMonthStart->copy()->utc(), $prevMonthEnd->copy()->utc()])
                ->selectRaw('COUNT(*) as calls, COALESCE(SUM(payout_source), 0) as reverse')
                ->first();

            $prevCalls = $prevMonthData ? (int) $prevMonthData->calls : 0;
            $prevReverse = $prevMonthData ? (float) $prevMonthData->reverse : 0.0;

            $callsVar = ($prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $reverseVar = ($prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;

            $items[] = [
                'month' => sprintf('%04d-%02d', $year, $monthNum),
                'month_label' => $monthNames[$monthNum - 1],
                'calls' => $calls,
                'reverse' => $reverse,
                'prev_calls' => $prevCalls,
                'prev_reverse' => $prevReverse,
                'calls_var' => $callsVar,
                'reverse_var' => $reverseVar,
            ];

            $totalCalls += $calls;
            $totalReverse += $reverse;
        }

        return response()->json([
            'items' => $items,
            'totals' => [
                'calls' => $totalCalls,
                'reverse' => $totalReverse,
            ],
        ]);
    }

    /**
     * Options de filtres disponibles
     */
    public function filterOptions(string $sources, string $hash): JsonResponse
    {
        // Validation du hash
        if (!$this->validateHash($sources, $hash)) {
            abort(403, 'Invalid access token');
        }

        $sourceIds = $this->parseSourceIds($sources);

        $sourcesData = Source::whereIn('id', $sourceIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($s) => ['value' => $s->id, 'label' => $s->name]);

        return response()->json([
            'sources' => $sourcesData,
        ]);
    }

    /**
     * Parse multi-select values (helper)
     */
    private function parseMultiSelect($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            return array_filter(explode(',', $value), fn($v) => $v !== '');
        }

        return [];
    }
}
