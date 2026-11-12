<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Appels à vérifier : les appels à réécouter, un appel par ligne avec tous ses cas.
 *   reported  signalé par l'agent (motif libre)
 *   hangup    raccroché par l'agent (motif obligatoire)
 *   blacklist appelant sur liste noire
 *   against   transfert contre le choix suggéré (colonne calls.transfer_against_advice, voxnode118) : Backup suggéré,
 *             l'agent a choisi la marque
 *   recall    rappel transféré vers la marque (calls.transfer_recall_to_brand, voxnode118) : le client avait déjà appelé
 *             pour cette marque dans les 48 h, Backup était proposé d'abord, l'agent a transféré vers la marque
 *   audio     audio mauvais : note MOS < 3,6 (même seuil que l'API du Superviseur)
 */
class CallReviewController extends Controller
{
    private const CASES = ['reported', 'hangup', 'blacklist', 'against', 'recall', 'audio'];

    // Cas portés par une colonne que voxnode118 ajoute : absents tant que sa migration n'est pas passée
    private const OPTIONAL_COLUMNS = ['against' => 'transfer_against_advice', 'recall' => 'transfer_recall_to_brand'];

    private const AUDIO_BAD = 3.6;

    private const PER_PAGE = 50;

    public function index(): Response
    {
        return Inertia::render('CallReview');
    }

    public function data(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request->input('period', 'today'));
        $agent = $request->input('agent') ?: null;
        $cases = array_values(array_intersect(self::CASES, explode(',', (string) $request->input('cases', implode(',', self::CASES)))));
        $available = $this->availableCases();
        $cases = array_values(array_intersect($cases, $available));

        $period = fn () => Call::excludeTests(DB::table('calls'))
            ->where('calls.called_at', '>=', $from)->where('calls.called_at', '<', $to);
        $base = fn () => $period()->when($agent, fn ($q) => $q->where('calls.agent_name', $agent));

        // Nombre de chaque cas sur la période (et l'agent choisi), quels que soient les cas cochés
        $counts = $base()->selectRaw(implode(', ', array_map(
            fn ($c) => "SUM(CASE WHEN {$this->condition($c)} THEN 1 ELSE 0 END) AS `{$c}`",
            $available,
        )))->first();

        $rows = $cases
            ? $base()->where(fn (Builder $q) => array_map(fn ($c) => $q->orWhereRaw($this->condition($c)), $cases))
                ->leftJoin('blacklist', 'blacklist.id', '=', 'calls.blacklist_id')
                ->orderByDesc('calls.called_at')
                ->paginate(self::PER_PAGE, $this->columns($available), 'page', max(1, (int) $request->input('page', 1)))
            : null;

        $backup = $this->backupNumber();

        // Par agent (tous les agents de la période, quel que soit l'agent choisi) : nombre de chaque cas, appels à vérifier
        // (au moins un des cas cochés : les lignes qu'il aurait dans la liste) et appels décrochés pour situer
        $anyChecked = $cases ? '('.implode(' OR ', array_map(fn ($c) => $this->condition($c), $cases)).')' : '0';
        $byAgent = $period()->whereNotNull('calls.agent_name')
            ->where(fn (Builder $q) => array_map(fn ($c) => $q->orWhereRaw($this->condition($c)), $available))
            ->groupBy('calls.agent_name')
            ->selectRaw('calls.agent_name AS agent, '.implode(', ', [
                ...array_map(fn ($c) => "SUM(CASE WHEN {$this->condition($c)} THEN 1 ELSE 0 END) AS `{$c}`", $available),
                "SUM(CASE WHEN {$anyChecked} THEN 1 ELSE 0 END) AS `review`",
            ]))->get();
        $answered = $byAgent->isEmpty() ? collect() : $period()->whereIn('calls.agent_name', $byAgent->pluck('agent'))
            ->whereNotNull('calls.phone_answered_at')->groupBy('calls.agent_name')->pluck(DB::raw('COUNT(*)'), 'calls.agent_name');
        $ranking = $byAgent->map(fn ($r) => [
            'agent' => $r->agent,
            'counts' => collect(self::CASES)->mapWithKeys(fn ($c) => [$c => in_array($c, $available, true) ? (int) $r->{$c} : null]),
            'review' => (int) $r->review,
            'answered' => (int) ($answered[$r->agent] ?? 0),
        ])->filter(fn ($r) => $r['review'] > 0)->sortBy(fn ($r) => [-$r['review'], $r['agent']])->values();

        return response()->json([
            'ranking' => $ranking,
            'counts' => collect(self::CASES)->mapWithKeys(fn ($c) => [$c => in_array($c, $available, true) ? (int) ($counts->{$c} ?? 0) : null]),
            // Agents proposés dans le filtre : tous ceux de la période, quel que soit l'agent choisi
            'agents' => $period()->where(fn (Builder $q) => array_map(fn ($c) => $q->orWhereRaw($this->condition($c)), $available))
                ->whereNotNull('calls.agent_name')->distinct()->orderBy('calls.agent_name')->pluck('calls.agent_name'),
            'items' => collect($rows?->items() ?? [])->map(fn ($r) => $this->row($r, $available, $backup)),
            'page' => $rows?->currentPage() ?? 1,
            'lastPage' => $rows?->lastPage() ?? 1,
            'total' => $rows?->total() ?? 0,
        ]);
    }

    /** Appels signalés par l'agent aujourd'hui (pastille du menu) : même compte que la case de la page sur « Aujourd'hui ». */
    public function reportedToday(): JsonResponse
    {
        [$from, $to] = $this->period('today');

        return response()->json(['count' => Call::excludeTests(DB::table('calls'))
            ->where('called_at', '>=', $from)->where('called_at', '<', $to)
            ->whereRaw($this->condition('reported'))->count()]);
    }

    /**
     * Détail d'un appel pour la fenêtre de vérification : ses cas, son déroulé, la qualité réseau (audio mauvais),
     * l'appelant (liste noire, ses autres appels avec leurs cas) et, pour l'audio, les autres appels de l'agent ce jour-là.
     */
    public function detail(int $id): JsonResponse
    {
        $available = $this->availableCases();
        $backup = $this->backupNumber();
        $select = fn () => Call::excludeTests(DB::table('calls'))->leftJoin('blacklist', 'blacklist.id', '=', 'calls.blacklist_id');

        $call = $select()->where('calls.id', $id)->first([...$this->columns($available), 'calls.total_duration', 'calls.who_hangup']);
        abort_unless($call, 404);
        $row = $this->row($call, $available, $backup);

        $history = $call->caller
            ? $select()->where('calls.caller', $call->caller)->where('calls.id', '!=', $id)->orderByDesc('calls.called_at')->limit(20)->get($this->columns($available))
                ->map(fn ($r) => $this->row($r, $available, $backup))
            : collect();

        // Audio mauvais : les autres appels de l'agent le même jour (jour de Paris), pour voir si c'est son réseau
        $agentDay = null;
        if ($call->agent_name && collect($row['cases'])->contains('key', 'audio')) {
            $day = Carbon::parse($call->called_at, 'UTC')->setTimezone('Europe/Paris')->startOfDay();
            $stats = Call::excludeTests(DB::table('calls'))->where('agent_name', $call->agent_name)
                ->where('called_at', '>=', $day->copy()->utc())->where('called_at', '<', $day->copy()->addDay()->utc())
                ->whereNotNull('audio_quality')
                ->selectRaw('COUNT(*) AS measured, SUM(audio_quality < ?) AS bad, AVG(audio_quality) AS mos', [self::AUDIO_BAD])->first();
            $agentDay = ['measured' => (int) $stats->measured, 'bad' => (int) $stats->bad, 'mos' => $stats->mos === null ? null : round((float) $stats->mos, 2)];
        }

        return response()->json([
            'call' => $row + [
                'duration_transfer' => $call->duration_transfer === null ? null : (int) $call->duration_transfer,
                'total_duration' => $call->total_duration === null ? null : (int) $call->total_duration,
                'who_hangup' => $call->who_hangup,
            ],
            'quality' => DB::table('call_quality')->where('call_id', $id)->first(['loss_pct', 'sent_loss_pct', 'jitter_median_ms', 'jitter_max_ms', 'rtt_avg_ms', 'rtt_max_ms']),
            'agentDay' => $agentDay,
            // Appelant sur liste noire, même si cet appel est passé avant son ajout
            'blacklist' => $this->blacklist($call->caller),
            'callerTotal' => $call->caller ? Call::excludeTests(DB::table('calls'))->where('caller', $call->caller)->count() : 0,
            'history' => $history,
        ]);
    }

    /** Entrée de liste noire de l'appelant ; date d'ajout en ISO (UTC), null si inconnue. */
    private function blacklist(?string $caller): ?array
    {
        $entry = $caller ? DB::table('blacklist')->where('phonenumber', $caller)->first(['note', 'source', 'created_at']) : null;

        return $entry ? [
            'note' => $entry->note,
            'source' => $entry->source,
            'created_at' => $entry->created_at ? Carbon::parse($entry->created_at, 'UTC')->toIso8601String() : null,
        ] : null;
    }

    private function columns(array $available): array
    {
        return [
            'calls.id', 'calls.called_at', 'calls.agent_name', 'calls.called', 'calls.caller', 'calls.brand_name', 'calls.brand_phonenumber',
            'calls.duration_agent', 'calls.duration_transfer', 'calls.record_agent', 'calls.record_transfer', 'calls.audio_quality',
            'calls.phone_reported_by_agent', 'calls.phone_reported_by_agent_reason', 'calls.phone_agent_hangup', 'calls.phone_agent_hangup_reason',
            'calls.blacklist_id', 'blacklist.note as blacklist_note',
            ...collect(self::OPTIONAL_COLUMNS)->only($available)->map(fn ($column) => "calls.{$column}")->values()->all(),
        ];
    }

    /** Un appel et ses cas, pour le tableau et la fenêtre. */
    private function row(object $r, array $available, ?string $backup): array
    {
        return [
            'id' => $r->id,
            'at' => Carbon::parse($r->called_at, 'UTC')->toIso8601String(),
            'agent' => $r->agent_name,
            'called' => $r->called,
            'caller' => $r->caller,
            'brand' => $r->brand_name,
            'duration_agent' => $r->duration_agent === null ? null : (int) $r->duration_agent,
            'record_agent' => $r->record_agent,
            'record_transfer' => $r->record_transfer,
            'cases' => array_values(array_filter([
                $r->phone_reported_by_agent ? ['key' => 'reported', 'detail' => $r->phone_reported_by_agent_reason] : null,
                $r->phone_agent_hangup ? ['key' => 'hangup', 'detail' => $r->phone_agent_hangup_reason] : null,
                $r->blacklist_id ? ['key' => 'blacklist', 'detail' => $r->blacklist_note] : null,
                in_array('against', $available, true) && $r->transfer_against_advice
                    // Toujours « Backup proposé, l'agent a choisi la marque » (seul cas marqué par voxnode118) ; renvoyé au service
                    // interne quand la marque n'était pas joignable par notre serveur (l'import met alors le numéro de BACKUP)
                    ? ['key' => 'against', 'toInternal' => $backup !== null && str_ends_with((string) $r->brand_phonenumber, $backup)]
                    : null,
                // Rappel (déjà appelé pour cette marque sous 48 h) transféré vers la marque au lieu de Backup
                in_array('recall', $available, true) && $r->transfer_recall_to_brand ? ['key' => 'recall'] : null,
                $r->audio_quality !== null && $r->audio_quality < self::AUDIO_BAD ? ['key' => 'audio', 'mos' => (float) $r->audio_quality] : null,
            ])),
        ];
    }

    private function condition(string $case): string
    {
        return match ($case) {
            'reported' => 'calls.phone_reported_by_agent = 1',
            'hangup' => 'calls.phone_agent_hangup = 1',
            'blacklist' => 'calls.blacklist_id IS NOT NULL',
            'against' => 'calls.transfer_against_advice = 1',
            'recall' => 'calls.transfer_recall_to_brand = 1',
            'audio' => 'calls.audio_quality < '.self::AUDIO_BAD,
        };
    }

    /** Période en jours de Paris, bornes en UTC (calls.called_at). */
    private function period(string $key): array
    {
        $today = now('Europe/Paris')->startOfDay();
        [$from, $to] = match ($key) {
            'yesterday' => [$today->copy()->subDay(), $today],
            'week' => [$today->copy()->subDays(6), $today->copy()->addDay()],
            'month' => [$today->copy()->subDays(29), $today->copy()->addDay()],
            default => [$today, $today->copy()->addDay()],
        };

        return [$from->utc(), $to->utc()];
    }

    /** Cas proposés : tous, sauf ceux dont la colonne (ajoutée par voxnode118) n'existe pas encore ; vérifié une fois par heure. */
    private function availableCases(): array
    {
        return array_values(array_filter(self::CASES, fn ($c) => !isset(self::OPTIONAL_COLUMNS[$c])
            || Cache::remember('calls:has-'.self::OPTIONAL_COLUMNS[$c], 3600, fn () => Schema::hasColumn('calls', self::OPTIONAL_COLUMNS[$c]))));
    }

    /** Numéro de la marque BACKUP, sans indicatif, pour reconnaître un transfert vers elle (fin du numéro). */
    private function backupNumber(): ?string
    {
        $number = DB::table('brands')->where('name', 'BACKUP')->value('phonenumber');

        return $number ? substr(preg_replace('/\D/', '', $number), -9) : null;
    }
}
