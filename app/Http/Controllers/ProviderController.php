<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Providers');
    }

    public function create(): Response
    {
        return Inertia::render('ProviderForm', [
            'provider' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|max:255',
            'enabled' => 'boolean',
            'config' => 'nullable|array',
            'payout' => 'nullable|numeric',
        ]);

        Provider::create($validated);

        return redirect()->route('providers.index')
            ->with('success', 'Provider créé avec succès.');
    }

    public function edit(Provider $provider): Response
    {
        return Inertia::render('ProviderForm', [
            'provider' => $provider,
        ]);
    }

    public function update(Request $request, Provider $provider)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|max:255',
            'enabled' => 'boolean',
            'config' => 'nullable|array',
            'payout' => 'nullable|numeric',
        ]);

        $provider->update($validated);

        return redirect()->route('providers.index')
            ->with('success', 'Provider modifié avec succès.');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Provider::query();

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('driver', 'like', "%{$search}%");
            });
        }

        if ($request->filled('enabled')) {
            $query->where('enabled', $request->enabled === 'yes');
        }

        // Tri
        $sortable = ['id', 'name', 'driver', 'enabled', 'payout', 'created_at'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'name';
        $dir = $request->dir === 'desc' ? 'desc' : 'asc';

        $perPage = min($request->input('per_page', 50), 100);
        $paginator = $query->orderBy($sort, $dir)->paginate($perPage);

        return response()->json([
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Provider::count(),
            'active' => Provider::where('enabled', true)->count(),
            'inactive' => Provider::where('enabled', false)->count(),
        ]);
    }
}
