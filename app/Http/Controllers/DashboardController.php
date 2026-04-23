<?php

namespace App\Http\Controllers;

use App\Helpers\CarrierHelper;
use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Hero KPIs avec comparaisons multiples (hier + même jour semaine dernière)
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->input('period', 'today');
        $now = now();

        // Définir les périodes selon la sélection
        if ($period === 'today') {
            // Aujourd'hui jusqu'à maintenant
            $currentStart = today()->startOfDay();
            $currentEnd = $now;

            // Hier à la même heure
            $comp1Start = today()->subDay()->startOfDay();
            $comp1End = today()->subDay()->setTime($now->hour, $now->minute, $now->second);
            $comp1Label = 'yesterday';

            // S-1 à la même heure
            $comp2Start = today()->subWeek()->startOfDay();
            $comp2End = today()->subWeek()->setTime($now->hour, $now->minute, $now->second);
            $comp2Label = 'last_week';
        } elseif ($period === 'this_week') {
            // Cette semaine depuis le début jusqu'à maintenant
            $currentStart = now()->startOfWeek();
            $currentEnd = $now;

            // Même nombre de jours la semaine dernière
            $daysIntoWeek = $now->dayOfWeek; // 0 = dimanche, 1 = lundi, etc.
            $comp1Start = now()->subWeek()->startOfWeek();
            $comp1End = now()->subWeek()->startOfWeek()->addDays($daysIntoWeek)->setTime($now->hour, $now->minute, $now->second);
            $comp1Label = 'last_week';

            // 2 semaines avant (même durée)
            $comp2Start = now()->subWeeks(2)->startOfWeek();
            $comp2End = now()->subWeeks(2)->startOfWeek()->addDays($daysIntoWeek)->setTime($now->hour, $now->minute, $now->second);
            $comp2Label = 'two_weeks_ago';
        } else { // this_month
            // Ce mois depuis le début jusqu'à maintenant
            $currentStart = now()->startOfMonth();
            $currentEnd = $now;

            // Même nombre de jours du mois précédent
            $dayOfMonth = $now->day;
            $comp1Start = now()->subMonth()->startOfMonth();
            $comp1End = now()->subMonth()->startOfMonth()->addDays($dayOfMonth - 1)->endOfDay();
            $comp1Label = 'last_month';

            // Mois d'avant (même durée)
            $comp2Start = now()->subMonths(2)->startOfMonth();
            $comp2End = now()->subMonths(2)->startOfMonth()->addDays($dayOfMonth - 1)->endOfDay();
            $comp2Label = 'two_months_ago';
        }

        // Période actuelle
        $current = Call::whereBetween('called_at', [$currentStart, $currentEnd])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        // Comparaison 1
        $comp1 = Call::whereBetween('called_at', [$comp1Start, $comp1End])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        // Comparaison 2
        $comp2 = Call::whereBetween('called_at', [$comp2Start, $comp2End])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        return response()->json([
            'current' => [
                'calls' => (int) $current->calls,
                'ca' => round((float) $current->ca, 2),
                'reverse' => round((float) $current->reverse, 2),
                'benefice' => round((float) $current->benefice, 2),
                'total_duration' => round((float) $current->total_duration),
                'avg_duration' => round((float) $current->avg_duration),
            ],
            $comp1Label => [
                'calls' => (int) $comp1->calls,
                'ca' => round((float) $comp1->ca, 2),
                'reverse' => round((float) $comp1->reverse, 2),
                'benefice' => round((float) $comp1->benefice, 2),
                'total_duration' => round((float) $comp1->total_duration),
                'avg_duration' => round((float) $comp1->avg_duration),
            ],
            $comp2Label => [
                'calls' => (int) $comp2->calls,
                'ca' => round((float) $comp2->ca, 2),
                'reverse' => round((float) $comp2->reverse, 2),
                'benefice' => round((float) $comp2->benefice, 2),
                'total_duration' => round((float) $comp2->total_duration),
                'avg_duration' => round((float) $comp2->avg_duration),
            ],
        ]);
    }

    /**
     * Parse multi-select filter value
     */
    private function parseMultiSelect($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }

    /**
     * Graphique avec mois en cours + mois précédent + filtres
     */
    public function chartData(Request $request): JsonResponse
    {
        // Parse month filter (format: YYYY-MM)
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $currentStart = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $currentEnd = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();
            $previousStart = \Carbon\Carbon::createFromDate($year, $month, 1)->subMonth()->startOfMonth();
            $previousEnd = \Carbon\Carbon::createFromDate($year, $month, 1)->subMonth()->endOfMonth();
        } else {
            $currentStart = now()->startOfMonth();
            $currentEnd = now()->endOfMonth();
            $previousStart = now()->subMonth()->startOfMonth();
            $previousEnd = now()->subMonth()->endOfMonth();
        }

        // Fonction pour construire la query avec les filtres
        $applyFilters = function ($query) use ($request) {
            if ($request->filled('brand_name')) {
                $brands = $this->parseMultiSelect($request->brand_name);
                $query->whereIn('brand_name', $brands);
            }

            if ($request->filled('agent_name')) {
                $agents = $this->parseMultiSelect($request->agent_name);
                $query->whereIn('agent_name', $agents);
            }

            if ($request->filled('callcenter_id')) {
                $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
                $query->whereIn('callcenter_id', $callcenters);
            }

            if ($request->filled('carrier')) {
                $carriers = $this->parseMultiSelect($request->carrier);
                $query->whereIn('carrier', $carriers);
            }

            if ($request->filled('provider_id')) {
                $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
                $query->whereHas('phonenumber', function ($q) use ($providers) {
                    $q->withTrashed()->whereIn('provider_id', $providers);
                });
            }

            if ($request->filled('company_id')) {
                $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
                $query->whereHas('phonenumber', function ($q) use ($companies) {
                    $q->withTrashed()->whereIn('company_id', $companies);
                });
            }

            if ($request->filled('source_id')) {
                $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
                $query->where(function ($q) use ($sources) {
                    $q->whereIn('source_id', $sources)
                      ->orWhere(function ($sq) use ($sources) {
                          $sq->whereNull('source_id')
                             ->whereHas('phonenumber', function ($psq) use ($sources) {
                                 $psq->withTrashed()->whereIn('source_id', $sources);
                             });
                      });
                });
            }
        };

        // Données mois en cours
        $currentQuery = Call::query();
        $applyFilters($currentQuery);
        $currentData = $currentQuery->whereBetween('called_at', [$currentStart, $currentEnd])
            ->selectRaw('
                DATE(called_at) as date,
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'calls' => (int) $row->calls,
                    'ca' => round((float) $row->ca, 2),
                    'reverse' => round((float) $row->reverse, 2),
                    'benefice' => round((float) $row->benefice, 2),
                    'total_duration_hours' => round((float) $row->total_duration / 3600, 1),
                    'avg_duration_minutes' => round((float) $row->avg_duration / 60, 1),
                ];
            });

        // Données mois précédent
        $previousQuery = Call::query();
        $applyFilters($previousQuery);
        $previousData = $previousQuery->whereBetween('called_at', [$previousStart, $previousEnd])
            ->selectRaw('
                DATE(called_at) as date,
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'calls' => (int) $row->calls,
                    'ca' => round((float) $row->ca, 2),
                    'reverse' => round((float) $row->reverse, 2),
                    'benefice' => round((float) $row->benefice, 2),
                    'total_duration_hours' => round((float) $row->total_duration / 3600, 1),
                    'avg_duration_minutes' => round((float) $row->avg_duration / 60, 1),
                ];
            });

        return response()->json([
            'current' => $currentData,
            'previous' => $previousData,
        ]);
    }

    /**
     * Répartition par marques (mois en cours + filtres)
     */
    public function brandDistribution(Request $request): JsonResponse
    {
        // Parse month filter (format: YYYY-MM)
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $start = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $end = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();
        } else {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();
        }

        $query = Call::query();

        // Appliquer les mêmes filtres
        if ($request->filled('brand_name')) {
            $brands = $this->parseMultiSelect($request->brand_name);
            $query->whereIn('brand_name', $brands);
        }

        if ($request->filled('agent_name')) {
            $agents = $this->parseMultiSelect($request->agent_name);
            $query->whereIn('agent_name', $agents);
        }

        if ($request->filled('callcenter_id')) {
            $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
            $query->whereIn('callcenter_id', $callcenters);
        }

        if ($request->filled('carrier')) {
            $carriers = $this->parseMultiSelect($request->carrier);
            $query->whereIn('carrier', $carriers);
        }

        if ($request->filled('provider_id')) {
            $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
            $query->whereHas('phonenumber', function ($q) use ($providers) {
                $q->withTrashed()->whereIn('provider_id', $providers);
            });
        }

        if ($request->filled('company_id')) {
            $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
            $query->whereHas('phonenumber', function ($q) use ($companies) {
                $q->withTrashed()->whereIn('company_id', $companies);
            });
        }

        if ($request->filled('source_id')) {
            $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
            $query->where(function ($q) use ($sources) {
                $q->whereIn('source_id', $sources)
                  ->orWhere(function ($sq) use ($sources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($sources) {
                             $psq->withTrashed()->whereIn('source_id', $sources);
                         });
                  });
            });
        }

        // Compter toutes les marques avec au moins un appel (pour le compteur)
        $totalBrandsCount = (clone $query)->whereBetween('called_at', [$start, $end])
            ->whereNotNull('brand_name')
            ->where('brand_name', '!=', '')
            ->distinct('brand_name')
            ->count('brand_name');

        // Ne pas filtrer par brand_name pour le camembert (on veut toutes les marques avec minimum 3 appels)
        $brands = $query->whereBetween('called_at', [$start, $end])
            ->whereNotNull('brand_name')
            ->where('brand_name', '!=', '')
            ->selectRaw('
                brand_name,
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->groupBy('brand_name')
            ->havingRaw('COUNT(*) >= 5')
            ->orderByDesc('benefice')
            ->get()
            ->map(function ($row) {
                $totalSeconds = (int) $row->total_duration;
                $totalHours = floor($totalSeconds / 3600);
                $totalMinutes = floor(($totalSeconds % 3600) / 60);

                return [
                    'name' => $row->brand_name,
                    'calls' => (int) $row->calls,
                    'ca' => round((float) $row->ca, 2),
                    'reverse' => round((float) $row->reverse, 2),
                    'benefice' => round((float) $row->benefice, 2),
                    'total_duration_formatted' => sprintf('%dh%02dm', $totalHours, $totalMinutes),
                    'avg_duration' => round((float) $row->avg_duration),
                ];
            });

        return response()->json([
            'brands' => $brands,
            'total_count' => $totalBrandsCount,
        ]);
    }

    /**
     * Tableau jour par jour (toujours le mois en cours) avec filtres + comparaisons
     */
    public function dailyBreakdown(Request $request): JsonResponse
    {
        // Parse month filter (format: YYYY-MM)
        // Interpréter les dates en timezone Europe/Paris
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $start = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->startOfMonth();
            $end = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->endOfMonth();
            $previousMonthStart = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->subMonth()->startOfMonth();
            $previousMonthEnd = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->subMonth()->endOfMonth();
        } else {
            $start = now('Europe/Paris')->startOfMonth();
            $end = now('Europe/Paris')->endOfMonth();
            $previousMonthStart = now('Europe/Paris')->subMonth()->startOfMonth();
            $previousMonthEnd = now('Europe/Paris')->subMonth()->endOfMonth();
        }

        // Créer une closure pour appliquer les filtres (réutilisable)
        $applyFilters = function($query) use ($request) {
            if ($request->filled('brand_name')) {
                $brands = $this->parseMultiSelect($request->brand_name);
                $query->whereIn('brand_name', $brands);
            }

            if ($request->filled('agent_name')) {
                $agents = $this->parseMultiSelect($request->agent_name);
                $query->whereIn('agent_name', $agents);
            }

            if ($request->filled('callcenter_id')) {
                $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
                $query->whereIn('callcenter_id', $callcenters);
            }

            if ($request->filled('carrier')) {
                $carriers = $this->parseMultiSelect($request->carrier);
                $query->whereIn('carrier', $carriers);
            }

            if ($request->filled('provider_id')) {
                $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
                $query->whereHas('phonenumber', function ($q) use ($providers) {
                    $q->withTrashed()->whereIn('provider_id', $providers);
                });
            }

            if ($request->filled('company_id')) {
                $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
                $query->whereHas('phonenumber', function ($q) use ($companies) {
                    $q->withTrashed()->whereIn('company_id', $companies);
                });
            }

            if ($request->filled('source_id')) {
                $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
                $query->where(function ($q) use ($sources) {
                    $q->whereIn('source_id', $sources)
                      ->orWhere(function ($sq) use ($sources) {
                          $sq->whereNull('source_id')
                             ->whereHas('phonenumber', function ($psq) use ($sources) {
                                 $psq->withTrashed()->whereIn('source_id', $sources);
                             });
                      });
                });
            }

            // Filtre optionnel : appels >= 10 secondes
            if ($request->filled('min_duration') && $request->min_duration == 'true') {
                $query->where('total_duration', '>=', 10);
            }

            return $query;
        };

        $today = \Carbon\Carbon::now('Europe/Paris');
        $now = \Carbon\Carbon::now('Europe/Paris');

        // Générer les jours du mois jusqu'à aujourd'hui (pas au-delà)
        $lastDay = $today->lt($end) ? $today : $end;
        $allDays = collect();
        $currentDay = $start->copy();
        while ($currentDay->lte($lastDay)) {
            $allDays->push($currentDay->format('Y-m-d'));
            $currentDay->addDay();
        }

        // Récupérer les données pour chaque jour
        $daily = $allDays->map(function($dateStr) use ($applyFilters, $today, $now) {
            // Parser la date en timezone Europe/Paris puis convertir en UTC pour la requête
            $date = \Carbon\Carbon::parse($dateStr, 'Europe/Paris');

            $dayQuery = Call::query();
            $applyFilters($dayQuery);

            // Si c'est aujourd'hui, limiter à maintenant
            if ($date->isSameDay($today)) {
                $dayStart = $date->copy()->startOfDay()->utc();
                $dayEnd = $now->copy()->utc();
            } else {
                $dayStart = $date->copy()->startOfDay()->utc();
                $dayEnd = $date->copy()->endOfDay()->utc();
            }

            $data = $dayQuery->whereBetween('called_at', [$dayStart, $dayEnd])
                ->selectRaw('
                    COUNT(*) as calls,
                    COALESCE(SUM(payout), 0) as ca,
                    COALESCE(SUM(payout_source), 0) as reverse,
                    COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                    COALESCE(SUM(total_duration), 0) as total_duration,
                    COALESCE(AVG(total_duration), 0) as avg_duration
                ')
                ->first();

            return (object)[
                'date' => $dateStr,
                'calls' => $data ? (int) $data->calls : 0,
                'ca' => $data ? (float) $data->ca : 0,
                'reverse' => $data ? (float) $data->reverse : 0,
                'benefice' => $data ? (float) $data->benefice : 0,
                'total_duration' => $data ? (int) $data->total_duration : 0,
                'avg_duration' => $data ? (float) $data->avg_duration : 0,
            ];
        });

        // Données semaine précédente (7 jours avant, mêmes filtres)
        // On récupère pour chaque date du mois actuel, la date -7 jours
        $previousWeekData = [];

        // Pour chaque jour, récupérer les données de 7 jours avant
        foreach ($daily as $dayData) {
            // Parser en timezone Europe/Paris
            $currentDate = \Carbon\Carbon::parse($dayData->date, 'Europe/Paris');
            $previousWeekDate = $currentDate->copy()->subDays(7);

            $prevQuery = Call::query();
            $applyFilters($prevQuery);

            // Si c'est aujourd'hui, comparer jusqu'à la même heure (convertir en UTC)
            if ($currentDate->isToday()) {
                $prevQuery->whereBetween('called_at', [
                    $previousWeekDate->copy()->startOfDay()->utc(),
                    $previousWeekDate->copy()->setTime($now->hour, $now->minute, $now->second)                ]);
            } else {
                // Jour complet (convertir en UTC)
                $prevQuery->whereBetween('called_at', [
                    $previousWeekDate->copy()->startOfDay()->utc(),
                    $previousWeekDate->copy()->endOfDay()                ]);
            }

            $prevData = $prevQuery->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')->first();

            $previousWeekData[$dayData->date] = $prevData;
        }

        // Mapper avec comparaisons
        $daily = $daily->map(function ($row) use ($previousWeekData) {
            $currentDate = \Carbon\Carbon::parse($row->date);
            $comparisonDate = $currentDate->copy()->subDays(7);

            // Récupérer les données de 7 jours avant
            $prev = $previousWeekData[$row->date] ?? null;

            $calls = (int) $row->calls;
            $ca = round((float) $row->ca, 2);
            $reverse = round((float) $row->reverse, 2);
            $benefice = round((float) $row->benefice, 2);
            $totalDuration = (int) $row->total_duration;
            $avgDuration = round((float) $row->avg_duration);

            $prevCalls = $prev ? (int) $prev->calls : null;
            $prevCa = $prev ? round((float) $prev->ca, 2) : null;
            $prevReverse = $prev ? round((float) $prev->reverse, 2) : null;
            $prevBenefice = $prev ? round((float) $prev->benefice, 2) : null;
            $prevTotalDuration = $prev ? (int) $prev->total_duration : null;
            $prevAvgDuration = $prev ? round((float) $prev->avg_duration) : null;

            // Calcul des variations
            $callsVar = ($prevCalls && $prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $caVar = ($prevCa && $prevCa > 0) ? round((($ca - $prevCa) / $prevCa) * 100, 1) : null;
            $reverseVar = ($prevReverse && $prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;
            $beneficeVar = ($prevBenefice && $prevBenefice > 0) ? round((($benefice - $prevBenefice) / $prevBenefice) * 100, 1) : null;
            $totalDurationVar = ($prevTotalDuration && $prevTotalDuration > 0) ? round((($totalDuration - $prevTotalDuration) / $prevTotalDuration) * 100, 1) : null;
            $avgDurationVar = ($prevAvgDuration && $prevAvgDuration > 0) ? round((($avgDuration - $prevAvgDuration) / $prevAvgDuration) * 100, 1) : null;

            // Formater les dates avec jour de la semaine en français
            $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$currentDate->dayOfWeek];
            $comparisonDayName = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$comparisonDate->dayOfWeek];

            return [
                'date' => $row->date,
                'date_label' => $dayName . ' ' . $currentDate->format('d/m'),
                'comparison_date' => $comparisonDate->format('Y-m-d'),
                'comparison_label' => 'vs. ' . $comparisonDayName . ' ' . $comparisonDate->format('d/m'),
                'calls' => $calls,
                'ca' => $ca,
                'reverse' => $reverse,
                'benefice' => $benefice,
                'total_duration' => $totalDuration,
                'avg_duration' => $avgDuration,
                'prev_calls' => $prevCalls,
                'prev_ca' => $prevCa,
                'prev_reverse' => $prevReverse,
                'prev_benefice' => $prevBenefice,
                'prev_total_duration' => $prevTotalDuration,
                'prev_avg_duration' => $prevAvgDuration,
                'calls_var' => $callsVar,
                'ca_var' => $caVar,
                'reverse_var' => $reverseVar,
                'benefice_var' => $beneficeVar,
                'total_duration_var' => $totalDurationVar,
                'avg_duration_var' => $avgDurationVar,
            ];
        });

        // Calculer les totaux du mois actuel
        $totalCalls = $daily->sum('calls');
        $totalCa = round($daily->sum('ca'), 2);
        $totalReverse = round($daily->sum('reverse'), 2);
        $totalBenefice = round($daily->sum('benefice'), 2);
        $totalDuration = $daily->sum('total_duration');
        $avgDuration = $daily->avg('avg_duration') ? round($daily->avg('avg_duration')) : 0;

        // Calculer les totaux du mois précédent à la même période (jusqu'au même jour du mois)
        $previousMonthStart = $start->copy()->subMonth();
        $dayOfMonth = $today->day;
        $daysInPreviousMonth = $previousMonthStart->copy()->endOfMonth()->day;
        $previousMonthEnd = $previousMonthStart->copy()->addDays(min($dayOfMonth, $daysInPreviousMonth) - 1)->endOfDay();

        $prevMonthQuery = Call::query();
        $applyFilters($prevMonthQuery);

        $prevMonthData = $prevMonthQuery->whereBetween('called_at', [$previousMonthStart, $previousMonthEnd])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        $prevTotalCalls = $prevMonthData ? (int) $prevMonthData->calls : 0;
        $prevTotalCa = $prevMonthData ? round((float) $prevMonthData->ca, 2) : 0;
        $prevTotalReverse = $prevMonthData ? round((float) $prevMonthData->reverse, 2) : 0;
        $prevTotalBenefice = $prevMonthData ? round((float) $prevMonthData->benefice, 2) : 0;
        $prevTotalDuration = $prevMonthData ? (int) $prevMonthData->total_duration : 0;
        $prevAvgDuration = $prevMonthData ? round((float) $prevMonthData->avg_duration) : 0;

        // Calculer les variations
        $callsVar = ($prevTotalCalls > 0) ? round((($totalCalls - $prevTotalCalls) / $prevTotalCalls) * 100, 1) : null;
        $caVar = ($prevTotalCa > 0) ? round((($totalCa - $prevTotalCa) / $prevTotalCa) * 100, 1) : null;
        $reverseVar = ($prevTotalReverse > 0) ? round((($totalReverse - $prevTotalReverse) / $prevTotalReverse) * 100, 1) : null;
        $beneficeVar = ($prevTotalBenefice > 0) ? round((($totalBenefice - $prevTotalBenefice) / $prevTotalBenefice) * 100, 1) : null;
        $totalDurationVar = ($prevTotalDuration > 0) ? round((($totalDuration - $prevTotalDuration) / $prevTotalDuration) * 100, 1) : null;
        $avgDurationVar = ($prevAvgDuration > 0) ? round((($avgDuration - $prevAvgDuration) / $prevAvgDuration) * 100, 1) : null;

        $totals = [
            'calls' => $totalCalls,
            'ca' => $totalCa,
            'reverse' => $totalReverse,
            'benefice' => $totalBenefice,
            'total_duration' => $totalDuration,
            'avg_duration' => $avgDuration,
            'prev_calls' => $prevTotalCalls,
            'prev_ca' => $prevTotalCa,
            'prev_reverse' => $prevTotalReverse,
            'prev_benefice' => $prevTotalBenefice,
            'prev_total_duration' => $prevTotalDuration,
            'prev_avg_duration' => $prevAvgDuration,
            'calls_var' => $callsVar,
            'ca_var' => $caVar,
            'reverse_var' => $reverseVar,
            'benefice_var' => $beneficeVar,
            'total_duration_var' => $totalDurationVar,
            'avg_duration_var' => $avgDurationVar,
        ];

        return response()->json([
            'items' => $daily,
            'totals' => $totals,
        ]);
    }

    /**
     * Breakdown heure par heure pour un jour donné (9h-20h FR)
     * avec comparaison avec le même jour de la semaine précédente
     */
    public function hourlyBreakdown(Request $request): JsonResponse
    {
        $date = $request->input('date'); // Format: YYYY-MM-DD
        if (!$date) {
            return response()->json(['error' => 'Date requise'], 400);
        }

        $currentDate = \Carbon\Carbon::parse($date)->setTimezone('Europe/Paris');
        $previousWeekDate = $currentDate->copy()->subDays(7);

        // Créer une closure pour appliquer les filtres (réutilisable)
        $applyFilters = function($query) use ($request) {
            if ($request->filled('brand_name')) {
                $brands = $this->parseMultiSelect($request->brand_name);
                $query->whereIn('brand_name', $brands);
            }

            if ($request->filled('agent_name')) {
                $agents = $this->parseMultiSelect($request->agent_name);
                $query->whereIn('agent_name', $agents);
            }

            if ($request->filled('callcenter_id')) {
                $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
                $query->whereIn('callcenter_id', $callcenters);
            }

            if ($request->filled('carrier')) {
                $carriers = $this->parseMultiSelect($request->carrier);
                $query->whereIn('carrier', $carriers);
            }

            if ($request->filled('provider_id')) {
                $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
                $query->whereHas('phonenumber', function ($q) use ($providers) {
                    $q->withTrashed()->whereIn('provider_id', $providers);
                });
            }

            if ($request->filled('company_id')) {
                $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
                $query->whereHas('phonenumber', function ($q) use ($companies) {
                    $q->withTrashed()->whereIn('company_id', $companies);
                });
            }

            if ($request->filled('source_id')) {
                $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
                $query->where(function ($q) use ($sources) {
                    $q->whereIn('source_id', $sources)
                      ->orWhere(function ($sq) use ($sources) {
                          $sq->whereNull('source_id')
                             ->whereHas('phonenumber', function ($psq) use ($sources) {
                                 $psq->withTrashed()->whereIn('source_id', $sources);
                             });
                      });
                });
            }

            // Filtre optionnel : appels >= 10 secondes
            if ($request->filled('min_duration') && $request->min_duration == 'true') {
                $query->where('total_duration', '>=', 10);
            }

            return $query;
        };

        // Générer les heures de 8h à 21h (heures d'ouverture)
        $hours = range(8, 21);
        $hourlyData = [];

        foreach ($hours as $hour) {
            // Période actuelle (heure spécifique du jour sélectionné)
            // Créer les dates en Europe/Paris puis convertir en UTC pour la requête
            $hourStart = $currentDate->copy()->setTime($hour, 0, 0)->utc();
            $hourEnd = $currentDate->copy()->setTime($hour, 59, 59)->utc();

            $currentQuery = Call::query();
            $applyFilters($currentQuery);
            $currentStats = $currentQuery->whereBetween('called_at', [$hourStart, $hourEnd])
                ->selectRaw('
                    COUNT(*) as calls,
                    COALESCE(SUM(payout), 0) as ca,
                    COALESCE(SUM(payout_source), 0) as reverse,
                    COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                    COALESCE(SUM(total_duration), 0) as total_duration,
                    COALESCE(AVG(total_duration), 0) as avg_duration
                ')
                ->first();

            // Même heure, 7 jours avant (convertir en UTC)
            $prevHourStart = $previousWeekDate->copy()->setTime($hour, 0, 0)->utc();
            $prevHourEnd = $previousWeekDate->copy()->setTime($hour, 59, 59)->utc();

            $prevQuery = Call::query();
            $applyFilters($prevQuery);
            $prevStats = $prevQuery->whereBetween('called_at', [$prevHourStart, $prevHourEnd])
                ->selectRaw('
                    COUNT(*) as calls,
                    COALESCE(SUM(payout), 0) as ca,
                    COALESCE(SUM(payout_source), 0) as reverse,
                    COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                    COALESCE(SUM(total_duration), 0) as total_duration,
                    COALESCE(AVG(total_duration), 0) as avg_duration
                ')
                ->first();

            $calls = $currentStats ? (int) $currentStats->calls : 0;
            $ca = $currentStats ? round((float) $currentStats->ca, 2) : 0;
            $reverse = $currentStats ? round((float) $currentStats->reverse, 2) : 0;
            $benefice = $currentStats ? round((float) $currentStats->benefice, 2) : 0;
            $totalDuration = $currentStats ? (int) $currentStats->total_duration : 0;
            $avgDuration = $currentStats ? round((float) $currentStats->avg_duration) : 0;

            $prevCalls = $prevStats ? (int) $prevStats->calls : 0;
            $prevCa = $prevStats ? round((float) $prevStats->ca, 2) : 0;
            $prevReverse = $prevStats ? round((float) $prevStats->reverse, 2) : 0;
            $prevBenefice = $prevStats ? round((float) $prevStats->benefice, 2) : 0;
            $prevTotalDuration = $prevStats ? (int) $prevStats->total_duration : 0;
            $prevAvgDuration = $prevStats ? round((float) $prevStats->avg_duration) : 0;

            // Calcul des variations
            $callsVar = ($prevCalls > 0) ? round((($calls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $caVar = ($prevCa > 0) ? round((($ca - $prevCa) / $prevCa) * 100, 1) : null;
            $reverseVar = ($prevReverse > 0) ? round((($reverse - $prevReverse) / $prevReverse) * 100, 1) : null;
            $beneficeVar = ($prevBenefice > 0) ? round((($benefice - $prevBenefice) / $prevBenefice) * 100, 1) : null;
            $totalDurationVar = ($prevTotalDuration > 0) ? round((($totalDuration - $prevTotalDuration) / $prevTotalDuration) * 100, 1) : null;
            $avgDurationVar = ($prevAvgDuration > 0) ? round((($avgDuration - $prevAvgDuration) / $prevAvgDuration) * 100, 1) : null;

            $hourlyData[] = [
                'hour' => sprintf('%02d:00', $hour),
                'calls' => $calls,
                'ca' => $ca,
                'reverse' => $reverse,
                'benefice' => $benefice,
                'total_duration' => $totalDuration,
                'avg_duration' => $avgDuration,
                'prev_calls' => $prevCalls,
                'prev_ca' => $prevCa,
                'prev_reverse' => $prevReverse,
                'prev_benefice' => $prevBenefice,
                'prev_total_duration' => $prevTotalDuration,
                'prev_avg_duration' => $prevAvgDuration,
                'calls_var' => $callsVar,
                'ca_var' => $caVar,
                'reverse_var' => $reverseVar,
                'benefice_var' => $beneficeVar,
                'total_duration_var' => $totalDurationVar,
                'avg_duration_var' => $avgDurationVar,
            ];
        }

        // Calculer les totaux de la journée (0h-23h59) en interrogeant directement la base
        // Convertir en UTC pour la requête
        $dayStart = $currentDate->copy()->startOfDay()->utc();
        $dayEnd = $currentDate->copy()->endOfDay()->utc();

        $totalQuery = Call::query();
        $applyFilters($totalQuery);
        $totalStats = $totalQuery->whereBetween('called_at', [$dayStart, $dayEnd])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        $totalCalls = $totalStats ? (int) $totalStats->calls : 0;
        $totalCa = $totalStats ? round((float) $totalStats->ca, 2) : 0;
        $totalReverse = $totalStats ? round((float) $totalStats->reverse, 2) : 0;
        $totalBenefice = $totalStats ? round((float) $totalStats->benefice, 2) : 0;
        $totalDuration = $totalStats ? (int) $totalStats->total_duration : 0;
        $avgDuration = $totalStats ? round((float) $totalStats->avg_duration) : 0;

        // Totaux semaine précédente (0h-23h59) - convertir en UTC
        $prevDayStart = $previousWeekDate->copy()->startOfDay()->utc();
        $prevDayEnd = $previousWeekDate->copy()->endOfDay()->utc();

        $prevTotalQuery = Call::query();
        $applyFilters($prevTotalQuery);
        $prevTotalStats = $prevTotalQuery->whereBetween('called_at', [$prevDayStart, $prevDayEnd])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        $prevTotalCalls = $prevTotalStats ? (int) $prevTotalStats->calls : 0;
        $prevTotalCa = $prevTotalStats ? round((float) $prevTotalStats->ca, 2) : 0;
        $prevTotalReverse = $prevTotalStats ? round((float) $prevTotalStats->reverse, 2) : 0;
        $prevTotalBenefice = $prevTotalStats ? round((float) $prevTotalStats->benefice, 2) : 0;
        $prevTotalDuration = $prevTotalStats ? (int) $prevTotalStats->total_duration : 0;
        $prevAvgDuration = $prevTotalStats ? round((float) $prevTotalStats->avg_duration) : 0;

        // Variations totales
        $callsVar = ($prevTotalCalls > 0) ? round((($totalCalls - $prevTotalCalls) / $prevTotalCalls) * 100, 1) : null;
        $caVar = ($prevTotalCa > 0) ? round((($totalCa - $prevTotalCa) / $prevTotalCa) * 100, 1) : null;
        $reverseVar = ($prevTotalReverse > 0) ? round((($totalReverse - $prevTotalReverse) / $prevTotalReverse) * 100, 1) : null;
        $beneficeVar = ($prevTotalBenefice > 0) ? round((($totalBenefice - $prevTotalBenefice) / $prevTotalBenefice) * 100, 1) : null;
        $totalDurationVar = ($prevTotalDuration > 0) ? round((($totalDuration - $prevTotalDuration) / $prevTotalDuration) * 100, 1) : null;
        $avgDurationVar = ($prevAvgDuration > 0) ? round((($avgDuration - $prevAvgDuration) / $prevAvgDuration) * 100, 1) : null;

        $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$currentDate->dayOfWeek];
        $prevDayName = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][$previousWeekDate->dayOfWeek];

        // Heure actuelle en France pour le highlighting
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
                'ca' => $totalCa,
                'reverse' => $totalReverse,
                'benefice' => $totalBenefice,
                'total_duration' => $totalDuration,
                'avg_duration' => $avgDuration,
                'prev_calls' => $prevTotalCalls,
                'prev_ca' => $prevTotalCa,
                'prev_reverse' => $prevTotalReverse,
                'prev_benefice' => $prevTotalBenefice,
                'prev_total_duration' => $prevTotalDuration,
                'prev_avg_duration' => $prevAvgDuration,
                'calls_var' => $callsVar,
                'ca_var' => $caVar,
                'reverse_var' => $reverseVar,
                'benefice_var' => $beneficeVar,
                'total_duration_var' => $totalDurationVar,
                'avg_duration_var' => $avgDurationVar,
            ],
        ]);
    }

    /**
     * Options de filtres
     */
    public function filterOptions(): JsonResponse
    {
        return response()->json(\Cache::remember('dashboard_filter_options', 3600, function () {
            return [
                'brands' => Call::select('brand_name')
                    ->distinct()
                    ->whereNotNull('brand_name')
                    ->where('brand_name', '!=', '')
                    ->orderBy('brand_name')
                    ->pluck('brand_name'),
                'agents' => Call::select('agent_name')
                    ->distinct()
                    ->whereNotNull('agent_name')
                    ->where('agent_name', '!=', '')
                    ->orderBy('agent_name')
                    ->pluck('agent_name'),
                'callcenters' => \App\Models\Callcenter::select('id', 'name')
                    ->where('enabled', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn($c) => ['value' => $c->id, 'label' => $c->name])
                    ->values()
                    ->toArray(),
                'carriers' => Call::select('carrier')
                    ->distinct()
                    ->whereNotNull('carrier')
                    ->where('carrier', '!=', '')
                    ->pluck('carrier')
                    ->map(function ($code) {
                        return [
                            'value' => $code,
                            'label' => CarrierHelper::getDisplayName($code),
                            'isDefined' => CarrierHelper::isDefined($code),
                        ];
                    })
                    ->sortBy(function ($carrier) {
                        // Les carriers définis en premier, puis par label alphabétique
                        return [
                            $carrier['isDefined'] ? 0 : 1,
                            $carrier['label'],
                        ];
                    })
                    ->values(),
                'providers' => \App\Models\Provider::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($p) => ['value' => $p->id, 'label' => $p->name, 'color' => $p->color])
                    ->values()
                    ->toArray(),
                'companies' => \App\Models\Company::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($c) => ['value' => $c->id, 'label' => $c->name, 'color' => $c->color])
                    ->values()
                    ->toArray(),
                'sources' => \App\Models\Source::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($s) => ['value' => $s->id, 'label' => $s->name, 'color' => $s->color])
                    ->values()
                    ->toArray(),
            ];
        }));
    }

}
