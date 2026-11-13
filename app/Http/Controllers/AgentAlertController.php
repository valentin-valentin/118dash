<?php

namespace App\Http\Controllers;

use Carbon\CarbonInterface as Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon as Date;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alertes agents : quel agent pose problème, et de quelle façon. Deux familles :
 *   behaviour  ce que l'agent aurait pu éviter : missed, reload, closed, phone, pin, hangup, transfer, hold ; un événement
 *              comportement inconnu va dans other (jamais perdu)
 *   technical  ce qui ne vient pas de lui (réseau, navigateur, serveurs), jamais reproché : lost, breaker, ghost,
 *              restart, net, page, mic, unpause, unverified ; plus les incidents collectifs (tous les agents à la fois)
 * Source : presence_events (voxnode118, docs/presence.md), une ligne par fait de présence ; family = 'info' jamais
 * affiché ; phone et pin sont calculés sur les changements d'état (state_from ≠ state_to) qui durent 2 min ou plus.
 * Avant la première ligne de presence_events (et tant que la table n'existe pas) : l'ancienne lecture d'agent_logs,
 * rangée dans les mêmes sortes ; un même jour n'a qu'une source.
 * Les comptes de test (test01…, testeur de softphone) sont rangés dans le centre « Test ».
 */
class AgentAlertController extends Controller
{
    private const KINDS = [
        'behaviour' => ['missed', 'reload', 'closed', 'phone', 'pin', 'hangup', 'transfer', 'hold', 'other'],
        'technical' => ['lost', 'breaker', 'ghost', 'restart', 'net', 'page', 'mic', 'unpause', 'unverified'],
    ];

    // Événements techniques par sorte ; toute autre ligne technique va dans « page » (rien n'est perdu)
    private const TECHNICAL_EVENTS = [
        'lost' => ['ring_not_received', 'ring_late', 'ring_end_not_seen', 'ring_too_long', 'ring_refused_busy'],
        'breaker' => ['technical_circuit_breaker'],
        'ghost' => ['ghost_call_released'],
        'restart' => ['phone_not_registered'],
        'net' => ['silent_while_working', 'silent_on_pin', 'phone_down'],
        // page_left_in_call en technique : la page tenait un appel et le navigateur a planté (detail.crashed)
        'page' => ['js_error', 'stalled', 'frozen', 'ring_lost_page_crashed', 'page_reloaded_after_crash', 'page_left_in_call'],
        'mic' => ['microphone_unavailable', 'ringtone_blocked', 'answer_failed'],
        'unpause' => ['unpause_failed'],
        'unverified' => ['ring_unverifiable', 'missed_ring_not_seen', 'break_end_without_break'],
    ];

    private const COLLECTIVE = ['collective_silence', 'collective_ring_failure'];

    // Durée à partir de laquelle un téléphone laissé éteint ou un écran PIN non retapé devient une alerte
    private const LONG_STATE = 120;

    private const PER_PAGE = 50;

    public function index(): Response
    {
        return Inertia::render('AgentAlerts', [
            'centers' => collect(config('callcenters'))->reject(fn ($c) => $c['optional'] ?? false)
                ->map(fn ($c, $key) => ['key' => $key, 'label' => $c['label']])->values()
                ->push(['key' => 'test', 'label' => 'Test']),
        ]);
    }

    /**
     * La page : nombre de chaque sorte, classement et liste de la famille choisie (all = les deux), incidents collectifs.
     * Sur all, le classement compte par famille (une colonne par sorte ne tiendrait pas) ; sur une famille, par sorte.
     */
    public function data(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request->input('period', 'today'));
        $center = $request->input('center') ?: null;
        $family = in_array($request->input('family'), ['behaviour', 'technical'], true) ? $request->input('family') : 'all';
        $allowed = $family === 'all' ? [...self::KINDS['behaviour'], ...self::KINDS['technical']] : self::KINDS[$family];
        $kinds = array_values(array_intersect($allowed, explode(',', (string) $request->input('kinds', implode(',', $allowed)))));
        $columns = $family === 'all' ? ['behaviour', 'technical'] : self::KINDS[$family];

        $all = $this->alerts($from, $to);
        $alerts = $all['alerts']->filter(fn ($a) => !$center || $a['center'] === $center);
        $counts = collect(self::KINDS)->flatten()->mapWithKeys(fn ($k) => [$k => $alerts->where('kind', $k)->count()]);
        $shown = $alerts->whereIn('kind', $kinds);

        // Classement : une ligne par agent, du plus grand nombre d'alertes au plus petit, puis sorte par sorte dans l'ordre
        $ids = $shown->pluck('agentId')->unique()->values()->all();
        $answered = $family !== 'technical' ? $this->answered($ids, $from, $to) : [];
        $breaks = $family !== 'technical' ? $this->breakSeconds($ids, $from, $to) : [];
        $ranking = $shown->groupBy('agent')->map(function (Collection $list, $name) use ($family, $columns, $answered, $breaks) {
            $first = $list->first();

            return [
                'agent' => $name,
                'center' => $first['center'],
                'counts' => collect($columns)->mapWithKeys(fn ($k) => [$k => $list->where($family === 'all' ? 'family' : 'kind', $k)->count()]),
                'answered' => $answered[$first['agentId']] ?? 0,
                'breakSec' => $breaks[$first['agentId']] ?? 0,
                'last' => $list->max('at'),
            ];
        })->sortBy(fn ($r) => [-array_sum($r['counts']->all()), ...array_map(fn ($k) => -$r['counts'][$k], $columns), $r['agent']])->values();

        $page = max(1, (int) $request->input('page', 1));
        $list = $shown->sortBy(fn ($a) => [$a['at'], (string) $a['id']])->reverse()->values();

        return response()->json([
            'counts' => $counts,
            'ranking' => $ranking,
            // Incidents collectifs : avec la famille Technique (ou toutes), tous centres (ils touchent toute la plateforme)
            'incidents' => $family !== 'behaviour' ? $all['incidents'] : [],
            'items' => $list->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'page' => $page,
            'lastPage' => max(1, (int) ceil($list->count() / self::PER_PAGE)),
            'total' => $list->count(),
        ]);
    }

    /**
     * Fenêtre d'un agent : la période en chiffres (travail, dont en appel, pauses par type, décrochés, alertes par famille)
     * et le fil : ses alertes des deux familles, ses pauses et ses débuts de journée, le plus récent d'abord.
     */
    public function agent(Request $request): JsonResponse
    {
        $name = (string) $request->input('agent');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name), 422);
        [$from, $to] = $this->period($request->input('period', 'today'));
        $agentId = (int) DB::table('agents')->where('voxnode_username', $name)->value('id');
        abort_unless($agentId, 404);

        $alerts = $this->read($from, $to, $agentId)['alerts'];
        $attendances = DB::table('agent_attendances')->where('agent_id', $agentId)->whereNull('deleted_at')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')
            ->get(['action', 'type', 'created_at', 'ended_at']);
        $seconds = fn ($a) => $this->periodSeconds($a, $to);
        $breaks = $attendances->where('action', 'break')->values();
        $works = $attendances->where('action', 'work')->values();

        // Pauses et débuts de journée (première période de travail de chaque jour de Paris), en gris dans le fil
        $info = collect();
        $days = [];
        foreach ($works as $w) {
            $day = Date::parse($w->created_at, 'UTC')->setTimezone('Europe/Paris')->toDateString();
            if (!isset($days[$day])) {
                $days[$day] = true;
                $info->push(['id' => 'start-'.$day, 'family' => 'info', 'kind' => 'start', 'at' => $this->iso($w->created_at), 'detail' => []]);
            }
        }
        foreach ($breaks as $i => $b) {
            $info->push(['id' => 'break-'.$i, 'family' => 'info', 'kind' => 'break', 'at' => $this->iso($b->created_at), 'detail' => [
                'type' => $b->type, 'until' => $b->ended_at ? $this->iso($b->ended_at) : null, 'seconds' => $seconds($b),
            ]]);
        }

        // En appel : somme des durées de ses appels décrochés sur la période
        $inCall = (int) $this->callsQuery([$agentId], $from, $to)->sum(DB::raw('COALESCE(phone_duration_agent, duration_agent, 0)'));

        return response()->json([
            'agent' => $name,
            'center' => $this->centerOf($name),
            'workSec' => (int) $works->sum($seconds),
            'inCallSec' => $inCall,
            'breakSec' => collect(['pause', 'lunch', 'manager'])->mapWithKeys(fn ($t) => [$t => (int) $breaks->where('type', $t)->sum($seconds)])
                ->put('other', (int) $breaks->whereNotIn('type', ['pause', 'lunch', 'manager'])->sum($seconds)),
            'answered' => $this->answered([$agentId], $from, $to)[$agentId] ?? 0,
            'counts' => collect(self::KINDS)->flatten()->mapWithKeys(fn ($k) => [$k => $alerts->where('kind', $k)->count()]),
            'items' => $alerts->concat($info)->sortBy(fn ($a) => [$a['at'], (string) $a['id']])->reverse()->values(),
        ]);
    }

    // ─── Lecture ──────────────────────────────────────────────────────────────────

    /** Alertes de [$from, $to), tous agents : relues au plus une fois par minute et par période. */
    private function alerts(Carbon $from, Carbon $to): array
    {
        $read = Cache::remember("agent_alerts:v2:{$from->timestamp}:{$to->timestamp}", 60, function () use ($from, $to) {
            $r = $this->read($from, $to);

            return ['alerts' => $r['alerts']->all(), 'incidents' => $r['incidents']];
        });

        return ['alerts' => collect($read['alerts']), 'incidents' => $read['incidents']];
    }

    /**
     * Alertes de [$from, $to) (d'un seul agent si $agentId) : presence_events à partir du jour de sa première ligne,
     * agent_logs avant ; un même jour n'a qu'une source.
     */
    private function read(Carbon $from, Carbon $to, ?int $agentId = null): array
    {
        $start = $this->presenceStart();
        // Jour de bascule ramené dans [$from, $to]
        $split = $start === null ? $to : ($start->lessThan($from) ? $from : ($start->greaterThan($to) ? $to : $start));
        $legacy = $from->lessThan($split) ? $this->readLogs($from, $split, $agentId) : collect();
        $presence = $split->lessThan($to) ? $this->readPresence($split, $to, $agentId) : ['alerts' => collect(), 'incidents' => []];

        return ['alerts' => $legacy->concat($presence['alerts'])->values(), 'incidents' => $presence['incidents']];
    }

    /** Début (UTC) du jour de Paris de la première ligne de presence_events ; null tant que la table est absente ou vide. */
    private function presenceStart(): ?Carbon
    {
        $first = Cache::remember('presence_events:first_day', 3600, function () {
            if (!Schema::hasTable('presence_events')) {
                return null;
            }
            $at = DB::table('presence_events')->min('at');

            return $at ? Date::parse($at, 'UTC')->setTimezone('Europe/Paris')->startOfDay()->utc()->toIso8601String() : null;
        });

        return $first ? Date::parse($first) : null;
    }

    private function readPresence(Carbon $from, Carbon $to, ?int $agentId): array
    {
        $events = DB::table('presence_events as e')
            ->join('agents as a', 'a.id', '=', 'e.agent_id')
            ->when($agentId !== null, fn ($q) => $q->where('e.agent_id', $agentId))
            ->where('e.at', '>=', $from)->where('e.at', '<', $to)
            ->whereIn('e.family', ['behaviour', 'technical'])
            ->orderBy('e.at')
            ->get(['e.id', 'e.agent_id', 'e.at', 'e.event', 'e.family', 'e.detail', 'a.voxnode_username']);

        $alerts = collect();
        $collective = collect();
        foreach ($events as $e) {
            $detail = json_decode((string) $e->detail, true) ?: [];
            if (in_array($e->event, self::COLLECTIVE, true)) {
                $collective->push(['at' => strtotime($e->at), 'event' => $e->event, 'detail' => $detail]);

                continue;
            }
            $alerts->push($this->alert($e->id, $e->agent_id, $e->voxnode_username, $this->presenceKind($e->event, $e->family), $e->at,
                ['event' => $e->event] + array_intersect_key($detail, array_flip([
                    'ring_s', 'from', 'how', 'value', 'state', 'reason', 'after_s', 'brand', 'number', 'page_ring_s', 'ring_ms', 'misses', 'hold_s', 'by', 'crashed', 'key', 'during',
                ]))));
        }

        return ['alerts' => $alerts->concat($this->longStates($from, $to, $agentId)), 'incidents' => $this->incidents($collective)];
    }

    private function presenceKind(string $event, string $family): string
    {
        if ($family === 'behaviour') {
            return match ($event) {
                'missed_call' => 'missed',
                'missed_reload_during_ring', 'reload_blocked', 'page_left_in_call' => 'reload',
                'page_closed_while_working', 'page_closed_on_pin', 'logout_on_pin', 'page_taken_over', 'old_page_still_open' => 'closed',
                'agent_hangup' => 'hangup',
                'transfer_within_10s' => 'transfer',
                'hold_too_long' => 'hold',
                default => 'other',
            };
        }
        foreach (self::TECHNICAL_EVENTS as $kind => $list) {
            if (in_array($event, $list, true)) {
                return $kind;
            }
        }

        return 'page';
    }

    /**
     * Téléphone laissé éteint et PIN pas retapé : un passage à phone_off (par un événement d'information, pas une coupure
     * technique) ou à missed, dont la partie dans les heures d'ouverture dure 2 min ou plus.
     *   fin : le changement d'état suivant de l'agent ; pour phone_off, aussi le prochain phone_started_outside_service
     *         (téléphone allumé hors service : l'état reste phone_off, « pas de travail compté », mais il est allumé) ;
     *         aucune fin et l'agent toujours dans cet état : jusqu'à maintenant ; sinon fin inconnue, pas d'alerte
     *   heures d'ouverture : settings.opening_hours, jour par jour en heure de Paris (une page ouverte avant l'ouverture
     *         ou le soir ne compte pas)
     */
    private function longStates(Carbon $from, Carbon $to, ?int $agentId): Collection
    {
        $rows = DB::table('presence_events as e')
            ->join('agents as a', 'a.id', '=', 'e.agent_id')
            ->when($agentId !== null, fn ($q) => $q->where('e.agent_id', $agentId))
            ->where('e.at', '>=', $from)->where('e.at', '<', $to->copy()->addDay())
            ->where(fn ($q) => $q->whereColumn('e.state_from', '!=', 'e.state_to')->orWhere('e.event', 'phone_started_outside_service'))
            ->orderBy('e.agent_id')->orderBy('e.at')->orderBy('e.id')
            ->get(['e.id', 'e.agent_id', 'e.at', 'e.event', 'e.family', 'e.state_from', 'e.state_to', 'a.voxnode_username'])
            ->groupBy('agent_id');
        $current = Schema::hasColumn('agents', 'presence_state')
            ? DB::table('agents')->whereIn('id', $rows->keys())->pluck('presence_state', 'id') : collect();
        $hours = $this->openingHours();

        $alerts = collect();
        foreach ($rows as $id => $list) {
            $list = $list->values();
            foreach ($list as $i => $c) {
                if ($c->state_from === $c->state_to) {
                    continue; // phone_started_outside_service : ne commence rien, peut seulement finir une période
                }
                $phone = $c->state_to === 'phone_off' && $c->family === 'info' && !in_array($c->event, ['phone_down', 'phone_started_outside_service'], true);
                if (strtotime($c->at) >= $to->getTimestamp() || (!$phone && $c->state_to !== 'missed')) {
                    continue;
                }
                $next = $list->slice($i + 1)->first(fn ($n) => $n->state_from !== $n->state_to || ($phone && $n->event === 'phone_started_outside_service'));
                if (!$next && ($current[$id] ?? null) !== $c->state_to) {
                    continue; // fin inconnue : pas d'alerte inventée
                }
                $open = $this->withinOpening(strtotime($c->at), $next ? strtotime($next->at) : time(), $hours);
                if ($open && $open['seconds'] >= self::LONG_STATE) {
                    $alerts->push($this->alert('s'.$c->id, $c->agent_id, $c->voxnode_username, $phone ? 'phone' : 'pin', date('Y-m-d H:i:s', $open['start']), [
                        'event' => $phone ? 'phone_left_off' : 'pin_not_typed',
                        'until' => $next ? Date::createFromTimestamp($open['end'], 'UTC')->toIso8601String() : null,
                        'seconds' => $open['seconds'],
                    ]));
                }
            }
        }

        return $alerts;
    }

    /**
     * Partie de [$start, $end] (timestamps) comprise dans les heures d'ouverture, jour par jour en heure de Paris :
     * [start, end, seconds] du premier au dernier moment ouvert, seconds = la somme ; null si rien n'est ouvert.
     */
    private function withinOpening(int $start, int $end, array $hours): ?array
    {
        $seconds = 0;
        $first = $last = null;
        $day = Date::createFromTimestamp($start, 'UTC')->setTimezone('Europe/Paris')->startOfDay();
        while ($day->getTimestamp() < $end) {
            $h = $hours[strtolower($day->englishDayOfWeek)] ?? null;
            if ($h && isset($h['open'], $h['close'])) {
                $o = $day->copy()->setTimeFromTimeString($h['open'])->getTimestamp();
                $c = $day->copy()->setTimeFromTimeString($h['close'])->getTimestamp();
                $a = max($start, $o);
                $b = min($end, $c);
                if ($b > $a) {
                    $seconds += $b - $a;
                    $first ??= $a;
                    $last = $b;
                }
            }
            $day = $day->addDay();
        }

        return $first === null ? null : ['start' => $first, 'end' => $last, 'seconds' => $seconds];
    }

    /** Heures d'ouverture (settings.opening_hours, voxnode118), par jour : {monday: {open, close}, …, sunday: null}. */
    private function openingHours(): array
    {
        $saved = json_decode((string) DB::table('settings')->where('key', 'opening_hours')->value('value'), true);
        $weekday = ['open' => '07:59', 'close' => '20:00'];

        return is_array($saved) ? $saved : [
            'monday' => $weekday, 'tuesday' => $weekday, 'wednesday' => $weekday, 'thursday' => $weekday, 'friday' => $weekday,
            'saturday' => ['open' => '08:59', 'close' => '19:00'], 'sunday' => null,
        ];
    }

    /** Incidents collectifs : événements à moins de 2 min l'un de l'autre = un incident (heure, nombre d'agents). */
    private function incidents(Collection $events): array
    {
        $groups = [];
        foreach ($events->sortBy('at') as $e) {
            $last = array_key_last($groups);
            if ($last !== null && $e['at'] - $groups[$last]['end'] < 120) {
                $groups[$last]['end'] = $e['at'];
                $groups[$last]['events'][] = $e;
            } else {
                $groups[] = ['start' => $e['at'], 'end' => $e['at'], 'events' => [$e]];
            }
        }

        return array_map(function ($g) {
            $max = fn ($event, $field) => (int) collect($g['events'])->where('event', $event)->max(fn ($e) => (int) ($e['detail'][$field] ?? 0));

            return [
                'at' => Date::createFromTimestamp($g['start'], 'UTC')->toIso8601String(),
                'seconds' => $g['end'] - $g['start'],
                'silent' => $max('collective_silence', 'silent') ?: null,
                'active' => $max('collective_silence', 'active') ?: null,
                'noRing' => $max('collective_ring_failure', 'agents') ?: null,
                'onPbx' => $max('collective_ring_failure', 'on_pbx') ?: null,
            ];
        }, array_reverse($groups));
    }

    /**
     * Ancienne source (jours avant presence_events) : agent_logs, rangé dans les nouvelles sortes. Très grosse table
     * (pings), sans index sur created_at : plage d'id trouvée par recherche dichotomique sur la clé primaire.
     *   reload_during_ring : clavier ou départ de la page → reload, sinon plantage → page (technique)
     *   call_timeout : null → missed, 'server' → unverified ; 'reload' = l'écran PIN du rechargement, pas compté
     *   end_work_failed / end_break_failed → page (technique)
     */
    private function readLogs(Carbon $from, Carbon $to, ?int $agentId): Collection
    {
        $first = $this->firstIdSince($from);
        $last = $to->isFuture() ? null : $this->firstIdSince($to);
        if ($first === null) {
            return collect();
        }

        $rows = DB::table('agent_logs as l')
            ->join('agents as a', 'a.id', '=', 'l.agent_id')
            ->where('l.id', '>=', $first)
            ->when($last !== null, fn ($q) => $q->where('l.id', '<=', $last + 2000))
            ->when($agentId !== null, fn ($q) => $q->where('l.agent_id', $agentId))
            ->where('l.created_at', '>=', $from)->where('l.created_at', '<', $to)
            ->where(fn ($q) => $q->where('l.type', 'danger')->whereIn('l.action', ['call_timeout', 'reload_during_ring'])
                ->orWhere(fn ($q) => $q->where('l.type', 'warning')->whereIn('l.action', ['end_work_failed', 'end_break_failed'])))
            ->get(['l.id', 'l.agent_id', 'l.action', 'l.value', 'l.created_at', 'a.voxnode_username']);

        // L'écran PIN qui suit un rechargement (call_timeout 'reload', même agent, à 2 min près) : « PIN demandé »
        $pins = $rows->filter(fn ($r) => $r->action === 'call_timeout' && $r->value === 'reload')
            ->map(fn ($r) => [$r->agent_id, strtotime($r->created_at)])->values();
        $pinFor = fn ($r) => $pins->contains(fn ($p) => $p[0] === $r->agent_id && abs($p[1] - strtotime($r->created_at)) <= 120);

        return $rows->map(function ($r) use ($pinFor) {
            if ($r->action === 'reload_during_ring') {
                $v = json_decode((string) $r->value, true) ?: [];
                $voluntary = !empty($v['key']) || !empty($v['left_page']);

                return $this->alert($r->id, $r->agent_id, $r->voxnode_username, $voluntary ? 'reload' : 'page', $r->created_at, [
                    'event' => $voluntary ? 'legacy_reload' : 'legacy_crash', 'value' => $v['key'] ?? null, 'ring_s' => $v['ring_s'] ?? null, 'pin' => $pinFor($r),
                ]);
            }
            if ($r->action === 'call_timeout') {
                return $r->value === 'reload' ? null : $this->alert($r->id, $r->agent_id, $r->voxnode_username,
                    $r->value === 'server' ? 'unverified' : 'missed', $r->created_at, ['event' => $r->value === 'server' ? 'legacy_missed_server' : 'legacy_missed']);
            }

            return $this->alert($r->id, $r->agent_id, $r->voxnode_username, 'page', $r->created_at,
                ['event' => $r->action === 'end_break_failed' ? 'legacy_end_break_failed' : 'legacy_end_work_failed']);
        })->filter()->values();
    }

    private function alert(int|string $id, int $agentId, ?string $name, string $kind, string $at, array $detail): array
    {
        return [
            'id' => $id,
            'agentId' => $agentId,
            'agent' => $name,
            'center' => $this->centerOf((string) $name),
            'family' => in_array($kind, self::KINDS['behaviour'], true) ? 'behaviour' : 'technical',
            'kind' => $kind,
            'detail' => $detail,
            'at' => $this->iso($at),
        ];
    }

    // ─── Chiffres ─────────────────────────────────────────────────────────────────

    /**
     * Appels décrochés par ces agents sur la période (pour situer leurs alertes). Sans exclure les appels de test :
     * ce sont ceux des comptes de test, affichés ici dans le centre « Test ».
     */
    private function answered(array $agentIds, Carbon $from, Carbon $to): array
    {
        return $agentIds ? $this->callsQuery($agentIds, $from, $to)->groupBy('agent_id')
            ->pluck(DB::raw('COUNT(*)'), 'agent_id')->map(fn ($n) => (int) $n)->all() : [];
    }

    /**
     * Appels décrochés : sur phone_answered_at (écrit au décroché ; called_at n'est rempli qu'à l'import, jamais pour
     * le testeur). Sans index : d'abord une plage d'id (au plus 15 000 appels par jour depuis le début de la période).
     */
    private function callsQuery(array $agentIds, Carbon $from, Carbon $to)
    {
        $days = max(1, (int) ceil($from->diffInHours(now(), true) / 24));
        $minId = (int) DB::table('calls')->max('id') - 15000 * $days;

        return DB::table('calls')->where('id', '>', $minId)->whereIn('agent_id', $agentIds)
            ->where('phone_answered_at', '>=', $from)->where('phone_answered_at', '<', $to);
    }

    /** Durée totale des pauses (toutes sortes) de ces agents sur la période, en secondes. */
    private function breakSeconds(array $agentIds, Carbon $from, Carbon $to): array
    {
        if (!$agentIds) {
            return [];
        }

        return DB::table('agent_attendances')->where('action', 'break')->whereNull('deleted_at')->whereIn('agent_id', $agentIds)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->get(['agent_id', 'created_at', 'ended_at'])
            ->groupBy('agent_id')->map(fn ($list) => (int) $list->sum(fn ($a) => $this->periodSeconds($a, $to)))->all();
    }

    /**
     * Durée d'une période de travail ou de pause (agent_attendances). Une période pas encore fermée court jusqu'à
     * maintenant, sans dépasser la fin de la période affichée ni la fin de son jour (heure de Paris) : une page partie
     * sans fermer sa période ne compte pas des jours entiers.
     */
    private function periodSeconds(object $a, Carbon $to): int
    {
        $start = strtotime($a->created_at);
        $end = $a->ended_at ? strtotime($a->ended_at)
            : min(time(), $to->getTimestamp(), Date::createFromTimestamp($start, 'UTC')->setTimezone('Europe/Paris')->endOfDay()->getTimestamp());

        return max(0, $end - $start);
    }

    // ─── Outils ───────────────────────────────────────────────────────────────────

    /**
     * Centre d'un agent par son nom : les comptes de test (test + chiffres, testeur de softphone) dans « Test », pour
     * vérifier la chaîne de bout en bout ; sinon noms exacts d'abord, puis le préfixe le plus long (config/callcenters.php).
     */
    private function centerOf(string $name): ?string
    {
        if (preg_match('/^test\d+$/', $name)) {
            return 'test';
        }
        static $centers = null;
        $centers ??= collect(config('callcenters'))->reject(fn ($c) => $c['optional'] ?? false);
        foreach ($centers as $key => $c) {
            if (in_array($name, $c['agents'] ?? [], true)) {
                return $key;
            }
        }
        $best = null;
        $length = 0;
        foreach ($centers as $key => $c) {
            foreach ($c['prefixes'] ?? [] as $prefix) {
                if (str_starts_with($name, $prefix) && strlen($prefix) > $length) {
                    [$best, $length] = [$key, strlen($prefix)];
                }
            }
        }

        return $best;
    }

    private function iso(string $utc): string
    {
        return Date::parse($utc, 'UTC')->toIso8601String();
    }

    /** Période en jours de Paris, bornes en UTC. */
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

    /**
     * Premier id de agent_logs créé à partir de $since (moins une marge de 1 000 lignes), par recherche dichotomique
     * sur la clé primaire ; gardé 1 h. null si $since est dans le futur.
     */
    private function firstIdSince(Carbon $since): ?int
    {
        if ($since->isFuture()) {
            return null;
        }

        return Cache::remember('agent_logs_first_id:' . $since->timestamp, 3600, function () use ($since) {
            $lo = (int) DB::table('agent_logs')->min('id');
            $hi = (int) DB::table('agent_logs')->max('id');
            $at = $since->format('Y-m-d H:i:s');
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                $created = DB::table('agent_logs')->where('id', '>=', $mid)->orderBy('id')->value('created_at');
                if ($created !== null && $created >= $at) {
                    $hi = $mid;
                } else {
                    $lo = $mid + 1;
                }
            }

            return max(0, $lo - 1000);
        });
    }
}
