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
                    $q->whereIn('provider_id', $providers);
                });
            }

            if ($request->filled('company_id')) {
                $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
                $query->whereHas('phonenumber', function ($q) use ($companies) {
                    $q->whereIn('company_id', $companies);
                });
            }

            if ($request->filled('source_id')) {
                $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
                $query->where(function ($q) use ($sources) {
                    $q->whereIn('source_id', $sources)
                      ->orWhere(function ($sq) use ($sources) {
                          $sq->whereNull('source_id')
                             ->whereHas('phonenumber', function ($psq) use ($sources) {
                                 $psq->whereIn('source_id', $sources);
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
                $q->whereIn('provider_id', $providers);
            });
        }

        if ($request->filled('company_id')) {
            $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
            $query->whereHas('phonenumber', function ($q) use ($companies) {
                $q->whereIn('company_id', $companies);
            });
        }

        if ($request->filled('source_id')) {
            $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
            $query->where(function ($q) use ($sources) {
                $q->whereIn('source_id', $sources)
                  ->orWhere(function ($sq) use ($sources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($sources) {
                             $psq->whereIn('source_id', $sources);
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
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $start = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $end = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();
            $previousMonthStart = \Carbon\Carbon::createFromDate($year, $month, 1)->subMonth()->startOfMonth();
            $previousMonthEnd = \Carbon\Carbon::createFromDate($year, $month, 1)->subMonth()->endOfMonth();
        } else {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();
            $previousMonthStart = now()->subMonth()->startOfMonth();
            $previousMonthEnd = now()->subMonth()->endOfMonth();
        }

        $query = Call::query();

        // Appliquer les filtres
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
                $q->whereIn('provider_id', $providers);
            });
        }

        if ($request->filled('company_id')) {
            $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
            $query->whereHas('phonenumber', function ($q) use ($companies) {
                $q->whereIn('company_id', $companies);
            });
        }

        if ($request->filled('source_id')) {
            $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
            $query->where(function ($q) use ($sources) {
                $q->whereIn('source_id', $sources)
                  ->orWhere(function ($sq) use ($sources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($sources) {
                             $psq->whereIn('source_id', $sources);
                         });
                  });
            });
        }

        // Détecter si on est sur un jour en cours (pas terminé)
        $today = today();
        $now = now();
        $currentDay = (int) $now->format('d');

        // Données mois en cours
        $daily = $query->whereBetween('called_at', [$start, $end])
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
            ->get();

        // Données mois précédent (mêmes filtres)
        $previousQuery = Call::query();

        if ($request->filled('brand_name')) {
            $brands = $this->parseMultiSelect($request->brand_name);
            $previousQuery->whereIn('brand_name', $brands);
        }

        if ($request->filled('agent_name')) {
            $agents = $this->parseMultiSelect($request->agent_name);
            $previousQuery->whereIn('agent_name', $agents);
        }

        if ($request->filled('callcenter_id')) {
            $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
            $previousQuery->whereIn('callcenter_id', $callcenters);
        }

        if ($request->filled('carrier')) {
            $carriers = $this->parseMultiSelect($request->carrier);
            $previousQuery->whereIn('carrier', $carriers);
        }

        if ($request->filled('provider_id')) {
            $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
            $previousQuery->whereHas('phonenumber', function ($q) use ($providers) {
                $q->whereIn('provider_id', $providers);
            });
        }

        if ($request->filled('company_id')) {
            $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
            $previousQuery->whereHas('phonenumber', function ($q) use ($companies) {
                $q->whereIn('company_id', $companies);
            });
        }

        if ($request->filled('source_id')) {
            $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
            $previousQuery->where(function ($q) use ($sources) {
                $q->whereIn('source_id', $sources)
                  ->orWhere(function ($sq) use ($sources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($sources) {
                             $psq->whereIn('source_id', $sources);
                         });
                  });
            });
        }

        $previousDaily = $previousQuery->whereBetween('called_at', [$previousMonthStart, $previousMonthEnd])
            ->selectRaw('
                DAY(called_at) as day,
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Pour aujourd'hui (journée incomplète), comparer à la même heure du mois précédent
        $todayHourComparison = null;
        if ($now->format('Y-m-d') >= $start->format('Y-m-d') && $now->format('Y-m-d') <= $end->format('Y-m-d')) {
            // Construire la requête pour le même jour du mois précédent jusqu'à la même heure
            $previousMonthSameDayStart = $now->copy()->subMonth()->startOfDay();
            $previousMonthSameDayEnd = $now->copy()->subMonth();

            $todayPreviousQuery = Call::query();

            // Appliquer les mêmes filtres
            if ($request->filled('brand_name')) {
                $brands = $this->parseMultiSelect($request->brand_name);
                $todayPreviousQuery->whereIn('brand_name', $brands);
            }

            if ($request->filled('agent_name')) {
                $agents = $this->parseMultiSelect($request->agent_name);
                $todayPreviousQuery->whereIn('agent_name', $agents);
            }

            if ($request->filled('callcenter_id')) {
                $callcenters = array_map('intval', $this->parseMultiSelect($request->callcenter_id));
                $todayPreviousQuery->whereIn('callcenter_id', $callcenters);
            }

            if ($request->filled('carrier')) {
                $carriers = $this->parseMultiSelect($request->carrier);
                $todayPreviousQuery->whereIn('carrier', $carriers);
            }

            if ($request->filled('provider_id')) {
                $providers = array_map('intval', $this->parseMultiSelect($request->provider_id));
                $todayPreviousQuery->whereHas('phonenumber', function ($q) use ($providers) {
                    $q->whereIn('provider_id', $providers);
                });
            }

            if ($request->filled('company_id')) {
                $companies = array_map('intval', $this->parseMultiSelect($request->company_id));
                $todayPreviousQuery->whereHas('phonenumber', function ($q) use ($companies) {
                    $q->whereIn('company_id', $companies);
                });
            }

            if ($request->filled('source_id')) {
                $sources = array_map('intval', $this->parseMultiSelect($request->source_id));
                $todayPreviousQuery->where(function ($q) use ($sources) {
                    $q->whereIn('source_id', $sources)
                      ->orWhere(function ($sq) use ($sources) {
                          $sq->whereNull('source_id')
                             ->whereHas('phonenumber', function ($psq) use ($sources) {
                                 $psq->whereIn('source_id', $sources);
                             });
                      });
                });
            }

            $todayHourComparison = $todayPreviousQuery
                ->whereBetween('called_at', [$previousMonthSameDayStart, $previousMonthSameDayEnd])
                ->selectRaw('
                    COUNT(*) as calls,
                    COALESCE(SUM(payout), 0) as ca,
                    COALESCE(SUM(payout_source), 0) as reverse,
                    COALESCE(SUM(COALESCE(payout, 0) - COALESCE(payout_source, 0)), 0) as benefice,
                    COALESCE(SUM(total_duration), 0) as total_duration,
                    COALESCE(AVG(total_duration), 0) as avg_duration
                ')
                ->first();
        }

        // Mapper avec comparaisons
        $daily = $daily->map(function ($row) use ($previousDaily, $todayHourComparison, $currentDay, $now) {
            $day = (int) date('d', strtotime($row->date));
            $isToday = ($row->date === $now->format('Y-m-d'));

            // Pour aujourd'hui, utiliser la comparaison horaire. Sinon, utiliser la comparaison journalière complète
            if ($isToday && $todayHourComparison) {
                $prev = $todayHourComparison;
            } else {
                $prev = $previousDaily->get($day);
            }

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

            return [
                'date' => $row->date,
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

        // Calculer les totaux
        $totals = [
            'calls' => $daily->sum('calls'),
            'ca' => round($daily->sum('ca'), 2),
            'reverse' => round($daily->sum('reverse'), 2),
            'benefice' => round($daily->sum('benefice'), 2),
            'total_duration' => $daily->sum('total_duration'),
            'avg_duration' => $daily->avg('avg_duration') ? round($daily->avg('avg_duration')) : 0,
        ];

        return response()->json([
            'items' => $daily,
            'totals' => $totals,
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
