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

        // Créer closure pour appliquer les filtres
        $applyFilters = function($query) use ($request, $sourceIds) {
            // Filtrer par sources autorisées
            $selectedSources = $request->filled('source_id')
                ? array_intersect($this->parseMultiSelect($request->source_id), $sourceIds)
                : $sourceIds;

            $query->whereIn('source_id', $selectedSources);
        };

        // Récupérer les jours du mois
        $days = [];
        $currentDay = $startDate->copy();
        while ($currentDay <= $endDate) {
            $days[] = $currentDay->format('Y-m-d');
            $currentDay->addDay();
        }

        $items = [];
        foreach ($days as $date) {
            $dayDate = \Carbon\Carbon::parse($date)->setTimezone('Europe/Paris');
            $prevDate = $dayDate->copy()->subMonth();

            // Stats du jour actuel (convertir en UTC pour la requête)
            $dayStart = $dayDate->copy()->startOfDay();
            $dayEnd = $dayDate->copy()->endOfDay();

            $dayQuery = Call::whereBetween('called_at', [$dayStart, $dayEnd]);
            $applyFilters($dayQuery);

            $calls = $dayQuery->count();
            $reverse = $dayQuery->sum('payout_source');

            // Stats du même jour mois précédent (convertir en UTC)
            $prevDayStart = $prevDate->copy()->startOfDay();
            $prevDayEnd = $prevDate->copy()->endOfDay();

            $prevDayQuery = Call::whereBetween('called_at', [$prevDayStart, $prevDayEnd]);
            $applyFilters($prevDayQuery);

            $prevCalls = $prevDayQuery->count();
            $prevReverse = $prevDayQuery->sum('payout_source');

            // Calcul des variations
            $callsVar = ($prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $reverseVar = ($prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;

            $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$dayDate->dayOfWeek];
            $prevDayName = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$prevDate->dayOfWeek];

            $items[] = [
                'date' => $date,
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

        // Calculer les totaux du mois (convertir en UTC)
        $totalQuery = Call::whereBetween('called_at', [$startDate->copy(), $endDate->copy()]);
        $applyFilters($totalQuery);
        $totalCalls = $totalQuery->count();
        $totalReverse = $totalQuery->sum('payout_source');

        $prevTotalQuery = Call::whereBetween('called_at', [$prevStartDate->copy(), $prevEndDate->copy()]);
        $applyFilters($prevTotalQuery);
        $prevTotalCalls = $prevTotalQuery->count();
        $prevTotalReverse = $prevTotalQuery->sum('payout_source');

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

        $currentDate = \Carbon\Carbon::parse($date)->setTimezone('Europe/Paris');
        $previousWeekDate = $currentDate->copy()->subDays(7);

        // Closure pour appliquer les filtres
        $applyFilters = function($query) use ($request, $sourceIds) {
            $selectedSources = $request->filled('source_id')
                ? array_intersect($this->parseMultiSelect($request->source_id), $sourceIds)
                : $sourceIds;

            $query->whereIn('source_id', $selectedSources);
        };

        // Générer les heures 9-20
        $hours = range(9, 20);
        $hourlyData = [];

        foreach ($hours as $hour) {
            // Créer les dates en Europe/Paris puis convertir en UTC pour la requête
            $hourStart = $currentDate->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            $hourEnd = $currentDate->copy()->setHour($hour)->setMinute(59)->setSecond(59);

            // Stats heure actuelle
            $currentQuery = Call::whereBetween('called_at', [$hourStart, $hourEnd]);
            $applyFilters($currentQuery);

            $calls = $currentQuery->count();
            $reverse = $currentQuery->sum('payout_source');

            // Stats même heure semaine précédente (convertir en UTC)
            $prevHourStart = $previousWeekDate->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            $prevHourEnd = $previousWeekDate->copy()->setHour($hour)->setMinute(59)->setSecond(59);

            $prevQuery = Call::whereBetween('called_at', [$prevHourStart, $prevHourEnd]);
            $applyFilters($prevQuery);

            $prevCalls = $prevQuery->count();
            $prevReverse = $prevQuery->sum('payout_source');

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

        // Totaux de la journée (9h-20h59) - convertir en UTC
        $dayStart = $currentDate->copy()->setHour(9)->setMinute(0)->setSecond(0);
        $dayEnd = $currentDate->copy()->setHour(20)->setMinute(59)->setSecond(59);

        $totalQuery = Call::whereBetween('called_at', [$dayStart, $dayEnd]);
        $applyFilters($totalQuery);
        $totalCalls = $totalQuery->count();
        $totalReverse = $totalQuery->sum('payout_source');

        $prevDayStart = $previousWeekDate->copy()->setHour(9)->setMinute(0)->setSecond(0);
        $prevDayEnd = $previousWeekDate->copy()->setHour(20)->setMinute(59)->setSecond(59);

        $prevTotalQuery = Call::whereBetween('called_at', [$prevDayStart, $prevDayEnd]);
        $applyFilters($prevTotalQuery);
        $prevTotalCalls = $prevTotalQuery->count();
        $prevTotalReverse = $prevTotalQuery->sum('payout_source');

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
