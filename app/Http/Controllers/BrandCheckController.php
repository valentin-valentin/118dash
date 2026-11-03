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
 * Vérifier les transferts (page Marques) : marques dont les transferts durent anormalement peu,
 * signe d'un numéro qui ne marche plus (non attribué, fermé, répondeur).
 *
 * Une seule mesure : la médiane de la durée réelle des transferts de la marque sur 7 ou 30 jours (au moins N mesurés, 3 par défaut).
 * Seules les marques sous le seuil choisi (60 s par défaut) sont affichées, la plus basse en premier.
 * Une marque marquée vérifiée ne compte plus que ses transferts postérieurs à la vérification.
 *
 * Durée réelle d'un transfert (calls.transfer_real_duration) :
 *   - Voxnode : duration_transfer comprend une attente avant la mise en relation ; la vraie durée est celle de
 *     l'enregistrement record_transfer, un mp3 à débit constant de 32 kbit/s : durée = Content-Length × 8 / 32000.
 *     Mesurée par une requête HEAD, à l'ouverture de la page, pour les transferts de la période pas encore mesurés.
 *     Pas d'enregistrement (adresse vide, ou fichier absent : 403/404) = aucun son de mise en relation = 0 s.
 *     65535 = fichier présent mais taille inconnue (ignoré, pas remesuré).
 *   - Notre serveur : duration_transfer, qui part du décroché de la marque.
 * Le service interne (+33184131572) n'est pas une marque : exclu. Jours en heure de Paris.
 */
class BrandCheckController extends Controller
{
    private const TZ = 'Europe/Paris';

    private const INTERNAL_SERVICE = '184131572';

    private const UNREADABLE = 65535;

    private const MP3_BYTES_PER_SECOND = 32000 / 8;

    // Nombre minimal de transferts mesurés pour qu'une marque soit listée (réglable sur la page)
    private const MIN_MEASURED = 3;

    private const LAST_TRANSFERS = 10;

    private const MEASURE_BATCH = 300;

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
        $days = (int) ($request->validate(['days' => 'nullable|integer|in:7,30,90'])['days'] ?? 30);
        $to = now(self::TZ)->endOfDay();

        return [$to->copy()->subDays($days - 1)->startOfDay(), $to, $days];
    }

    /**
     * Mesure un lot de transferts Voxnode pas encore mesurés (HEAD sur l'enregistrement) ; la page rappelle
     * cette route jusqu'à ce qu'il n'en reste plus.
     */
    public function measure(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $pending = fn () => $this->transfers($from, $to)
            ->where(fn ($q) => $q->whereNull('platform')->orWhere('platform', '!=', 'selfhosted'))
            ->whereNull('transfer_real_duration')
            ->where('record_transfer', '!=', '');

        // Premier appel de la page : seulement le nombre à mesurer, pour afficher la progression tout de suite
        if ($request->boolean('count_only')) {
            return response()->json(['measured' => 0, 'remaining' => $pending()->count()]);
        }

        $batch = $pending()->limit(self::MEASURE_BATCH)->pluck('record_transfer', 'id');
        if ($batch->isNotEmpty()) {
            $sizes = [];
            foreach ($batch->chunk(150, true) as $chunk) {
                $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn ($url, $id) => $pool->as((string) $id)->timeout(10)->head($url))->all());
                foreach ($responses as $id => $response) {
                    if ($response instanceof Throwable || $response->serverError()) {
                        continue; // erreur passagère (délai, serveur) : remesuré à la prochaine ouverture
                    }
                    if (in_array($response->status(), [403, 404], true)) {
                        $sizes[$id] = 0; // fichier jamais créé : aucun son de mise en relation
                    } elseif ($response->successful() && $response->hasHeader('Content-Length')) {
                        $sizes[$id] = min(self::UNREADABLE - 1, (int) round((int) $response->header('Content-Length') / self::MP3_BYTES_PER_SECOND));
                    } else {
                        $sizes[$id] = self::UNREADABLE;
                    }
                }
            }
            foreach ($sizes as $id => $seconds) {
                DB::table('calls')->where('id', $id)->update(['transfer_real_duration' => $seconds]);
            }
        }

        return response()->json(['measured' => $batch->count(), 'remaining' => $pending()->count()]);
    }

    /**
     * Marques dont la médiane de durée réelle est sous le seuil.
     */
    public function data(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->period($request);
        $params = $request->validate([
            'threshold' => 'nullable|integer|min:1|max:3600',
            'min' => 'nullable|integer|min:1|max:100',
            'hangup' => 'nullable|in:caller,callee,undefined',
        ]);
        $threshold = (int) ($params['threshold'] ?? 60);
        $minMeasured = (int) ($params['min'] ?? self::MIN_MEASURED);

        // Fiches des marques (nom + 9 derniers chiffres du numéro) : lien de modification et date de vérification
        $known = DB::table('brands')->whereNotNull('phonenumber')->where('phonenumber', '!=', '')
            ->get(['id', 'name', 'phonenumber', 'enabled', 'transfer_checked_at']);
        $byKey = $known->keyBy(fn ($b) => $b->name . '|' . $this->lastDigits($b->phonenumber));

        $rows = $this->transfers($from, $to)
            ->when($params['hangup'] ?? null, fn ($q, $hangup) => $q->where('who_hangup', $hangup))
            ->selectRaw('id, called_at, UNIX_TIMESTAMP(called_at) AS ts, caller, agent_name, duration_agent, who_hangup, duration_transfer, transfer_real_duration, platform, record_transfer, brand_name, brand_phonenumber')
            ->orderByDesc('called_at')
            ->get();

        $brands = [];
        foreach ($rows as $r) {
            $real = $this->realDuration($r);
            if ($real === null) {
                continue;
            }
            $key = $r->brand_name . '|' . $this->lastDigits($r->brand_phonenumber);
            // Marque vérifiée : seuls les transferts postérieurs à la vérification comptent
            $checkedAt = $byKey[$key]->transfer_checked_at ?? null;
            if ($checkedAt && $r->ts <= strtotime($checkedAt . ' UTC')) {
                continue;
            }
            $brands[$key] ??= ['name' => $r->brand_name, 'phonenumber' => $r->brand_phonenumber, 'durations' => [], 'last' => []];
            $brands[$key]['durations'][] = $real;
            if (count($brands[$key]['last']) < self::LAST_TRANSFERS) {
                $brands[$key]['last'][] = [
                    'id' => $r->id,
                    'called_at' => $r->called_at,
                    'seconds' => $real,
                    // Fichier Voxnode absent (mesuré 0 s) : rien à écouter
                    'record_transfer' => $r->record_transfer && !($real === 0 && $r->platform !== 'selfhosted') ? $r->record_transfer : null,
                    'caller' => $r->caller,
                    'agent_name' => $r->agent_name,
                    'duration_agent' => (int) $r->duration_agent,
                    'platform' => $r->platform === 'selfhosted' ? 'selfhosted' : 'voxnode',
                    'who_hangup' => $r->who_hangup ?: null,
                ];
            }
        }

        $result = [];
        foreach ($brands as $key => $b) {
            $median = $this->median($b['durations']);
            if (count($b['durations']) < $minMeasured || $median >= $threshold) {
                continue;
            }
            $result[] = [
                'key' => $key,
                'name' => $b['name'],
                'phonenumber' => $b['phonenumber'],
                'median' => $median,
                'measured' => count($b['durations']),
                'last' => $b['last'],
                'brand_id' => $byKey[$key]->id ?? null,
                'checked_at' => $byKey[$key]->transfer_checked_at ?? null,
            ];
        }
        usort($result, fn ($a, $b) => $a['median'] <=> $b['median']);

        return response()->json(['days' => $days, 'threshold' => $threshold, 'min' => $minMeasured, 'brands' => $result]);
    }

    private function lastDigits(?string $number): string
    {
        return substr(preg_replace('/\D/', '', (string) $number), -9);
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
        // Transfert Voxnode sans enregistrement : aucun son de mise en relation, compté 0 s
        if (($r->record_transfer ?? '') === '') {
            return 0;
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
