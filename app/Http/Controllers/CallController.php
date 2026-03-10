<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CallController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Calls');
    }

    public function show(Call $call): Response
    {
        $call->load(['agent', 'callcenter', 'individualSearch', 'packageTracking']);

        return Inertia::render('CallDetails', [
            'call' => $call,
        ]);
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

    public function data(Request $request): JsonResponse
    {
        $query = Call::query()
            ->with(['agent:id,name', 'callcenter:id,name', 'blacklist:id,name']);

        // Détecter si des filtres sont actifs
        $hasFilters = false;

        // Filtres
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
            $query->whereIn('callcenter_id', $callcenters);
        }

        if ($request->filled('carrier')) {
            $hasFilters = true;
            $carriers = $this->parseMultiSelect($request->carrier);
            $query->whereIn('carrier', $carriers);
        }

        if ($request->filled('date_from')) {
            $hasFilters = true;
            $query->whereDate('called_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $hasFilters = true;
            $query->whereDate('called_at', '<=', $request->date_to);
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

        if ($request->filled('blacklist_id')) {
            $hasFilters = true;
            if ($request->blacklist_id === 'any') {
                $query->whereNotNull('blacklist_id');
            } elseif ($request->blacklist_id === 'none') {
                $query->whereNull('blacklist_id');
            } else {
                $query->where('blacklist_id', $request->blacklist_id);
            }
        }

        // Tri
        $sortable = ['id', 'called_at', 'total_duration', 'duration_agent', 'payout', 'brand_name', 'agent_name'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'called_at';
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';

        $perPage = min($request->input('per_page', 50), 100); // Max 100 par page

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
                    ->orderBy('carrier')
                    ->pluck('carrier'),
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
