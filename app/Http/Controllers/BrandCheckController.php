<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Marques à vérifier : repérer les marques dont le numéro de transfert ne répond plus normalement
 * (numéro changé, non attribué, fermé…), d'après la durée réelle des mises en relation.
 *
 * Durée réelle d'un transfert (calls.transfer_real_duration) :
 *   - Voxnode : duration_transfer comprend une attente avant la mise en relation ; la vraie durée est celle de
 *     l'enregistrement record_transfer, un mp3 à débit constant de 32 kbit/s : durée = Content-Length × 8 / 32000.
 *     Mesurée par une requête HEAD, à l'ouverture de la page, pour les transferts de la période pas encore mesurés.
 *     65535 = enregistrement introuvable ou illisible (pas remesuré).
 *   - Notre serveur : duration_transfer est déjà la durée depuis le décroché de la marque.
 * Le service interne (+33184131572) n'est pas une marque : exclu. Jours en heure de Paris.
 */
class BrandCheckController extends Controller
{
    private const TZ = 'Europe/Paris';

    private const INTERNAL_SERVICE = '184131572';

    private const UNREADABLE = 65535;

    private const MP3_BYTES_PER_SECOND = 32000 / 8;

    // Mise en relation de moins de 15 s : message « numéro non attribué », répondeur « fermé », occupé…
    private const SHORT_SECONDS = 15;

    private const MEASURE_BATCH = 600;

    public function index(): Response
    {
        return Inertia::render('BrandChecks');
    }

    /**
     * Transferts Voxnode de la période (Paris) : appels transférés vers une marque, hors service interne.
     */
    private function transfers(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to)
    {
        return DB::table('calls')
            ->where('called_at', '>=', $from->copy()->utc())
            ->where('called_at', '<=', $to->copy()->utc())
            ->where('duration_transfer', '>', 0)
            ->where('brand_phonenumber', '!=', '')
            ->whereRaw('RIGHT(brand_phonenumber, 9) <> ?', [self::INTERNAL_SERVICE]);
    }

    private function period(Request $request): array
    {
        $days = (int) ($request->validate(['days' => 'nullable|integer|in:7,30'])['days'] ?? 7);
        $to = now(self::TZ)->endOfDay();

        return [$to->copy()->subDays($days - 1)->startOfDay(), $to, $days];
    }

    /**
     * Mesure un lot de transferts Voxnode pas encore mesurés (HEAD sur l'enregistrement) ; la page rappelle
     * cette route jusqu'à ce qu'il n'en reste plus. Les deux semaines comparées sont toujours couvertes.
     */
    public function measure(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $from = $from->min(now(self::TZ)->subDays(13)->startOfDay());

        $pending = fn () => $this->transfers($from, $to)
            ->where(fn ($q) => $q->whereNull('platform')->orWhere('platform', '!=', 'selfhosted'))
            ->whereNull('transfer_real_duration')
            ->where('record_transfer', '!=', '');

        $batch = $pending()->limit(self::MEASURE_BATCH)->pluck('record_transfer', 'id');
        if ($batch->isNotEmpty()) {
            $sizes = [];
            foreach ($batch->chunk(150, true) as $chunk) {
                $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn ($url, $id) => $pool->as((string) $id)->timeout(10)->head($url))->all());
                foreach ($responses as $id => $response) {
                    $length = $response instanceof Throwable || !$response->successful() ? null : (int) $response->header('Content-Length');
                    $sizes[$id] = $length ? min(self::UNREADABLE - 1, (int) round($length / self::MP3_BYTES_PER_SECOND)) : self::UNREADABLE;
                }
            }
            foreach ($sizes as $id => $seconds) {
                DB::table('calls')->where('id', $id)->update(['transfer_real_duration' => $seconds]);
            }
        }

        return response()->json(['measured' => $batch->count(), 'remaining' => $pending()->count()]);
    }

    /**
     * Une ligne par marque (nom + numéro de transfert) : transferts, durée réelle, transferts courts,
     * enregistrements manquants, rappels dans l'heure, évolution sur deux semaines.
     */
    public function data(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->period($request);
        $weekStart = now(self::TZ)->subDays(6)->startOfDay();
        $prevStart = now(self::TZ)->subDays(13)->startOfDay();
        $rangeStart = $from->min($prevStart);

        $rows = $this->transfers($rangeStart, $to)
            ->selectRaw("id, UNIX_TIMESTAMP(called_at) AS ts, caller, total_duration, duration_transfer, transfer_real_duration,
                platform, record_transfer, brand_name, brand_phonenumber,
                EXISTS (SELECT 1 FROM calls c2 WHERE c2.caller = calls.caller AND c2.caller <> ''
                    AND c2.called_at > calls.called_at
                    AND c2.called_at <= calls.called_at + INTERVAL (calls.total_duration + 3600) SECOND) AS called_back")
            ->orderBy('id')
            ->get();

        $brands = [];
        $fromTs = $from->getTimestamp();
        foreach ($rows as $r) {
            $key = $r->brand_name . '|' . substr(preg_replace('/\D/', '', $r->brand_phonenumber), -9);
            $b = &$brands[$key];
            $b ??= ['name' => $r->brand_name, 'phonenumber' => $r->brand_phonenumber, 'durations' => [], 'transfers' => 0, 'no_recording' => 0, 'called_back' => 0, 'short' => [], 'week' => [0, 0], 'prev' => [0, 0], 'last_at' => 0];
            $real = $this->realDuration($r);

            // Deux semaines comparées : % de transferts courts parmi les transferts mesurés
            if ($real !== null) {
                $isShort = $real < self::SHORT_SECONDS;
                if ($r->ts >= $weekStart->getTimestamp()) {
                    $b['week'][0]++;
                    $b['week'][1] += $isShort;
                } elseif ($r->ts >= $prevStart->getTimestamp()) {
                    $b['prev'][0]++;
                    $b['prev'][1] += $isShort;
                }
            }

            if ($r->ts < $fromTs) {
                unset($b);
                continue;
            }
            $b['transfers']++;
            $b['last_at'] = max($b['last_at'], (int) $r->ts);
            $b['called_back'] += (int) $r->called_back;
            if ($real === null) {
                $b['no_recording']++;
            } else {
                $b['durations'][] = $real;
                if ($real < self::SHORT_SECONDS) {
                    $b['short'][] = $r;
                }
            }
            unset($b);
        }

        $checked = DB::table('brands')->whereNotNull('transfer_checked_at')->get(['name', 'phonenumber', 'transfer_checked_at'])
            ->mapWithKeys(fn ($b) => [$b->name . '|' . substr(preg_replace('/\D/', '', (string) $b->phonenumber), -9) => $b->transfer_checked_at]);

        $result = [];
        foreach ($brands as $key => $b) {
            if (!$b['transfers']) {
                continue;
            }
            $measured = count($b['durations']);
            $result[] = [
                'key' => $key,
                'name' => $b['name'],
                'phonenumber' => $b['phonenumber'],
                'transfers' => $b['transfers'],
                'measured' => $measured,
                'median' => $this->median($b['durations']),
                'short' => count($b['short']),
                'short_pct' => $measured ? round(count($b['short']) / $measured * 100, 1) : null,
                'no_recording_pct' => round($b['no_recording'] / $b['transfers'] * 100, 1),
                'called_back_pct' => round($b['called_back'] / $b['transfers'] * 100, 1),
                'week_short_pct' => $b['week'][0] ? round($b['week'][1] / $b['week'][0] * 100, 1) : null,
                'week_measured' => $b['week'][0],
                'prev_short_pct' => $b['prev'][0] ? round($b['prev'][1] / $b['prev'][0] * 100, 1) : null,
                'prev_measured' => $b['prev'][0],
                'last_at' => $b['last_at'],
                'checked_at' => $checked[$key] ?? null,
            ];
        }

        return response()->json(['days' => $days, 'short_seconds' => self::SHORT_SECONDS, 'brands' => $result]);
    }

    /**
     * Les transferts les plus courts d'une marque sur la période, pour les écouter.
     */
    public function shortest(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string', 'phonenumber' => 'required|string']);
        [$from, $to] = $this->period($request);
        $digits = substr(preg_replace('/\D/', '', $validated['phonenumber']), -9);

        $rows = $this->transfers($from, $to)
            ->where('brand_name', $validated['name'])
            ->whereRaw('RIGHT(brand_phonenumber, 9) = ?', [$digits])
            ->get(['id', 'called_at', 'caller', 'agent_name', 'duration_transfer', 'transfer_real_duration', 'platform', 'record_transfer'])
            ->map(fn ($r) => [...(array) $r, 'real' => $this->realDuration($r)])
            ->filter(fn ($r) => $r['real'] !== null)
            ->sortBy('real')
            ->take(10)
            ->values();

        return response()->json($rows);
    }

    /**
     * Marque vérifiée (ou plus vérifiée) : date enregistrée sur la marque.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string', 'phonenumber' => 'required|string', 'checked' => 'required|boolean']);
        $digits = substr(preg_replace('/\D/', '', $validated['phonenumber']), -9);
        $updated = DB::table('brands')
            ->where('name', $validated['name'])
            ->whereRaw('RIGHT(phonenumber, 9) = ?', [$digits])
            ->update(['transfer_checked_at' => $validated['checked'] ? now() : null]);
        abort_unless($updated, 404, 'Marque introuvable dans la liste des marques (nom et numéro).');

        return response()->json(['checked_at' => $validated['checked'] ? now()->toIso8601String() : null]);
    }

    /** Durée réelle de la mise en relation, null si inconnue (pas d'enregistrement, illisible ou pas encore mesurée). */
    private function realDuration(object $r): ?int
    {
        if ($r->platform === 'selfhosted') {
            return (int) $r->duration_transfer;
        }
        $real = $r->transfer_real_duration;

        return $real === null || (int) $real === self::UNREADABLE ? null : (int) $real;
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
