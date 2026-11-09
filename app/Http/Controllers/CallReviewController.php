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
 *   against   transfert contre le choix suggéré (colonne calls.transfer_against_advice, voxnode118)
 *   audio     audio mauvais : note MOS < 3,6 (même seuil que l'API du Superviseur)
 */
class CallReviewController extends Controller
{
    private const CASES = ['reported', 'hangup', 'blacklist', 'against', 'audio'];

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
        $against = $this->hasAgainstColumn();
        if (!$against) {
            $cases = array_values(array_diff($cases, ['against']));
        }

        $base = fn () => Call::excludeTests(DB::table('calls'))
            ->where('calls.called_at', '>=', $from)->where('calls.called_at', '<', $to)
            ->when($agent, fn ($q) => $q->where('calls.agent_name', $agent));

        // Nombre de chaque cas sur la période (et l'agent choisi), quels que soient les cas cochés
        $counts = $base()->selectRaw(implode(', ', array_map(
            fn ($c) => "SUM(CASE WHEN {$this->condition($c)} THEN 1 ELSE 0 END) AS `{$c}`",
            array_values(array_filter(self::CASES, fn ($c) => $c !== 'against' || $against)),
        )))->first();

        $rows = $cases
            ? $base()->where(fn (Builder $q) => array_map(fn ($c) => $q->orWhereRaw($this->condition($c)), $cases))
                ->leftJoin('blacklist', 'blacklist.id', '=', 'calls.blacklist_id')
                ->orderByDesc('calls.called_at')
                ->paginate(self::PER_PAGE, [
                    'calls.id', 'calls.called_at', 'calls.agent_name', 'calls.called', 'calls.caller', 'calls.brand_name', 'calls.brand_phonenumber',
                    'calls.duration_agent', 'calls.duration_transfer', 'calls.record_agent', 'calls.record_transfer', 'calls.audio_quality',
                    'calls.phone_reported_by_agent', 'calls.phone_reported_by_agent_reason', 'calls.phone_agent_hangup', 'calls.phone_agent_hangup_reason',
                    'calls.blacklist_id', 'blacklist.note as blacklist_note',
                    ...($against ? ['calls.transfer_against_advice'] : []),
                ], 'page', max(1, (int) $request->input('page', 1)))
            : null;

        $backup = $this->backupNumber();

        return response()->json([
            'counts' => collect(self::CASES)->mapWithKeys(fn ($c) => [$c => $c === 'against' && !$against ? null : (int) ($counts->{$c} ?? 0)]),
            'agents' => $base()->where(fn (Builder $q) => array_map(fn ($c) => $q->orWhereRaw($this->condition($c)), array_values(array_filter(self::CASES, fn ($c) => $c !== 'against' || $against))))
                ->whereNotNull('calls.agent_name')->distinct()->orderBy('calls.agent_name')->pluck('calls.agent_name'),
            'items' => collect($rows?->items() ?? [])->map(fn ($r) => [
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
                    $against && $r->transfer_against_advice
                        ? ['key' => 'against', 'toBackup' => $backup !== null && str_ends_with((string) $r->brand_phonenumber, $backup)]
                        : null,
                    $r->audio_quality !== null && $r->audio_quality < self::AUDIO_BAD ? ['key' => 'audio', 'mos' => (float) $r->audio_quality] : null,
                ])),
            ]),
            'page' => $rows?->currentPage() ?? 1,
            'lastPage' => $rows?->lastPage() ?? 1,
            'total' => $rows?->total() ?? 0,
        ]);
    }

    private function condition(string $case): string
    {
        return match ($case) {
            'reported' => 'calls.phone_reported_by_agent = 1',
            'hangup' => 'calls.phone_agent_hangup = 1',
            'blacklist' => 'calls.blacklist_id IS NOT NULL',
            'against' => 'calls.transfer_against_advice = 1',
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

    /** Colonne ajoutée par voxnode118 : absente tant que sa migration n'est pas passée. */
    private function hasAgainstColumn(): bool
    {
        return Cache::remember('calls:has-transfer-against-advice', 3600, fn () => Schema::hasColumn('calls', 'transfer_against_advice'));
    }

    /** Numéro de la marque BACKUP, sans indicatif, pour reconnaître un transfert vers elle (fin du numéro). */
    private function backupNumber(): ?string
    {
        $number = DB::table('brands')->where('name', 'BACKUP')->value('phonenumber');

        return $number ? substr(preg_replace('/\D/', '', $number), -9) : null;
    }
}
