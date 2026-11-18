<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard (/dashboard) : appels de 10 s et plus seulement, sans les montants payout / reverse.
 *   Jour par jour : appels, qualité audio, attente avant décroché, perdus, durée affichée moyenne et revenu par appel
 *   (durée affichée moyenne × 0,45 € la minute), comparés à J-7 et, pour le total, au mois précédent à jour égal.
 *   Répartition par marque : nombre d'appels.
 * Le dashboard complet (montants, companies, heure par heure) reste sur /dashboard-full (DashboardController).
 */
class OverviewController extends Controller
{
    // Revenu : 0,45 € par minute de durée affichée
    private const RATE_PER_MINUTE = 0.45;
    // Durée affichée = total_duration × multiplicateur du mois de l'appel (heure de Paris) ; les mois absents : DEFAULT_MULTIPLIER
    private const MULTIPLIERS = ['2026-11' => 0.75, '2026-10' => 0.85, '2026-09' => 0.80];
    private const DEFAULT_MULTIPLIER = 0.8;
    private const MIN_DURATION = 10;
    private const TZ = 'Europe/Paris';

    public function index(): Response
    {
        return Inertia::render('Overview');
    }

    public function daily(Request $request): JsonResponse
    {
        [$start, $end] = $this->month($request);
        // Carbon (modifiable) : now() de l'application renvoie une date immuable
        $now = Carbon::now(self::TZ);
        $last = $end->lessThan($now) ? $end : $now;

        $items = [];
        for ($day = $start->copy(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            // Aujourd'hui s'arrête à maintenant, et J-7 à la même heure
            $to = $day->isSameDay($now) ? $now->copy() : $day->copy()->endOfDay();
            $current = $this->aggregate($request, $day->copy()->startOfDay(), $to);
            $previous = $this->aggregate($request, $day->copy()->subDays(7)->startOfDay(), $to->copy()->subDays(7));
            $comparison = $day->copy()->subDays(7);
            $items[] = [
                'date' => $day->toDateString(),
                'date_label' => ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$day->dayOfWeek].' '.$day->format('d/m'),
                // J-7 tombe le même jour de la semaine que la ligne : la date suffit
                'comparison_label' => 'vs. '.$comparison->format('d/m'),
            ] + $this->compare($current, $previous);
        }

        // Total du mois, comparé au mois précédent sur la même durée (jusqu'au même jour, à la même heure)
        $totals = $this->compare(
            $this->aggregate($request, $start, $last),
            $this->aggregate($request, $start->copy()->subMonthNoOverflow(), $last->copy()->subMonthNoOverflow()),
        );

        return response()->json(['items' => array_reverse($items), 'totals' => $totals]);
    }

    /** Répartition par marque : nombre d'appels de 10 s et plus du mois (marques d'au moins 5 appels). */
    public function brands(Request $request): JsonResponse
    {
        [$start, $end] = $this->month($request);
        $base = fn () => $this->filtered($request)->whereBetween('called_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->whereNotNull('brand_name')->where('brand_name', '!=', '');

        $brands = $base()->selectRaw('brand_name AS name, COUNT(*) AS calls')
            ->groupBy('brand_name')->havingRaw('COUNT(*) >= 5')->orderByDesc('calls')->get()
            ->map(fn ($b) => ['name' => $b->name, 'calls' => (int) $b->calls]);

        return response()->json(['brands' => $brands, 'total_count' => (int) $base()->distinct()->count('brand_name')]);
    }

    // ─── Calculs ──────────────────────────────────────────────────────────────

    /** Mois choisi (AAAA-MM), en heure de Paris ; le mois courant par défaut. */
    private function month(Request $request): array
    {
        $start = preg_match('/^(\d{4})-(\d{2})$/', (string) $request->input('month'), $m)
            ? Carbon::createFromDate((int) $m[1], (int) $m[2], 1, self::TZ)->startOfMonth()
            : Carbon::now(self::TZ)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * Sommes d'une période [$from, $to] (heure de Paris) :
     *   attente = appels décrochés : total_duration - duration_agent - duration_transfer (au moins 0), comme la page Attente et pertes ;
     *   perdus = aucun agent n'a décroché ;
     *   durée affichée = total_duration × multiplicateur du mois de l'appel (MULTIPLIERS, sinon DEFAULT_MULTIPLIER).
     */
    private function aggregate(Request $request, CarbonInterface $from, CarbonInterface $to): object
    {
        $displayed = 'ROUND(total_duration * '.$this->multiplierSql().')';

        return $this->filtered($request)
            ->whereBetween('called_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->selectRaw("
                COUNT(*) AS calls,
                COUNT(audio_quality) AS quality_calls,
                COALESCE(SUM(audio_quality), 0) AS quality_sum,
                COALESCE(SUM(audio_quality < 3.1), 0) AS quality_degraded,
                COALESCE(SUM(COALESCE(duration_agent, 0) = 0), 0) AS lost_calls,
                COUNT(CASE WHEN duration_agent > 0 THEN 1 END) AS answered,
                COALESCE(SUM(CASE WHEN duration_agent > 0 THEN GREATEST(0, total_duration - duration_agent - COALESCE(duration_transfer, 0)) END), 0) AS wait_sum,
                COALESCE(SUM($displayed), 0) AS displayed_sum
            ")
            ->toBase()->first();
    }

    /** Valeurs d'une période et de la période comparée, avec les écarts en % (appels, attente, durée affichée, revenu). */
    private function compare(object $cur, object $prev): array
    {
        $values = fn ($a) => [
            'calls' => (int) $a->calls,
            'avg_wait' => $a->answered ? (int) round($a->wait_sum / $a->answered) : null,
            'displayed_avg' => $a->calls ? (int) round($a->displayed_sum / $a->calls) : null,
            'revenue' => $a->calls ? round($a->displayed_sum / $a->calls / 60 * self::RATE_PER_MINUTE, 2) : null,
        ];
        $now = $values($cur);
        $before = $values($prev);
        $variation = fn ($a, $b) => $a !== null && $b ? round(($a - $b) / $b * 100, 1) : null;

        $row = $now + [
            'quality_calls' => (int) $cur->quality_calls,
            'quality_mos' => $cur->quality_calls ? round($cur->quality_sum / $cur->quality_calls, 2) : null,
            'quality_degraded' => (int) $cur->quality_degraded,
            'lost_calls' => (int) $cur->lost_calls,
            'lost_pct' => $cur->calls ? round($cur->lost_calls / $cur->calls * 100, 1) : null,
        ];
        foreach ($before as $key => $value) {
            $row["prev_$key"] = $value;
            $row["{$key}_var"] = $variation($now[$key], $value);
        }

        return $row;
    }

    /** Appels de 10 s et plus, avec les filtres de la page (mêmes règles que le dashboard complet). */
    private function filtered(Request $request)
    {
        $list = fn ($key) => array_values(array_filter(array_map('trim', explode(',', (string) $request->input($key))), fn ($v) => $v !== ''));
        $query = Call::query()->where('total_duration', '>=', self::MIN_DURATION);

        if ($request->filled('brand_name')) {
            $query->whereIn('brand_name', $list('brand_name'));
        }
        if ($request->filled('agent_name')) {
            $query->whereIn('agent_name', $list('agent_name'));
        }
        if ($request->filled('callcenter_id')) {
            $query->whereIn('callcenter_id', array_map('intval', $list('callcenter_id')));
        }
        if ($request->filled('carrier')) {
            $query->whereIn('carrier', $list('carrier'));
        }
        // Plateforme : selfhosted = notre serveur ; NULL ou voxnode = Voxnode
        if ($request->filled('platform')) {
            $request->platform === 'selfhosted'
                ? $query->where('platform', 'selfhosted')
                : $query->where(fn ($q) => $q->whereNull('platform')->orWhere('platform', '!=', 'selfhosted'));
        }
        if ($request->filled('provider_id')) {
            $providers = array_map('intval', $list('provider_id'));
            $query->whereHas('phonenumber', fn ($q) => $q->withTrashed()->whereIn('provider_id', $providers));
        }
        if ($request->filled('company_id')) {
            $companies = array_map('intval', $list('company_id'));
            $query->whereHas('phonenumber', fn ($q) => $q->withTrashed()->whereIn('company_id', $companies));
        }
        if ($request->filled('source_id')) {
            $sources = array_map('intval', $list('source_id'));
            $query->where(fn ($q) => $q->whereIn('source_id', $sources)
                ->orWhere(fn ($sq) => $sq->whereNull('source_id')
                    ->whereHas('phonenumber', fn ($psq) => $psq->withTrashed()->whereIn('source_id', $sources))));
        }

        return $query;
    }

    /** Multiplicateur de chaque appel selon son mois (bornes du mois de Paris en UTC, comme les autres requêtes). */
    private function multiplierSql(): string
    {
        $cases = '';
        foreach (self::MULTIPLIERS as $month => $multiplier) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01', self::TZ)->startOfMonth();
            $cases .= sprintf(" WHEN called_at >= '%s' AND called_at < '%s' THEN %s", $start->copy()->utc()->toDateTimeString(), $start->copy()->addMonthNoOverflow()->utc()->toDateTimeString(), $multiplier);
        }

        return $cases ? 'CASE'.$cases.' ELSE '.self::DEFAULT_MULTIPLIER.' END' : (string) self::DEFAULT_MULTIPLIER;
    }
}
