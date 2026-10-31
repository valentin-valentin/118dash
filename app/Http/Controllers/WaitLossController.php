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
 * Attente et appels perdus, Voxnode et notre serveur côte à côte.
 *
 * Sur chaque appel (table calls) :
 *   - décroché : un agent a été en ligne (duration_agent > 0) ;
 *   - perdu : aucun agent (duration_agent nul ou 0), le client a raccroché avant ;
 *   - attente avant décroché = total - durée agent - durée transfert ;
 *   - attente avant abandon = durée totale de l'appel perdu.
 * Ces attentes comprennent l'accueil et l'attente forcée. Pour nos appels, forced_wait et queue_wait
 * (renseignés depuis fin octobre 2026) séparent l'attente forcée de la vraie attente faute d'agent.
 * Les appels envoyés aux agents IA (agent_name = 'ai', tests) sont exclus.
 * Jours et heures en heure de Paris.
 */
class WaitLossController extends Controller
{
    private const TZ = 'Europe/Paris';

    // Tranches d'attente avant abandon (secondes)
    private const BUCKETS = [[0, 30], [30, 60], [60, 120], [120, 180], [180, 300], [300, null]];

    public function index(): Response
    {
        return Inertia::render('WaitLoss');
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from, self::TZ)->startOfDay();
        $to = Carbon::parse($request->to, self::TZ)->endOfDay();
        $paris = new DateTimeZone(self::TZ);

        // Accumulateurs par groupe (plateforme, puis plateforme × heure, plateforme × jour)
        $empty = fn () => ['calls' => 0, 'lost' => 0, 'answer_waits' => [], 'abandon_waits' => []];
        $platforms = ['all' => $empty(), 'voxnode' => $empty(), 'selfhosted' => $empty()];
        $hours = [];
        $days = [];
        $buckets = ['voxnode' => array_fill(0, count(self::BUCKETS), 0), 'selfhosted' => array_fill(0, count(self::BUCKETS), 0)];
        // Nos appels détaillés (forced_wait / queue_wait renseignés)
        $detail = ['calls' => 0, 'answered' => 0, 'lost_welcome' => 0, 'lost_forced' => 0, 'lost_queue' => 0, 'queue_waits' => [], 'forced_waits' => []];

        // Instants exacts (epoch) : called_at est un TIMESTAMP que MySQL renvoie dans le fuseau de son serveur ;
        // UNIX_TIMESTAMP / FROM_UNIXTIME donnent le bon résultat quel que soit ce fuseau.
        $rows = DB::table('calls')
            ->whereRaw('called_at BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)', [$from->getTimestamp(), $to->getTimestamp()])
            ->where(fn ($q) => $q->whereNull('agent_name')->orWhere('agent_name', '!=', 'ai'))
            ->selectRaw('UNIX_TIMESTAMP(called_at) AS ts, platform, total_duration, duration_agent, duration_transfer, forced_wait, queue_wait')
            ->orderBy('id')
            ->lazy(5000);

        foreach ($rows as $r) {
            $platform = $r->platform === 'selfhosted' ? 'selfhosted' : 'voxnode';
            $local = (new \DateTimeImmutable('@' . (int) $r->ts))->setTimezone($paris);
            $hour = (int) $local->format('G');
            $day = $local->format('Y-m-d');
            $total = (int) $r->total_duration;
            $agent = (int) $r->duration_agent;
            $lost = $agent <= 0;

            foreach (['all', $platform] as $key) {
                $this->add($platforms[$key], $lost, $total, $agent, (int) $r->duration_transfer);
            }
            $hours[$platform][$hour] ??= $empty();
            $days[$platform][$day] ??= $empty();
            $days['all'][$day] ??= $empty();
            $this->add($hours[$platform][$hour], $lost, $total, $agent, (int) $r->duration_transfer);
            $this->add($days[$platform][$day], $lost, $total, $agent, (int) $r->duration_transfer);
            $this->add($days['all'][$day], $lost, $total, $agent, (int) $r->duration_transfer);

            if ($lost) {
                $buckets[$platform][$this->bucket($total)]++;
            }

            if ($platform === 'selfhosted' && $r->forced_wait !== null && $r->queue_wait !== null) {
                $detail['calls']++;
                $queue = (int) $r->queue_wait;
                $forced = (int) $r->forced_wait;
                if (!$lost) {
                    $detail['answered']++;
                    $detail['queue_waits'][] = $queue;
                } elseif ($queue > 0) {
                    $detail['lost_queue']++;
                } elseif ($forced > 0) {
                    $detail['lost_forced']++;
                } else {
                    $detail['lost_welcome']++;
                }
                if ($forced > 0) {
                    $detail['forced_waits'][] = $forced;
                }
            }
        }

        $summary = fn ($g) => [
            'calls' => $g['calls'],
            'answered' => $g['calls'] - $g['lost'],
            'lost' => $g['lost'],
            'answer_wait_median' => $this->median($g['answer_waits']),
            'abandon_wait_median' => $this->median($g['abandon_waits']),
        ];

        $byHour = [];
        foreach (['voxnode', 'selfhosted'] as $p) {
            foreach ($hours[$p] ?? [] as $h => $g) {
                $byHour[$h][$p] = $summary($g);
            }
        }
        ksort($byHour);

        $byDay = [];
        foreach (['all', 'voxnode', 'selfhosted'] as $p) {
            foreach ($days[$p] ?? [] as $d => $g) {
                $byDay[$d][$p] = $summary($g);
            }
        }
        ksort($byDay);

        return response()->json([
            'platforms' => array_map($summary, $platforms),
            'buckets' => array_map(fn ($b, $i) => ['from' => $b[0], 'to' => $b[1], 'voxnode' => $buckets['voxnode'][$i], 'selfhosted' => $buckets['selfhosted'][$i]], self::BUCKETS, array_keys(self::BUCKETS)),
            'hours' => array_map(fn ($h, $v) => ['hour' => $h, ...$v], array_keys($byHour), $byHour),
            'days' => array_map(fn ($d, $v) => ['date' => $d, ...$v], array_keys($byDay), $byDay),
            'selfhosted_detail' => $detail['calls'] ? [
                'calls' => $detail['calls'],
                'answered' => $detail['answered'],
                'lost_welcome' => $detail['lost_welcome'],
                'lost_forced' => $detail['lost_forced'],
                'lost_queue' => $detail['lost_queue'],
                'queue_wait_median' => $this->median($detail['queue_waits']),
                'answered_within_30' => count(array_filter($detail['queue_waits'], fn ($w) => $w <= 30)),
                'answered_within_60' => count(array_filter($detail['queue_waits'], fn ($w) => $w <= 60)),
                'forced_wait_median' => $this->median($detail['forced_waits']),
            ] : null,
        ]);
    }

    private function add(array &$g, bool $lost, int $total, int $agent, int $transfer): void
    {
        $g['calls']++;
        if ($lost) {
            $g['lost']++;
            $g['abandon_waits'][] = $total;
        } else {
            $g['answer_waits'][] = max(0, $total - $agent - $transfer);
        }
    }

    private function bucket(int $seconds): int
    {
        foreach (self::BUCKETS as $i => [$min, $max]) {
            if ($max === null || $seconds < $max) {
                return $i;
            }
        }

        return count(self::BUCKETS) - 1;
    }

    private function median(array $values): ?int
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return (int) round($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
