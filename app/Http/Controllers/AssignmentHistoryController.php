<?php

namespace App\Http\Controllers;

use App\Models\AssignmentHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentHistoryController extends Controller
{
    /**
     * Display the assignment history page.
     */
    public function index(): Response
    {
        return Inertia::render('AssignmentHistory');
    }

    /**
     * Get stats for assignment history.
     */
    public function stats(): JsonResponse
    {
        $total = AssignmentHistory::count();
        $today = AssignmentHistory::whereDate('start_at', today())->count();
        $thisWeek = AssignmentHistory::whereBetween('start_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ])->count();

        return response()->json([
            'total' => $total,
            'today' => $today,
            'this_week' => $thisWeek,
        ]);
    }

    /**
     * Get paginated assignment history data with filters.
     */
    public function data(Request $request): JsonResponse
    {
        $query = AssignmentHistory::query()
            ->with([
                'phonenumber:id,phonenumber',
                'source:id,name',
                'company:id,name',
                'provider:id,name',
            ]);

        // Filter by phonenumber search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('phonenumber', function ($q) use ($search) {
                $q->where('phonenumber', 'like', "%{$search}%");
            });
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->whereDate('start_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('start_at', '<=', $request->end_date);
        }

        // Filter by source
        if ($request->filled('source_id')) {
            $query->where('source_id', (int) $request->source_id);
        }

        // Filter by company
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }

        // Filter by provider
        if ($request->filled('provider_id')) {
            $query->where('provider_id', (int) $request->provider_id);
        }

        // Sort
        $sortField = $request->get('sort', 'start_at');
        $sortDir = $request->get('dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Paginate
        $perPage = $request->get('per_page', 50);
        $items = $query->paginate($perPage);

        return response()->json([
            'items' => $items->items(),
            'total' => $items->total(),
            'per_page' => $items->perPage(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
        ]);
    }

    /**
     * Get filter options for sources, companies, and providers.
     */
    public function filterOptions(): JsonResponse
    {
        $sources = \App\Models\Source::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($s) => ['value' => $s->id, 'label' => $s->name]);

        $companies = \App\Models\Company::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => ['value' => $c->id, 'label' => $c->name]);

        $providers = \App\Models\Provider::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($p) => ['value' => $p->id, 'label' => $p->name]);

        return response()->json([
            'sources' => $sources,
            'companies' => $companies,
            'providers' => $providers,
        ]);
    }
}
