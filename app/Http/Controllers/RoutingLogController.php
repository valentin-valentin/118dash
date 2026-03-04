<?php

namespace App\Http\Controllers;

use App\Models\RoutingLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoutingLogController extends Controller
{
    /**
     * Display the routing logs page.
     */
    public function index(): Response
    {
        return Inertia::render('RoutingLogs');
    }

    /**
     * Get routing logs data.
     */
    public function data(Request $request): JsonResponse
    {
        $query = RoutingLog::with(['phonenumber', 'source', 'company', 'provider'])
            ->orderBy($request->get('sort', 'created_at'), $request->get('dir', 'desc'));

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('job_id', 'like', "%{$search}%")
                    ->orWhere('endpoint', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhereHas('phonenumber', function ($q) use ($search) {
                        $q->where('phonenumber', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by source
        if ($sourceId = $request->get('source_id')) {
            $query->where('source_id', $sourceId);
        }

        // Filter by company
        if ($companyId = $request->get('company_id')) {
            $query->where('company_id', $companyId);
        }

        // Filter by provider
        if ($providerId = $request->get('provider_id')) {
            $query->where('provider_id', $providerId);
        }

        // Date range
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $items = $query->limit(100)->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'job_id' => $log->job_id,
                'phonenumber' => $log->phonenumber?->phonenumber,
                'source' => $log->source?->name,
                'company' => $log->company?->name,
                'provider' => $log->provider?->name,
                'endpoint' => $log->endpoint,
                'status' => $log->status,
                'reason' => $log->reason,
                'message' => $log->message,
                'duration_ms' => $log->duration_ms,
                'attempt' => $log->attempt,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'is_error' => $log->isError(),
            ];
        });

        return response()->json([
            'items' => $items,
            'total' => $query->count(),
        ]);
    }

    /**
     * Get routing log details.
     */
    public function show(RoutingLog $routingLog): JsonResponse
    {
        $routingLog->load(['phonenumber', 'source', 'company', 'provider']);

        return response()->json([
            'id' => $routingLog->id,
            'job_id' => $routingLog->job_id,
            'phonenumber' => [
                'id' => $routingLog->phonenumber?->id,
                'phonenumber' => $routingLog->phonenumber?->phonenumber,
            ],
            'source' => [
                'id' => $routingLog->source?->id,
                'name' => $routingLog->source?->name,
            ],
            'company' => [
                'id' => $routingLog->company?->id,
                'name' => $routingLog->company?->name,
            ],
            'provider' => [
                'id' => $routingLog->provider?->id,
                'name' => $routingLog->provider?->name,
            ],
            'endpoint' => $routingLog->endpoint,
            'status' => $routingLog->status,
            'reason' => $routingLog->reason,
            'message' => $routingLog->message,
            'duration_ms' => $routingLog->duration_ms,
            'attempt' => $routingLog->attempt,
            'request_data' => $routingLog->request_data,
            'response_data' => $routingLog->response_data,
            'created_at' => $routingLog->created_at?->format('Y-m-d H:i:s'),
            'is_error' => $routingLog->isError(),
        ]);
    }

    /**
     * Get routing logs statistics.
     */
    public function stats(): JsonResponse
    {
        $total = RoutingLog::count();
        $errors = RoutingLog::whereIn('status', ['error', 'failed', 'failure'])->count();
        $success = RoutingLog::where('status', 'success')->count();
        $today = RoutingLog::whereDate('created_at', today())->count();

        return response()->json([
            'total' => $total,
            'errors' => $errors,
            'success' => $success,
            'today' => $today,
        ]);
    }

    /**
     * Get filter options for routing logs.
     */
    public function filterOptions(): JsonResponse
    {
        $sources = \App\Models\Source::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($s) => ['value' => (string)$s->id, 'label' => $s->name]);

        $companies = \App\Models\Company::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => ['value' => (string)$c->id, 'label' => $c->name]);

        $providers = \App\Models\Provider::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn($p) => ['value' => (string)$p->id, 'label' => $p->name]);

        $statuses = RoutingLog::distinct()
            ->pluck('status')
            ->filter()
            ->map(fn($s) => ['value' => $s, 'label' => ucfirst($s)])
            ->values();

        return response()->json([
            'sources' => $sources,
            'companies' => $companies,
            'providers' => $providers,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Check if there are recent errors (for sidebar badge).
     */
    public function hasErrors(): JsonResponse
    {
        $hasErrors = RoutingLog::whereIn('status', ['error', 'failed', 'failure'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        return response()->json(['has_errors' => $hasErrors]);
    }
}
