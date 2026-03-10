<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Hero KPIs avec comparaison période précédente
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->input('period', 'today'); // today, this_week, this_month

        // Définir les périodes
        [$start, $end, $prevStart, $prevEnd] = $this->getPeriodDates($period);

        // Période actuelle
        $current = Call::whereBetween('called_at', [$start, $end])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(payout - payout_source), 0) as benefice,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        // Période précédente
        $previous = Call::whereBetween('called_at', [$prevStart, $prevEnd])
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(payout - payout_source), 0) as benefice,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->first();

        return response()->json([
            'current' => [
                'calls' => (int) $current->calls,
                'ca' => round((float) $current->ca, 2),
                'reverse' => round((float) $current->reverse, 2),
                'benefice' => round((float) $current->benefice, 2),
                'avg_duration' => round((float) $current->avg_duration),
            ],
            'previous' => [
                'calls' => (int) $previous->calls,
                'ca' => round((float) $previous->ca, 2),
                'reverse' => round((float) $previous->reverse, 2),
                'benefice' => round((float) $previous->benefice, 2),
                'avg_duration' => round((float) $previous->avg_duration),
            ],
        ]);
    }

    /**
     * Tableau jour par jour
     */
    public function dailyBreakdown(Request $request): JsonResponse
    {
        $period = $request->input('period', 'this_month');
        [$start, $end] = $this->getPeriodDates($period);

        $daily = Call::whereBetween('called_at', [$start, $end])
            ->selectRaw('
                DATE(called_at) as date,
                COUNT(*) as calls,
                COALESCE(SUM(payout), 0) as ca,
                COALESCE(SUM(payout_source), 0) as reverse,
                COALESCE(SUM(payout - payout_source), 0) as benefice,
                COALESCE(SUM(total_duration), 0) as total_duration,
                COALESCE(AVG(total_duration), 0) as avg_duration
            ')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'calls' => (int) $row->calls,
                    'ca' => round((float) $row->ca, 2),
                    'reverse' => round((float) $row->reverse, 2),
                    'benefice' => round((float) $row->benefice, 2),
                    'total_duration' => (int) $row->total_duration,
                    'avg_duration' => round((float) $row->avg_duration),
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
     * Retourne les dates de début/fin pour une période donnée
     */
    private function getPeriodDates(string $period): array
    {
        return match ($period) {
            'today' => [
                today()->startOfDay(),
                today()->endOfDay(),
                today()->subDay()->startOfDay(),
                today()->subDay()->endOfDay(),
            ],
            'this_week' => [
                now()->startOfWeek(),
                now()->endOfWeek(),
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
            ],
            'this_month' => [
                now()->startOfMonth(),
                now()->endOfMonth(),
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ],
            default => [
                today()->startOfDay(),
                today()->endOfDay(),
                today()->subDay()->startOfDay(),
                today()->subDay()->endOfDay(),
            ],
        };
    }

    /**
     * Example paginated + filterable table data.
     * Replace with your actual model + query.
     */
    public function example(Request $request): JsonResponse
    {
        // ── Placeholder (remove once you wire up real data) ──────────────────
        return response()->json(['items' => [], 'total' => 0]);
    }
}
