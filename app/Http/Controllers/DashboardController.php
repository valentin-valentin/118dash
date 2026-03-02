<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * KPI stats shown at the top of the dashboard.
     * Replace with your actual DB queries.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total'  => 0,
            'active' => 0,
            'today'  => 0,
        ]);
    }

    /**
     * Example paginated + filterable table data.
     * Replace with your actual model + query.
     */
    public function example(Request $request): JsonResponse
    {
        // $query = YourModel::query();

        // if ($request->filled('search')) {
        //     $query->where('name', 'like', "%{$request->search}%");
        // }

        // if ($request->filled('status')) {
        //     $query->where('status', $request->status);
        // }

        // $sortable = ['id', 'name', 'created_at'];
        // $sort = in_array($request->sort, $sortable) ? $request->sort : 'id';
        // $dir  = $request->dir === 'asc' ? 'asc' : 'desc';

        // $paginator = $query->orderBy($sort, $dir)->paginate($request->input('per_page', 25));

        // return response()->json([
        //     'items' => $paginator->items(),
        //     'total' => $paginator->total(),
        // ]);

        // ── Placeholder (remove once you wire up real data) ──────────────────
        return response()->json(['items' => [], 'total' => 0]);
    }
}
