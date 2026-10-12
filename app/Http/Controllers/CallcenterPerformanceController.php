<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance et rentabilité des call centers et de leurs agents.
 *
 * Centre et agent sont déduits de calls.agent_name (voir config/callcenters.php),
 * callcenter_id / agent_id ne sont plus à jour.
 * Les jours sont des jours calendaires à l'heure de Paris.
 * Toutes les durées renvoyées sont en secondes, les montants en €.
 */
class CallcenterPerformanceController extends Controller
{
    private const TZ = 'Europe/Paris';

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
            'split_remote' => 'nullable|boolean',
        ]);

        $center = $this->centerSql($request->boolean('split_remote'));
        [$from, $to] = $this->range($request->from, $request->to);

        $centers = $this->centerTotals($center, $from, $to);
        $previous = $request->filled('prev_from') && $request->filled('prev_to')
            ? $this->centerTotals($center, ...$this->range($request->prev_from, $request->prev_to))
            : null;

        return response()->json([
            'centers' => $centers,
            'previous' => $previous,
            'daily' => $this->daily($center, $from, $to),
            'hourly' => $this->hourly($center, $from, $to),
            'agents' => $this->agents($center, $from, $to),
            'unattributed' => DB::table('calls')
                ->whereBetween('called_at', [$from, $to])
                ->whereRaw("$center IS NULL")
                ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(payout), 0) AS payout')
                ->first(),
        ]);
    }

    public function agentDaily(Request $request): JsonResponse
    {
        $request->validate([
            'agent' => 'required|string|max:255',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        [$from, $to] = $this->range($request->from, $request->to);
        $day = $this->parisDateSql($from, $to);

        $rows = DB::table('calls')
            ->whereBetween('called_at', [$from, $to])
            ->where('agent_name', $request->agent)
            ->selectRaw("$day AS date, " . $this->sumsSql())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json($rows);
    }

    /**
     * Totaux par centre, avec coût alloué sur la période.
     */
    private function centerTotals(string $center, Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        $rows = $this->attributedCalls($center, $from, $to)
            ->selectRaw("
                $center AS id,
                COUNT(DISTINCT calls.agent_name) AS agents,
                COUNT(DISTINCT calls.agent_name, $day) AS agent_days,
                COUNT(DISTINCT $day) AS days,
                " . $this->sumsSql())
            // Grouper sur l'expression : « id » désignerait la colonne calls.id
            ->groupByRaw($center)
            ->get()
            ->keyBy('id');

        // Dans l'ordre de la config
        return collect(config('callcenters'))
            ->filter(fn ($conf, $key) => $rows->has($key))
            ->map(function ($conf, $key) use ($rows, $from, $to) {
                $cost = $this->allocatedCost($conf['costs'], $from, $to);

                return [
                    ...(array) $rows[$key],
                    'name' => $conf['label'],
                    'label' => $conf['label'],
                    'color' => $conf['color'],
                    'tracked' => true,
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
    private function daily(string $center, Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        return $this->attributedCalls($center, $from, $to)
            ->selectRaw("
                $day AS date,
                $center AS id,
                COUNT(DISTINCT calls.agent_name) AS agent_days,
                " . $this->sumsSql())
            ->groupByRaw("date, $center")
            ->orderBy('date')
            ->get()
            ->all();
    }

    /**
     * Totaux par centre et par heure de la journée (heure de Paris).
     * agent_hours = nombre de couples agent / jour / heure avec au moins un appel.
     */
    private function hourly(string $center, Carbon $from, Carbon $to): array
    {
        $local = $this->parisDateTimeSql($from, $to);

        return $this->attributedCalls($center, $from, $to)
            ->selectRaw("
                $center AS id,
                HOUR($local) AS hour,
                COUNT(*) AS calls,
                COALESCE(SUM(payout), 0) AS payout,
                COUNT(DISTINCT calls.agent_name, DATE($local)) AS agent_hours
            ")
            ->groupByRaw("$center, hour")
            ->orderBy('hour')
            ->get()
            ->all();
    }

    /**
     * Totaux par agent (identifié par son nom de ligne : dedi003, prepose995...).
     */
    private function agents(string $center, Carbon $from, Carbon $to): array
    {
        $day = $this->parisDateSql($from, $to);

        return $this->attributedCalls($center, $from, $to)
            ->selectRaw("
                calls.agent_name AS id,
                calls.agent_name AS username,
                $center AS callcenter_id,
                COUNT(DISTINCT $day) AS days,
                " . $this->sumsSql())
            ->groupByRaw("calls.agent_name, $center")
            ->get()
            ->all();
    }

    /**
     * Appels de la période rattachés à un centre.
     */
    private function attributedCalls(string $center, Carbon $from, Carbon $to): Builder
    {
        return DB::table('calls')
            ->whereBetween('called_at', [$from, $to])
            ->whereRaw("$center IS NOT NULL");
    }

    /**
     * Centre d'un appel en SQL, d'après agent_name et la config.
     * Noms exacts d'abord, puis préfixes du plus long au plus court. NULL si aucun centre.
     */
    private function centerSql(bool $splitRemote): string
    {
        $pdo = DB::getPdo();
        $exact = [];
        $prefixes = [];

        foreach (config('callcenters') as $key => $conf) {
            if (!empty($conf['optional']) && !$splitRemote) {
                continue;
            }
            foreach ($conf['agents'] ?? [] as $name) {
                $exact[] = 'WHEN calls.agent_name = ' . $pdo->quote($name) . ' THEN ' . $pdo->quote($key);
            }
            foreach ($conf['prefixes'] ?? [] as $prefix) {
                $prefixes[$prefix] = 'WHEN calls.agent_name LIKE ' . $pdo->quote(addcslashes($prefix, '%_\\') . '%') . ' THEN ' . $pdo->quote($key);
            }
        }

        uksort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        return '(CASE ' . implode(' ', [...$exact, ...array_values($prefixes)]) . ' END)';
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
