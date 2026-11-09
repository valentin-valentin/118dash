<?php

namespace App\Http\Controllers;

use Carbon\CarbonInterface as Carbon;
use Illuminate\Support\Carbon as Date;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alertes agents : les alertes graves des journaux des agents (agent_logs), pour voir quel agent pose problème.
 *   f5      rechargement pendant une sonnerie avec une touche (F5, Ctrl+R…) : volontaire
 *   reload  rechargement pendant une sonnerie sans touche, en quittant la page (bouton, extension)
 *   crash   rechargement pendant une sonnerie sans quitter la page : plantage du navigateur
 *   missed  appel manqué (call_timeout) ; value = origine : null (softphone), 'server' (file de notre serveur).
 *           call_timeout 'reload' n'est pas compté : c'est le même événement que le rechargement ci-dessus
 *   endfail échec de fin de travail ou de pause
 * Les comptes de test (test01…, testeur de softphone) sont rangés dans le centre « Test ».
 * agent_logs est très grosse (pings) et sans index sur created_at : la période est bornée par une plage d'id trouvée
 * par recherche dichotomique sur la clé primaire (comme SupervisorController::firstIdSince dans voxnode118).
 */
class AgentAlertController extends Controller
{
    private const KINDS = ['f5', 'reload', 'crash', 'missed', 'endfail'];

    private const PER_PAGE = 50;

    public function index(): Response
    {
        return Inertia::render('AgentAlerts', [
            'centers' => collect(config('callcenters'))->reject(fn ($c) => $c['optional'] ?? false)
                ->map(fn ($c, $key) => ['key' => $key, 'label' => $c['label']])->values()
                ->push(['key' => 'test', 'label' => 'Test']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request->input('period', 'today'));
        $center = $request->input('center') ?: null;
        $kinds = array_values(array_intersect(self::KINDS, explode(',', (string) $request->input('kinds', implode(',', self::KINDS)))));

        // Alertes de la période (toutes les sortes), avec leur agent et son centre (comptes de test : centre « Test »)
        $alerts = $this->alerts($from, $to)
            ->filter(fn ($a) => !$center || $a['center'] === $center);

        $counts = collect(self::KINDS)->mapWithKeys(fn ($k) => [$k => $alerts->where('kind', $k)->count()]);
        $shown = $alerts->whereIn('kind', $kinds);

        // Classement : une ligne par agent, du plus grave au moins grave (F5, rechargements, plantages, appels manqués)
        $answered = $this->answered($shown->pluck('agentId')->unique()->values()->all(), $from, $to);
        $ranking = $shown->groupBy('agent')->map(function (Collection $list, $name) use ($answered) {
            $first = $list->first();

            return [
                'agent' => $name,
                'center' => $first['center'],
                'counts' => collect(self::KINDS)->mapWithKeys(fn ($k) => [$k => $list->where('kind', $k)->count()]),
                'answered' => $answered[$first['agentId']] ?? 0,
                'last' => $list->max('at'),
            ];
        })->sortBy(fn ($r) => [-$r['counts']['f5'], -$r['counts']['reload'], -$r['counts']['crash'], -$r['counts']['missed'], -$r['counts']['endfail'], $r['agent']])->values();

        $page = max(1, (int) $request->input('page', 1));
        $list = $shown->sortBy(fn ($a) => [$a['at'], $a['id']])->reverse()->values();

        return response()->json([
            'counts' => $counts,
            'ranking' => $ranking,
            'items' => $list->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'page' => $page,
            'lastPage' => max(1, (int) ceil($list->count() / self::PER_PAGE)),
            'total' => $list->count(),
        ]);
    }

    /**
     * Toutes les alertes d'un agent sur la période (fenêtre ouverte par un clic dans le classement), avec ses nombres
     * par sorte et ses appels décrochés. La plus récente d'abord.
     */
    public function agent(Request $request): JsonResponse
    {
        $name = (string) $request->input('agent');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name), 422);
        [$from, $to] = $this->period($request->input('period', 'today'));

        $alerts = $this->alerts($from, $to)->where('agent', $name)
            ->sortBy(fn ($a) => [$a['at'], $a['id']])->reverse()->values();
        $agentId = $alerts->first()['agentId'] ?? DB::table('agents')->where('voxnode_username', $name)->value('id');

        return response()->json([
            'agent' => $name,
            'center' => $this->centerOf($name),
            'counts' => collect(self::KINDS)->mapWithKeys(fn ($k) => [$k => $alerts->where('kind', $k)->count()]),
            'answered' => $agentId ? ($this->answered([$agentId], $from, $to)[$agentId] ?? 0) : 0,
            'items' => $alerts,
        ]);
    }

    /** Alertes de [$from, $to), classées, avec l'agent (nom, centre). */
    private function alerts(Carbon $from, Carbon $to): Collection
    {
        $first = $this->firstIdSince($from);
        $last = $to->isFuture() ? null : $this->firstIdSince($to);
        if ($first === null) {
            return collect();
        }

        return DB::table('agent_logs as l')
            ->join('agents as a', 'a.id', '=', 'l.agent_id')
            ->where('l.id', '>=', $first)
            ->when($last !== null, fn ($q) => $q->where('l.id', '<=', $last + 2000))
            ->where('l.created_at', '>=', $from)->where('l.created_at', '<', $to)
            ->where(fn ($q) => $q->where('l.type', 'danger')->whereIn('l.action', ['call_timeout', 'reload_during_ring'])
                ->orWhere(fn ($q) => $q->where('l.type', 'warning')->whereIn('l.action', ['end_work_failed', 'end_break_failed'])))
            ->get(['l.id', 'l.agent_id', 'l.action', 'l.value', 'l.created_at', 'a.voxnode_username'])
            ->map(fn ($r) => $this->classify($r))
            ->filter()
            ->values();
    }

    private function classify(object $r): ?array
    {
        $value = $r->value;
        $detail = [];
        if ($r->action === 'reload_during_ring') {
            $v = json_decode((string) $value, true) ?: [];
            $kind = !empty($v['key']) ? 'f5' : (!empty($v['left_page']) ? 'reload' : 'crash');
            $detail = ['key' => $v['key'] ?? null, 'ring' => isset($v['ring_s']) ? (int) $v['ring_s'] : null];
        } elseif ($r->action === 'call_timeout') {
            if ($value === 'reload') {
                return null;
            }
            $kind = 'missed';
            $detail = ['origin' => $value === 'server' ? 'server' : 'softphone'];
        } else {
            $kind = 'endfail';
            $detail = ['what' => $r->action === 'end_break_failed' ? 'break' : 'work'];
        }

        return [
            'id' => $r->id,
            'agentId' => $r->agent_id,
            'agent' => $r->voxnode_username,
            'center' => $this->centerOf((string) $r->voxnode_username),
            'kind' => $kind,
            'detail' => $detail,
            'at' => Date::parse($r->created_at, 'UTC')->toIso8601String(),
        ];
    }

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

    /**
     * Appels décrochés par ces agents sur la période (pour situer leurs alertes). Sans exclure les appels de test :
     * ce sont ceux des comptes de test, affichés ici dans le centre « Test ».
     */
    private function answered(array $agentIds, Carbon $from, Carbon $to): array
    {
        if (!$agentIds) {
            return [];
        }

        return DB::table('calls')
            ->whereIn('agent_id', $agentIds)->whereNotNull('phone_answered_at')
            ->where('called_at', '>=', $from)->where('called_at', '<', $to)
            ->groupBy('agent_id')->pluck(DB::raw('COUNT(*)'), 'agent_id')->map(fn ($n) => (int) $n)->all();
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
