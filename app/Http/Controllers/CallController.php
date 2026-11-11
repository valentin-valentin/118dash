<?php

namespace App\Http\Controllers;

use App\Helpers\CarrierHelper;
use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CallController extends Controller
{
    private const TZ = 'Europe/Paris';

    // Service interne (Backup) : fin du numéro composé (calls.brand_phonenumber)
    private const BACKUP_NUMBER = '184131572';

    public function index(): Response
    {
        return Inertia::render('Calls', ['centers' => $this->centersForDisplay()]);
    }

    public function show(Call $call): Response
    {
        return Inertia::render('CallDetails', ['callId' => $call->id, 'centers' => $this->centersForDisplay()]);
    }

    /**
     * Centres d'appels (config/callcenters.php) : la page déduit le centre d'un appel du nom de l'agent.
     */
    private function centersForDisplay(): array
    {
        return collect(config('callcenters'))
            ->map(fn ($c, $key) => [
                'key' => $key,
                'label' => $c['label'],
                'color' => $c['color'] ?? null,
                'agents' => $c['agents'] ?? [],
                'prefixes' => $c['prefixes'] ?? [],
                'optional' => (bool) ($c['optional'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * Fiche complète d'un appel : liens (numéro, source…), détail réseau, autres appels du même numéro.
     */
    public function detail(Call $call): JsonResponse
    {
        $call->load([
            'blacklist:id,phonenumber,note,source',
            'source:id,name,color',
            'phonenumber' => fn ($q) => $q->withTrashed()->select('id', 'phonenumber', 'provider_id', 'company_id', 'source_id'),
            'phonenumber.provider:id,name,color',
            'phonenumber.company:id,name,color',
            'phonenumber.source:id,name,color',
        ]);

        $quality = \DB::table('call_quality')->where('call_id', $call->id)->first();
        $callerCalls = $call->caller
            ? Call::where('caller', $call->caller)->where('id', '!=', $call->id)
                ->orderByDesc('called_at')->limit(15)
                ->get(['id', 'called_at', 'called', 'total_duration', 'duration_agent', 'duration_transfer', 'agent_name', 'brand_name', 'record_agent'])
            : collect();
        $callerTotal = $call->caller ? Call::where('caller', $call->caller)->count() : 0;

        return response()->json([
            'call' => $call,
            'quality' => $quality,
            'caller_calls' => $callerCalls,
            'caller_total' => $callerTotal,
        ]);
    }

    /**
     * Résumé des appels filtrés : volumes, résultat, durées et argent.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Call::query();
        $this->applyFilters($request, $query);

        $row = $query->toBase()->selectRaw('
            COUNT(*) AS calls,
            SUM(COALESCE(duration_agent, 0) > 0) AS answered,
            SUM(COALESCE(duration_agent, 0) = 0) AS lost,
            SUM(COALESCE(duration_transfer, 0) > 0) AS transferred,
            AVG(total_duration) AS avg_total,
            AVG(CASE WHEN duration_agent > 0 THEN duration_agent END) AS avg_agent,
            AVG(CASE WHEN duration_agent > 0 THEN GREATEST(0, total_duration - duration_agent - COALESCE(duration_transfer, 0)) END) AS avg_wait_answered,
            COALESCE(SUM(payout), 0) AS payout,
            COALESCE(SUM(payout_source), 0) AS payout_source,
            SUM(record_agent IS NOT NULL AND record_agent <> \'\') AS recorded
        ')->first();

        return response()->json([
            'calls' => (int) $row->calls,
            'answered' => (int) $row->answered,
            'lost' => (int) $row->lost,
            'transferred' => (int) $row->transferred,
            'avg_total' => $row->avg_total !== null ? (int) round($row->avg_total) : null,
            'avg_agent' => $row->avg_agent !== null ? (int) round($row->avg_agent) : null,
            'avg_wait_answered' => $row->avg_wait_answered !== null ? (int) round($row->avg_wait_answered) : null,
            'payout' => round((float) $row->payout, 2),
            'payout_source' => round((float) $row->payout_source, 2),
            'recorded' => (int) $row->recorded,
        ]);
    }

    /**
     * Appels d'un centre, d'après le nom de l'agent (règles de config/callcenters.php) ; 'none' = aucun centre.
     */
    private function applyCenter($query, string $key): void
    {
        $centers = config('callcenters');
        $exact = collect($centers)->flatMap(fn ($c) => $c['agents'] ?? [])->all();
        $matchesCenter = function ($q, array $c, string $k) use ($centers) {
            $q->where(function ($q) use ($c, $k, $centers) {
                if (!empty($c['agents'])) {
                    $q->orWhereIn('agent_name', $c['agents']);
                }
                foreach ($c['prefixes'] ?? [] as $prefix) {
                    // Les noms exacts des autres centres (non optionnels) ne comptent pas dans le préfixe
                    $others = collect($centers)->except($k)->reject(fn ($o) => $o['optional'] ?? false)->flatMap(fn ($o) => $o['agents'] ?? [])->all();
                    $q->orWhere(fn ($q) => $q->where('agent_name', 'like', addcslashes($prefix, '%_\\') . '%')->whereNotIn('agent_name', $others));
                }
            });
        };

        if ($key === 'none') {
            $query->where(function ($q) use ($centers, $exact) {
                $q->whereNull('agent_name')->orWhere(function ($q) use ($centers, $exact) {
                    $q->whereNotIn('agent_name', $exact);
                    foreach ($centers as $c) {
                        foreach ($c['prefixes'] ?? [] as $prefix) {
                            $q->where('agent_name', 'not like', addcslashes($prefix, '%_\\') . '%');
                        }
                    }
                });
            });

            return;
        }
        abort_unless(isset($centers[$key]), 422, 'Centre inconnu');
        $matchesCenter($query, $centers[$key], $key);
    }

    /**
     * Parse multi-select filter value (comma-separated or array)
     */
    private function parseMultiSelect($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }

    /**
     * Applique les filtres de la requête sur la query. Retourne true si au moins un filtre est actif.
     */
    private function applyFilters(Request $request, $query): bool
    {
        // Détecter si des filtres sont actifs
        $hasFilters = false;

        // Filtres
        // Recherche d'un numéro, appelant ou appelé : « 06 12 34 », « 0612… » ou « +33612… »
        if ($request->filled('q')) {
            $hasFilters = true;
            $digits = preg_replace('/[^\d+]/', '', $request->q);
            $normalized = preg_replace('/^(\+33|0033|0)/', '', $digits);
            $query->where(fn ($q) => $q->where('caller', 'like', "%{$normalized}%")->orWhere('called', 'like', "%{$normalized}%"));
        }

        if ($request->filled('outcome')) {
            $hasFilters = true;
            match ($request->outcome) {
                'answered' => $query->where('duration_agent', '>', 0),
                'lost' => $query->where(fn ($q) => $q->whereNull('duration_agent')->orWhere('duration_agent', '<=', 0)),
                'transferred' => $query->where('duration_transfer', '>', 0),
                // Un transfert vers Backup garde le nom de la marque demandée : seul le numéro composé les distingue
                'to_brand' => $query->where('duration_transfer', '>', 0)->where(fn ($q) => $q->whereNull('brand_phonenumber')->orWhereRaw('RIGHT(brand_phonenumber, 9) <> ?', [self::BACKUP_NUMBER])),
                'to_backup' => $query->where('duration_transfer', '>', 0)->whereRaw('RIGHT(brand_phonenumber, 9) = ?', [self::BACKUP_NUMBER]),
                default => null,
            };
        }

        if ($request->filled('center')) {
            $hasFilters = true;
            $this->applyCenter($query, $request->center);
        }

        if ($request->input('has_recording') === 'yes') {
            $hasFilters = true;
            $query->whereNotNull('record_agent')->where('record_agent', '!=', '');
        }

        if ($request->filled('caller')) {
            $hasFilters = true;
            $query->where('caller', 'like', "%{$request->caller}%");
        }

        if ($request->filled('called')) {
            $hasFilters = true;
            $query->where('called', 'like', "%{$request->called}%");
        }

        if ($request->filled('brand_name')) {
            $hasFilters = true;
            $brands = $this->parseMultiSelect($request->brand_name);
            $query->whereIn('brand_name', $brands);
        }

        if ($request->filled('agent_name')) {
            $hasFilters = true;
            $agents = $this->parseMultiSelect($request->agent_name);
            $query->whereIn('agent_name', $agents);
        }

        if ($request->filled('callcenter_id')) {
            $hasFilters = true;
            $callcenters = $this->parseMultiSelect($request->callcenter_id);
            // Convert to integers to ensure proper comparison
            $callcenters = array_map('intval', $callcenters);
            $query->whereIn('callcenter_id', $callcenters);
        }

        if ($request->filled('carrier')) {
            $hasFilters = true;
            $carriers = $this->parseMultiSelect($request->carrier);
            $query->whereIn('carrier', $carriers);
        }

        if ($request->filled('provider_id')) {
            $hasFilters = true;
            $providers = $this->parseMultiSelect($request->provider_id);
            // Convert to integers to ensure proper comparison
            $providers = array_map('intval', $providers);
            $query->whereHas('phonenumber', function ($q) use ($providers) {
                $q->withTrashed()->whereIn('provider_id', $providers);
            });
        }

        if ($request->filled('company_id')) {
            $hasFilters = true;
            $companies = $this->parseMultiSelect($request->company_id);
            // Convert to integers to ensure proper comparison
            $companies = array_map('intval', $companies);
            $query->whereHas('phonenumber', function ($q) use ($companies) {
                $q->withTrashed()->whereIn('company_id', $companies);
            });
        }

        if ($request->filled('source_id')) {
            $hasFilters = true;
            $sources = $this->parseMultiSelect($request->source_id);
            // Convert to integers to ensure proper comparison
            $sources = array_map('intval', $sources);
            // Filter logic must match display logic:
            // Show source from Call.source_id if exists, otherwise from Phonenumber.source_id
            $query->where(function ($q) use ($sources) {
                // Case 1: Call has direct source_id
                $q->whereIn('source_id', $sources)
                  // Case 2: Call has NO source_id (null) AND phonenumber has source_id
                  ->orWhere(function ($sq) use ($sources) {
                      $sq->whereNull('source_id')
                         ->whereHas('phonenumber', function ($psq) use ($sources) {
                             $psq->withTrashed()->whereIn('source_id', $sources);
                         });
                  });
            });
        }

        // Jours en heure de Paris
        if ($request->filled('date_from')) {
            $hasFilters = true;
            $query->where('called_at', '>=', \Carbon\Carbon::parse($request->date_from, self::TZ)->startOfDay()->utc());
        }

        if ($request->filled('date_to')) {
            $hasFilters = true;
            $query->where('called_at', '<=', \Carbon\Carbon::parse($request->date_to, self::TZ)->endOfDay()->utc());
        }

        if ($request->filled('duration_min')) {
            $hasFilters = true;
            $query->where('total_duration', '>=', $request->duration_min);
        }

        if ($request->filled('duration_max')) {
            $hasFilters = true;
            $query->where('total_duration', '<=', $request->duration_max);
        }

        if ($request->filled('who_hangup')) {
            $hasFilters = true;
            $whoHangup = $this->parseMultiSelect($request->who_hangup);
            $query->whereIn('who_hangup', $whoHangup);
        }

        if ($request->filled('has_rating')) {
            $hasFilters = true;
            if ($request->has_rating === 'yes') {
                $query->whereNotNull('ratings_note');
            } else {
                $query->whereNull('ratings_note');
            }
        }

        if ($request->filled('ratings_warning')) {
            $hasFilters = true;
            $query->where('ratings_warning', $request->ratings_warning === 'yes');
        }

        if ($request->filled('ratings_danger')) {
            $hasFilters = true;
            $query->where('ratings_danger', $request->ratings_danger === 'yes');
        }

        if ($request->filled('ratings_not_rated')) {
            $hasFilters = true;
            $query->where('ratings_not_rated', $request->ratings_not_rated === 'yes');
        }

        if ($request->filled('ratings_reviewer')) {
            $hasFilters = true;
            $reviewers = $this->parseMultiSelect($request->ratings_reviewer);
            $query->whereIn('ratings_reviewer', $reviewers);
        }

        if ($request->filled('phone_agent_hangup')) {
            $hasFilters = true;
            $query->where('phone_agent_hangup', $request->phone_agent_hangup === 'yes');
        }

        if ($request->filled('phone_reported_by_agent')) {
            $hasFilters = true;
            $query->where('phone_reported_by_agent', $request->phone_reported_by_agent === 'yes');
        }

        if ($request->filled('phone_cant_provide_service')) {
            $hasFilters = true;
            $query->where('phone_cant_provide_service', $request->phone_cant_provide_service === 'yes');
        }

        // Plateforme : selfhosted = notre serveur ; NULL ou voxnode = Voxnode
        if ($request->filled('platform')) {
            $hasFilters = true;
            if ($request->platform === 'selfhosted') {
                $query->where('platform', 'selfhosted');
            } else {
                $query->where(fn ($q) => $q->whereNull('platform')->orWhere('platform', '!=', 'selfhosted'));
            }
        }

        // Qualité audio (score MOS) : dégradés (< 3,1), mesurés, non mesurés
        if ($request->filled('audio_quality')) {
            $hasFilters = true;
            match ($request->audio_quality) {
                'degraded' => $query->where('audio_quality', '<', 3.1),
                'measured' => $query->whereNotNull('audio_quality'),
                'unmeasured' => $query->whereNull('audio_quality'),
                default => null,
            };
        }

        if ($request->filled('blacklist_id')) {
            $hasFilters = true;
            if ($request->blacklist_id === 'any') {
                $query->whereNotNull('blacklist_id');
            } elseif ($request->blacklist_id === 'none') {
                $query->whereNull('blacklist_id');
            } else {
                $query->where('blacklist_id', (int) $request->blacklist_id);
            }
        }

        return $hasFilters;
    }

    public function data(Request $request): JsonResponse
    {
        $query = Call::query()
            ->with([
                'agent:id,name',
                'callcenter:id,name',
                'blacklist:id,phonenumber',
                'source:id,name,color',
                'phonenumber:id,phonenumber,provider_id,company_id,source_id',
                'phonenumber.provider:id,name,color',
                'phonenumber.company:id,name,color',
                'phonenumber.source:id,name,color'
            ]);

        $hasFilters = $this->applyFilters($request, $query);

        // Tri
        $sortable = ['id', 'called_at', 'total_duration', 'duration_agent', 'payout', 'brand_name', 'agent_name', 'audio_quality', 'caller'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'called_at';
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';

        $perPage = min($request->input('per_page', 50), 1000); // Max 1000 par page

        // Si pas de filtres, utiliser simplePaginate pour éviter le COUNT() coûteux
        if (!$hasFilters) {
            $paginator = $query->orderBy($sort, $dir)->simplePaginate($perPage);
            return response()->json([
                'items' => $paginator->items(),
                'total' => null, // Pas de total si pas de filtres
                'current_page' => $paginator->currentPage(),
                'last_page' => null, // SimplePaginate ne fournit pas last_page
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
            ]);
        }

        // Avec filtres, utiliser paginate normal
        $paginator = $query->orderBy($sort, $dir)->paginate($perPage);
        return response()->json([
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'has_more_pages' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Colonnes exportables : clé => libellé (en-tête CSV).
     * source/provider/company/callcenter/blacklist sont résolus en nom via les relations,
     * avec la même logique d'affichage que la page (source du Call, sinon du Phonenumber).
     */
    private const EXPORT_COLUMNS = [
        'source' => 'Source',
        'provider' => 'Provider',
        'company' => 'Company',
        'voxnode_id' => 'Voxnode ID',
        'caller' => 'Appelant',
        'called' => 'Appelé',
        'total_duration' => 'Durée totale (s)',
        'duration_agent' => 'Durée agent (s)',
        'duration_transfer' => 'Durée transfert (s)',
        'called_at' => 'Date',
        'who_hangup' => 'Qui a raccroché',
        'agent_name' => 'Agent',
        'brand_name' => 'Marque',
        'brand_phonenumber' => 'N° marque',
        'carrier' => 'Opérateur',
        'record_agent' => 'Enregistrement agent',
        'record_transfer' => 'Enregistrement transfert',
        'callcenter' => 'Call center',
        'blacklist' => 'Blacklist',
        'platform' => 'Plateforme',
        'audio_quality' => 'Qualité audio (score)',
    ];

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $allowed = array_keys(self::EXPORT_COLUMNS);
        $columns = $request->filled('columns')
            ? array_values(array_intersect($allowed, $this->parseMultiSelect($request->columns)))
            : $allowed;

        abort_if(empty($columns), 422, 'Aucune colonne valide sélectionnée');

        $query = Call::query()
            ->with([
                'callcenter:id,name',
                'blacklist:id,phonenumber',
                'source:id,name',
                'phonenumber:id,phonenumber,provider_id,company_id,source_id',
                'phonenumber.provider:id,name',
                'phonenumber.company:id,name',
                'phonenumber.source:id,name',
            ]);

        $this->applyFilters($request, $query);

        $filename = sprintf('appels_%s_%s.csv', $request->date_from, $request->date_to);

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_map(fn ($c) => self::EXPORT_COLUMNS[$c], $columns), ';', '"', '');

            $query->chunkById(1000, function ($calls) use ($out, $columns) {
                foreach ($calls as $call) {
                    fputcsv($out, array_map(fn ($c) => match ($c) {
                        'source' => $call->source?->name ?? $call->phonenumber?->source?->name,
                        'provider' => $call->phonenumber?->provider?->name,
                        'company' => $call->phonenumber?->company?->name,
                        'callcenter' => $call->callcenter?->name,
                        'blacklist' => $call->blacklist?->phonenumber,
                        'called_at' => $call->called_at?->format('Y-m-d H:i:s'),
                        'platform' => $call->platform === 'selfhosted' ? 'notre serveur' : 'voxnode',
                        default => $call->{$c},
                    }, $columns), ';', '"', '');
                }

                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'today' => Call::whereBetween('called_at', [today()->startOfDay(), today()->endOfDay()])->count(),
            'this_week' => Call::whereBetween('called_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => Call::whereBetween('called_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'avg_duration_month' => round(Call::whereBetween('called_at', [now()->startOfMonth(), now()->endOfMonth()])->avg('total_duration')),
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        return response()->json(\Cache::remember('calls_filter_options', 3600, function () {
            return [
                'brands' => Call::select('brand_name')
                    ->distinct()
                    ->whereNotNull('brand_name')
                    ->where('brand_name', '!=', '')
                    ->orderBy('brand_name')
                    ->pluck('brand_name'),
                'agents' => Call::select('agent_name')
                    ->distinct()
                    ->whereNotNull('agent_name')
                    ->where('agent_name', '!=', '')
                    ->orderBy('agent_name')
                    ->pluck('agent_name'),
                'callcenters' => \App\Models\Callcenter::select('id', 'name')
                    ->where('enabled', true)
                    ->orderBy('name')
                    ->get(),
                'carriers' => Call::select('carrier')
                    ->distinct()
                    ->whereNotNull('carrier')
                    ->where('carrier', '!=', '')
                    ->pluck('carrier')
                    ->map(function ($code) {
                        return [
                            'value' => $code,
                            'label' => CarrierHelper::getDisplayName($code),
                            'isDefined' => CarrierHelper::isDefined($code),
                        ];
                    })
                    ->sortBy(function ($carrier) {
                        return [
                            $carrier['isDefined'] ? 0 : 1,
                            $carrier['label'],
                        ];
                    })
                    ->values(),
                'providers' => \App\Models\Provider::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($p) => ['value' => $p->id, 'label' => $p->name, 'color' => $p->color])
                    ->values()
                    ->toArray(),
                'companies' => \App\Models\Company::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($c) => ['value' => $c->id, 'label' => $c->name, 'color' => $c->color])
                    ->values()
                    ->toArray(),
                'sources' => \App\Models\Source::select('id', 'name', 'color')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($s) => ['value' => $s->id, 'label' => $s->name, 'color' => $s->color])
                    ->values()
                    ->toArray(),
                'who_hangup' => Call::select('who_hangup')
                    ->distinct()
                    ->whereNotNull('who_hangup')
                    ->where('who_hangup', '!=', '')
                    ->orderBy('who_hangup')
                    ->pluck('who_hangup'),
                'ratings_reviewers' => Call::select('ratings_reviewer')
                    ->distinct()
                    ->whereNotNull('ratings_reviewer')
                    ->where('ratings_reviewer', '!=', '')
                    ->orderBy('ratings_reviewer')
                    ->pluck('ratings_reviewer'),
                'blacklists' => \App\Models\Blacklist::select('id', 'phonenumber')
                    ->orderBy('phonenumber')
                    ->get(),
            ];
        }));
    }

    public function clearFilterCache(): JsonResponse
    {
        \Cache::forget('calls_filter_options');

        return response()->json([
            'success' => true,
            'message' => 'Cache des filtres vidé avec succès',
        ]);
    }
}
