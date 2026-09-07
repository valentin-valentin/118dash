<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Analyse des appels rejetés (disabled_calls).
 *
 * Règles communes avec le dashboard :
 * - retries opérateurs dédupliqués sur le couple from/to (fenêtre glissante de 5 mn)
 * - seuls les appels entre 8h et 20h (Europe/Paris) hors dimanche sont comptés
 */
class RejectedCallAnalyzer
{
    // Fenêtre de déduplication des retries opérateurs (secondes)
    public const RETRY_GAP = 300;

    // Raisons possibles, dans l'ordre d'affichage
    public const REASONS = [
        'expired_lt_5',
        'expired_5_10',
        'expired_10_15',
        'expired_15_20',
        'expired_20_30',
        'expired_30_45',
        'expired_45_60',
        'expired_1h_2h',
        'expired_2h_4h',
        'expired_4h_8h',
        'expired_gt_8h',
        'not_assigned_today',
        'active_assignment',
    ];

    /**
     * Appels rejetés dédupliqués sur [start, end] (bornes en Europe/Paris).
     * Chaque entrée : id, from, to, phonenumber_id, called_at (Carbon UTC),
     * paris_at (Carbon Europe/Paris), retries (nb d'occurrences supprimées).
     */
    public function dedupedCalls(Request $request, Carbon $start, Carbon $end): array
    {
        $startUtc = $start->copy()->utc();

        $query = DB::table('disabled_calls')
            // marge avant le début pour détecter les retries qui chevauchent la borne
            ->whereBetween('called_at', [$startUtc->copy()->subMinutes(10), $end->copy()->utc()]);

        // Seuls les filtres portés par le phonenumber sont applicables aux rejetés
        foreach (['provider_id', 'company_id', 'source_id'] as $field) {
            if ($request->filled($field)) {
                $ids = array_map('intval', $this->parseMultiSelect($request->input($field)));
                $query->whereIn('phonenumber_id', function ($q) use ($field, $ids) {
                    $q->select('id')->from('phonenumbers')->whereIn($field, $ids);
                });
            }
        }

        $rows = $query->orderBy('called_at')->orderBy('id')
            ->get(['id', 'from', 'to', 'phonenumber_id', 'called_at']);

        $lastSeen = [];
        $kept = [];
        $keptIndexByPair = [];

        foreach ($rows as $row) {
            $calledAt = Carbon::parse($row->called_at, 'UTC');
            $key = $row->from . '|' . $row->to;

            $isRetry = isset($lastSeen[$key]) && $lastSeen[$key]->diffInSeconds($calledAt) <= self::RETRY_GAP;
            $lastSeen[$key] = $calledAt;

            if ($isRetry) {
                if (isset($keptIndexByPair[$key])) {
                    $kept[$keptIndexByPair[$key]]['retries']++;
                }
                continue;
            }

            // Nouvel appel : s'il est hors périmètre, ses retries ne comptent pour personne
            unset($keptIndexByPair[$key]);

            if ($calledAt->lt($startUtc)) {
                continue;
            }

            $parisAt = $calledAt->copy()->setTimezone('Europe/Paris');
            if ($parisAt->hour < 8 || $parisAt->hour >= 20 || $parisAt->isSunday()) {
                continue;
            }

            $kept[] = [
                'id' => $row->id,
                'from' => $row->from,
                'to' => $row->to,
                'phonenumber_id' => $row->phonenumber_id,
                'called_at' => $calledAt,
                'paris_at' => $parisAt,
                'retries' => 0,
            ];
            $keptIndexByPair[$key] = count($kept) - 1;
        }

        return $kept;
    }

    /**
     * Comptes par jour (clé Y-m-d en Europe/Paris).
     */
    public function countsByDay(array $calls): array
    {
        $byDay = [];
        foreach ($calls as $call) {
            $day = $call['paris_at']->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        return $byDay;
    }

    /**
     * Ajoute à chaque appel la raison du rejet (reason) et le délai en minutes
     * depuis l'expiration réelle du numéro (delay_minutes, null si non applicable),
     * en s'appuyant sur assignment_history.
     */
    public function withReasons(array $calls): array
    {
        if (empty($calls)) {
            return [];
        }

        $phoneIds = array_values(array_unique(array_filter(array_column($calls, 'phonenumber_id'))));
        $timestamps = array_column($calls, 'called_at');
        $minAt = min($timestamps)->copy()->subDays(7);
        $maxAt = max($timestamps);

        // Toutes les assignations pouvant concerner ces appels. La marge de 7 jours
        // suffit : une fin plus ancienne signifie de toute façon "pas assigné aujourd'hui".
        $assignments = DB::table('assignment_history')
            ->whereIn('phonenumber_id', $phoneIds)
            ->where('start_at', '<=', $maxAt)
            ->where(function ($q) use ($minAt) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', $minAt);
            })
            ->orderBy('start_at')
            ->get(['phonenumber_id', 'start_at', 'end_at'])
            ->groupBy('phonenumber_id');

        foreach ($calls as &$call) {
            [$reason, $delay] = $this->classify($call, $assignments->get($call['phonenumber_id']) ?? collect());
            $call['reason'] = $reason;
            $call['delay_minutes'] = $delay;
        }
        unset($call);

        return $calls;
    }

    /**
     * Classe un rejet :
     * - active_assignment : le numéro était censé être actif au moment de l'appel (anomalie)
     * - not_assigned_today : aucune assignation terminée le même jour (Europe/Paris) avant l'appel
     * - expired_* : délai entre la fin réelle de la dernière assignation et l'appel
     */
    private function classify(array $call, $assignments): array
    {
        $t = $call['called_at'];
        $lastEnd = null;

        foreach ($assignments as $a) {
            $startAt = Carbon::parse($a->start_at, 'UTC');
            if ($startAt->lte($t) && ($a->end_at === null || Carbon::parse($a->end_at, 'UTC')->gt($t))) {
                return ['active_assignment', null];
            }

            if ($a->end_at !== null) {
                $endAt = Carbon::parse($a->end_at, 'UTC');
                if ($endAt->lte($t) && ($lastEnd === null || $endAt->gt($lastEnd))) {
                    $lastEnd = $endAt;
                }
            }
        }

        if ($lastEnd === null || !$lastEnd->copy()->setTimezone('Europe/Paris')->isSameDay($call['paris_at'])) {
            return ['not_assigned_today', null];
        }

        $minutes = $lastEnd->diffInSeconds($t) / 60;
        $delay = (int) floor($minutes);

        return [match (true) {
            $minutes < 5 => 'expired_lt_5',
            $minutes < 10 => 'expired_5_10',
            $minutes < 15 => 'expired_10_15',
            $minutes < 20 => 'expired_15_20',
            $minutes < 30 => 'expired_20_30',
            $minutes < 45 => 'expired_30_45',
            $minutes < 60 => 'expired_45_60',
            $minutes < 120 => 'expired_1h_2h',
            $minutes < 240 => 'expired_2h_4h',
            $minutes < 480 => 'expired_4h_8h',
            default => 'expired_gt_8h',
        }, $delay];
    }

    private function parseMultiSelect($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            return array_filter(explode(',', $value), fn ($v) => $v !== '');
        }

        return [];
    }
}
