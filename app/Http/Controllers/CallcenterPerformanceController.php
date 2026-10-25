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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    // En dessous de ce score MOS, un appel est compté comme dégradé
    private const DEGRADED_MOS = 3.1;

    // Score MOS d'un appel téléphonique parfait (100 %)
    private const MAX_MOS = 4.4;

    public function index(): Response
    {
        return Inertia::render('CallcenterPerformance');
    }

    /**
     * Rapport imprimable (PDF via l'impression du navigateur).
     */
    public function report(Request $request): Response
    {
        return Inertia::render('CallcenterReport', [
            'query' => $request->only(['from', 'to', 'prev_from', 'prev_to', 'split_remote', 'mode', 'agents', 'centers', 'download']),
        ]);
    }

    /**
     * Export CSV complet (centres, agents, jour par jour), sans rien masquer.
     * Une seule table : la colonne « niveau » indique le type de ligne.
     */
    public function csv(Request $request): StreamedResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'split_remote' => 'nullable|boolean',
        ]);

        $center = $this->centerSql($request->boolean('split_remote'));
        [$from, $to] = $this->range($request->from, $request->to);
        $day = $this->parisDateSql($from, $to);
        $labels = collect(config('callcenters'))->map(fn ($c) => $c['label']);

        $centers = collect($this->centerTotals($center, $from, $to));

        $rows = [];
        foreach ($centers as $c) {
            $rows[] = ['niveau' => 'centre', 'date' => '', 'centre' => $c['label'], 'agent' => '', ...$this->csvValues((object) $c, $c['cost'])];
        }
        $centersById = $centers->keyBy('id');
        foreach ($this->agents($center, $from, $to) as $a) {
            $a->agent_days = $a->days; // pour un agent, journées-agent = jours actifs
            // Part du coût du centre : au prorata de ses jours travaillés
            $c = $centersById->get($a->callcenter_id);
            $cost = $c && $c['cost'] !== null && $c['agent_days'] ? $c['cost'] * $a->days / $c['agent_days'] : null;
            $rows[] = ['niveau' => 'agent', 'date' => '', 'centre' => $labels[$a->callcenter_id], 'agent' => $a->id, ...$this->csvValues($a, $cost)];
        }
        foreach ($this->daily($center, $from, $to) as $d) {
            $rows[] = ['niveau' => 'centre_jour', 'date' => $d->date, 'centre' => $labels[$d->id], 'agent' => '', ...$this->csvValues($d)];
        }
        $agentDays = $this->attributedCalls($center, $from, $to)
            ->selectRaw("$day AS date, calls.agent_name AS agent, $center AS center_key, " . $this->sumsSql())
            ->groupByRaw("date, calls.agent_name, $center")
            ->orderBy('date')
            ->orderBy('agent')
            ->get();
        foreach ($agentDays as $d) {
            $d->agent_days = 1;
            $rows[] = ['niveau' => 'agent_jour', 'date' => $d->date, 'centre' => $labels[$d->center_key], 'agent' => $d->agent, ...$this->csvValues($d)];
        }

        $filename = "performance-centres_{$request->from}_{$request->to}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            fputcsv($out, array_keys($rows[0] ?? ['niveau' => '']), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Valeurs d'une ligne CSV : sommes brutes + indicateurs calculés à partir des sommes.
     * Conversation / pause : répartition mesurée sur les appels qui ont le détail,
     * appliquée à la durée agent (comme sur la page).
     */
    private function csvValues(object $r, ?float $cost = null): array
    {
        $calls = (int) $r->calls;
        $payout = (float) $r->payout;
        $agentS = (int) $r->agent_s;
        $totalS = (int) $r->total_s;
        $phone = (int) $r->talk_s + (int) $r->pause_s;
        $talkShare = $phone ? (int) $r->talk_s / $phone : null;
        $agentDays = isset($r->agent_days) ? (int) $r->agent_days : null;
        $div = fn ($a, $b, $d = 2) => $b ? round($a / $b, $d) : '';
        $talkMinutes = $talkShare === null ? null : $agentS * $talkShare / 60;
        $qualityCalls = (int) $r->quality_calls;
        $mos = $qualityCalls ? (float) $r->quality_sum / $qualityCalls : null;

        return [
            'appels' => $calls,
            'agents_actifs' => $r->agents ?? '',
            'journees_agent' => $agentDays ?? '',
            'jours_actifs' => $r->days ?? '',
            'payout_eur' => round($payout, 2),
            'cout_eur' => $cost === null ? '' : round($cost, 2),
            'marge_eur' => $cost === null ? '' : round($payout - $cost, 2),
            'duree_totale_s' => $totalS,
            'attente_s' => (int) $r->wait_s,
            'duree_agent_s' => $agentS,
            'conversation_s' => $talkShare === null ? '' : round($agentS * $talkShare),
            'pause_s' => $talkShare === null ? '' : round($agentS * (1 - $talkShare)),
            'transfert_s' => (int) $r->transfer_s,
            'payout_par_appel' => $div($payout, $calls),
            'payout_par_heure_agent' => $div($payout, $agentS / 3600),
            'payout_par_journee_agent' => $agentDays ? $div($payout, $agentDays) : '',
            'appels_par_journee_agent' => $agentDays ? $div($calls, $agentDays, 1) : '',
            'duree_moyenne_s' => $div($totalS, $calls, 0),
            'duree_agent_moyenne_s' => $div($agentS, $calls, 0),
            'ratio_temps_agent' => $div($agentS, $totalS, 4),
            'ratio_transfert' => $div((int) $r->transfer_s, $totalS, 4),
            // Minutes où l'agent parle vraiment avec un client, et ce que coûte chacune
            'minutes_parlees' => $talkMinutes === null ? '' : round($talkMinutes),
            'cout_par_minute_parlee' => $cost === null || !$talkMinutes ? '' : round($cost / $talkMinutes, 4),
            'qualite_audio_pct' => $mos === null ? '' : min(100, round($mos / self::MAX_MOS * 100)),
            'qualite_audio_mos' => $mos === null ? '' : round($mos, 2),
            'appels_qualite_mesuree' => $qualityCalls,
            'appels_degrades' => (int) $r->quality_degraded,
        ];
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'prev_from' => 'nullable|date_format:Y-m-d',
            'prev_to' => 'nullable|date_format:Y-m-d',
            'split_remote' => 'nullable|boolean',
            'cmp_from' => 'nullable|date_format:Y-m-d',
            'cmp_to' => 'nullable|date_format:Y-m-d',
        ]);

        $center = $this->centerSql($request->boolean('split_remote'));
        [$from, $to] = $this->range($request->from, $request->to);

        $centers = $this->centerTotals($center, $from, $to);
        $previousRange = $request->filled('prev_from') && $request->filled('prev_to')
            ? $this->range($request->prev_from, $request->prev_to)
            : null;

        return response()->json([
            // Tous les centres de la config (y compris sans appel sur la période)
            'config_centers' => collect(config('callcenters'))
                ->map(fn ($c, $key) => ['id' => $key, 'label' => $c['label'], 'color' => $c['color'], 'optional' => !empty($c['optional'])])
                ->values(),
            'centers' => $centers,
            'previous' => $previousRange ? $this->centerTotals($center, ...$previousRange) : null,
            'daily' => $this->daily($center, $from, $to),
            // Jour par jour de la période de comparaison des graphiques (la page envoie J-7)
            'compare_daily' => $request->filled('cmp_from') && $request->filled('cmp_to')
                ? $this->daily($center, ...$this->range($request->cmp_from, $request->cmp_to))
                : null,
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
     * Qualité audio (calls.audio_quality, score MOS 1-5, NULL si non mesuré) : somme et nombre
     * d'appels mesurés, pour recalculer la moyenne après regroupement.
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
            SUM(total_duration < duration_agent + duration_transfer) AS anomalies,
            COUNT(audio_quality) AS quality_calls,
            COALESCE(SUM(audio_quality), 0) AS quality_sum,
            COALESCE(SUM(audio_quality < ' . self::DEGRADED_MOS . '), 0) AS quality_degraded
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
