<?php

namespace App\Http\Controllers;

use App\Services\RejectedCallAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RejectedCallController extends Controller
{
    public function __construct(private RejectedCallAnalyzer $analyzer)
    {
    }

    /**
     * Page Appels rejetés
     */
    public function index(): Response
    {
        return Inertia::render('RejectedCalls');
    }

    /**
     * Tableau quotidien des rejets, ventilés par raison
     */
    public function dailyBreakdown(Request $request): JsonResponse
    {
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $start = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->startOfMonth();
            $end = \Carbon\Carbon::createFromDate($year, $month, 1, 'Europe/Paris')->endOfMonth();
        } else {
            $start = now('Europe/Paris')->startOfMonth();
            $end = now('Europe/Paris')->endOfMonth();
        }

        $calls = $this->analyzer->withReasons($this->analyzer->dedupedCalls($request, $start, $end));

        // Générer les jours du mois jusqu'à aujourd'hui (pas au-delà)
        $today = now('Europe/Paris');
        $lastDay = $today->lt($end) ? $today : $end;

        $emptyReasons = array_fill_keys(RejectedCallAnalyzer::REASONS, 0);
        $items = [];
        $currentDay = $start->copy();
        while ($currentDay->lte($lastDay)) {
            $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$currentDay->dayOfWeek];
            $items[$currentDay->format('Y-m-d')] = array_merge([
                'date' => $currentDay->format('Y-m-d'),
                'date_label' => $dayName . ' ' . $currentDay->format('d/m'),
                'total' => 0,
            ], $emptyReasons);
            $currentDay->addDay();
        }

        $totals = array_merge(['total' => 0], $emptyReasons);

        foreach ($calls as $call) {
            $day = $call['paris_at']->format('Y-m-d');
            if (!isset($items[$day])) {
                continue;
            }
            $items[$day][$call['reason']]++;
            $items[$day]['total']++;
            $totals[$call['reason']]++;
            $totals['total']++;
        }

        return response()->json([
            'items' => array_values($items),
            'totals' => $totals,
        ]);
    }

    /**
     * Détail des rejets d'un jour donné (pour comprendre au cas par cas)
     */
    public function details(Request $request): JsonResponse
    {
        $date = $request->input('date');
        if (!$date) {
            return response()->json(['error' => 'Date requise'], 400);
        }

        $dayStart = \Carbon\Carbon::parse($date, 'Europe/Paris')->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        $calls = $this->analyzer->withReasons($this->analyzer->dedupedCalls($request, $dayStart, $dayEnd));

        // Infos numéro / source / company pour chaque appel
        $phoneIds = array_values(array_unique(array_filter(array_column($calls, 'phonenumber_id'))));
        $phones = DB::table('phonenumbers')
            ->leftJoin('sources', 'sources.id', '=', 'phonenumbers.source_id')
            ->leftJoin('companies', 'companies.id', '=', 'phonenumbers.company_id')
            ->whereIn('phonenumbers.id', $phoneIds)
            ->get([
                'phonenumbers.id',
                'phonenumbers.phonenumber',
                'sources.name as source_name',
                'companies.name as company_name',
            ])
            ->keyBy('id');

        $items = array_map(function ($call) use ($phones) {
            $phone = $call['phonenumber_id'] ? ($phones[$call['phonenumber_id']] ?? null) : null;

            return [
                'time' => $call['paris_at']->format('H:i:s'),
                'from' => $call['from'],
                'to' => $call['to'],
                'phonenumber' => $phone?->phonenumber,
                'source_name' => $phone?->source_name,
                'company_name' => $phone?->company_name,
                'reason' => $call['reason'],
                'delay_minutes' => $call['delay_minutes'],
                'retries' => $call['retries'],
            ];
        }, $calls);

        usort($items, fn ($a, $b) => strcmp($a['time'], $b['time']));

        $dayName = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$dayStart->dayOfWeek];

        return response()->json([
            'date' => $date,
            'date_label' => $dayName . ' ' . $dayStart->format('d/m/Y'),
            'items' => $items,
        ]);
    }
}
