<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance et rentabilité des call centers et de leurs agents.
 *
 * Les centres et agents viennent de calls.callcenter_id / calls.agent_id.
 * Les jours sont des jours calendaires à l'heure de Paris.
 * Toutes les durées renvoyées sont en secondes, les montants en €.
 */
class CallcenterPerformanceController extends Controller
{
    private const TZ = 'Europe/Paris';

    // Centres hors config : couleurs de repli
    private const EXTRA_COLORS = ['#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    public function index(): Response
    {
        return Inertia::render('CallcenterPerformance');
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'prev_from' => 'nullable|date_format:Y-m-d',
            'prev_to' => 'nullable|date_format:Y-m-d',
        ]);

        [$from, $to] = $this->range($request->from, $request->to);

        $centers = $this->centerTotals($from, $to);
        $previous = $request->filled('prev_from') && $request->filled('prev_to')
            ? $this->centerTotals(...$this->range($request->prev_from, $request->prev_to))
            : null;

        return response()->json([
            'centers' => $centers,
            'previous' => $previous,
            'daily' => $this->daily($from, $to),
            'hourly' => $this->hourly($from, $to),
            'agents' => $this->agents($from, $to),
            'unattributed' => DB::table('calls')
                ->whereBetween('called_at', [$from, $to])
                ->whereNull('callcenter_id')
                ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(payout), 0) AS payout')
                ->first(),
        ]);
    }

    public function agentDaily(Request $request, int $agent): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'callcenter_id' => 'nullable|integer',
        ]);

        [$from, $to] = $this->range($request->from, $request->to);
        $day = $this->parisDateSql($from, $to);

        $rows = DB::table('calls')
            ->whereBetween('called_at', [$from, $to])
            ->where('agent_id', $agent)
            ->when($request->filled('callcenter_id'), fn ($q) => $q->where('callcenter_id', $request->callcenter_id))
            ->selectRaw("$day AS date, " . $this->sumsSql())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json($rows);
    }

    /**
     * Totaux par centre, avec coût alloué sur la période.
     */
    private function centerTotals(Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        $rows = DB::table('calls')
            ->join('callcenters', 'callcenters.id', '=', 'calls.callcenter_id')
            ->whereBetween('called_at', [$from, $to])
            ->selectRaw("
                calls.callcenter_id AS id,
                callcenters.name AS name,
                COUNT(DISTINCT calls.agent_id) AS agents,
                COUNT(DISTINCT calls.agent_id, $day) AS agent_days,
                COUNT(DISTINCT $day) AS days,
                " . $this->sumsSql())
            ->groupBy('calls.callcenter_id', 'callcenters.name')
            ->get();

        $config = config('callcenters');
        $extra = 0;

        return $rows
            ->sortBy(fn ($r) => isset($config[$r->id]) ? array_search($r->id, array_keys($config)) : 100 + $r->id)
            ->map(function ($r) use ($config, $from, $to, &$extra) {
                $conf = $config[$r->id] ?? null;
                $cost = $conf ? $this->allocatedCost($conf['costs'], $from, $to) : null;

                return [
                    ...(array) $r,
                    'label' => $conf['label'] ?? $r->name,
                    'color' => $conf['color'] ?? self::EXTRA_COLORS[$extra++ % count(self::EXTRA_COLORS)],
                    'tracked' => $conf !== null,
                    'cost' => $cost['amount'] ?? null,
                    'cost_estimated' => $cost['estimated'] ?? false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Totaux par centre et par jour.
     */
    private function daily(Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        return DB::table('calls')
            ->whereBetween('called_at', [$from, $to])
            ->whereNotNull('callcenter_id')
            ->selectRaw("
                $day AS date,
                callcenter_id AS id,
                COUNT(DISTINCT agent_id) AS agent_days,
                " . $this->sumsSql())
            ->groupBy('date', 'callcenter_id')
            ->orderBy('date')
            ->get()
            ->all();
    }

    /**
     * Totaux par centre et par heure de la journée (heure de Paris).
     * agent_hours = nombre de couples agent / jour / heure avec au moins un appel.
     */
    private function hourly(Carbon $from, Carbon $to): array
    {
        $local = $this->parisDateTimeSql($from, $to);

        return DB::table('calls')
            ->whereBetween('called_at', [$from, $to])
            ->whereNotNull('callcenter_id')
            ->selectRaw("
                callcenter_id AS id,
                HOUR($local) AS hour,
                COUNT(*) AS calls,
                COALESCE(SUM(payout), 0) AS payout,
                COUNT(DISTINCT agent_id, DATE($local)) AS agent_hours
            ")
            ->groupBy('callcenter_id', 'hour')
            ->orderBy('hour')
            ->get()
            ->all();
    }

    /**
     * Totaux par agent.
     */
    private function agents(Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        return DB::table('calls')
            ->leftJoin('agents', 'agents.id', '=', 'calls.agent_id')
            ->whereBetween('called_at', [$from, $to])
            ->whereNotNull('calls.agent_id')
            ->whereNotNull('calls.callcenter_id')
            ->selectRaw("
                calls.agent_id AS id,
                calls.callcenter_id AS callcenter_id,
                NULLIF(agents.name, '') AS name,
                agents.voxnode_username AS username,
                COUNT(DISTINCT $day) AS days,
                " . $this->sumsSql())
            ->groupBy('calls.agent_id', 'calls.callcenter_id', 'agents.name', 'agents.voxnode_username')
            ->get()
            ->all();
    }

    /**
     * Sommes communes à toutes les agrégations.
     * Attente = total - agent - transfert, sans correction : les appels où elle est
     * négative (durées incohérentes) sont comptés dans `anomalies`.
     */
    private function sumsSql(): string
    {
        return '
            COUNT(*) AS calls,
            COALESCE(SUM(payout), 0) AS payout,
            COALESCE(SUM(total_duration), 0) AS total_s,
            COALESCE(SUM(duration_agent), 0) AS agent_s,
            COALESCE(SUM(duration_transfer), 0) AS transfer_s,
            COALESCE(SUM(total_duration - duration_agent - duration_transfer), 0) AS wait_s,
            COALESCE(SUM(phone_duration_agent), 0) AS talk_s,
            COALESCE(SUM(phone_duration_pause), 0) AS pause_s,
            COUNT(phone_duration_agent) AS phone_calls,
            SUM(total_duration < duration_agent + duration_transfer) AS anomalies
        ';
    }

    /**
     * Coût du centre sur la période : coût du mois pour un mois entier,
     * au prorata des jours sinon (marqué comme estimé).
     */
    private function allocatedCost(array $costs, Carbon $from, Carbon $to): ?array
    {
        ksort($costs);
        $start = $from->copy()->tz(self::TZ)->startOfDay();
        $end = $to->copy()->tz(self::TZ)->startOfDay();

        $amount = 0;
        $estimated = false;
        $month = $start->copy()->startOfMonth();

        while ($month <= $end) {
            $monthly = null;
            foreach ($costs as $effective => $value) {
                if ($effective <= $month->format('Y-m')) {
                    $monthly = $value;
                }
            }
            if ($monthly === null) {
                return null;
            }

            $daysInMonth = $month->daysInMonth;
            $first = max($start, $month);
            $last = min($end, $month->copy()->endOfMonth()->startOfDay());
            $days = $first->diffInDays($last) + 1;

            if ($days < $daysInMonth) {
                $estimated = true;
            }
            $amount += $monthly * $days / $daysInMonth;
            $month->addMonth();
        }

        return ['amount' => round($amount, 2), 'estimated' => $estimated];
    }

    /**
     * Bornes UTC d'une période donnée en jours de Paris.
     */
    private function range(string $from, string $to): array
    {
        return [
            Carbon::parse($from, self::TZ)->startOfDay()->utc(),
            Carbon::parse($to, self::TZ)->endOfDay()->utc(),
        ];
    }

    /**
     * Date de Paris d'un appel en SQL (called_at est en UTC).
     */
    private function parisDateSql(Carbon $from, Carbon $to): string
    {
        return 'DATE(' . $this->parisDateTimeSql($from, $to) . ')';
    }

    /**
     * Date et heure de Paris d'un appel en SQL.
     * Les tables de fuseaux MySQL ne sont pas forcément chargées : on calcule
     * le décalage heure d'été / heure d'hiver à partir des changements d'heure de la période.
     */
    private function parisDateTimeSql(Carbon $from, Carbon $to): string
    {
        $transitions = (new DateTimeZone(self::TZ))
            ->getTransitions($from->timestamp - 86400, $to->timestamp + 86400);

        $offset = (string) $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $t) {
            $at = gmdate('Y-m-d H:i:s', $t['ts']);
            $offset = "IF(calls.called_at >= '$at', {$t['offset']}, $offset)";
        }

        return "DATE_ADD(calls.called_at, INTERVAL $offset SECOND)";
    }
}
