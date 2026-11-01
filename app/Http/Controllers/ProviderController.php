<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'color' => 'nullable|string|max:50',
            'sip_number_format' => 'required|in:e164_no_plus,e164,local',
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
            'color' => 'nullable|string|max:50',
            'sip_number_format' => 'required|in:e164_no_plus,e164,local',
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

        // Ajouter le nombre total de numéros pour chaque provider
        $items = $paginator->items();
        foreach ($items as $provider) {
            $provider->total_phonenumbers = \App\Models\Phonenumber::where('provider_id', $provider->id)->count();
        }

        return response()->json([
            'items' => $items,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    /**
     * « Routé chez nous » : providers.config $.selfhosted, lu par voxnode118 pour envoyer les numéros
     * de ce fournisseur sur notre serveur SIP. JSON_SET ne touche à aucune autre clé de la configuration.
     */
    public function setSelfhosted(Request $request, Provider $provider): JsonResponse
    {
        $enabled = $request->validate(['selfhosted' => 'required|boolean'])['selfhosted'];

        DB::update(
            "UPDATE providers SET config = JSON_SET(COALESCE(NULLIF(config, ''), '{}'), '$.selfhosted', JSON_EXTRACT(?, '$.v')), updated_at = ? WHERE id = ?",
            [json_encode(['v' => (bool) $enabled]), now(), $provider->id],
        );

        Log::info('[Fournisseurs] ' . $provider->name . ' : routé chez nous = ' . ($enabled ? 'oui' : 'non'), ['user' => auth()->user()?->email]);

        return response()->json(['id' => $provider->id, 'selfhosted' => (bool) $enabled]);
    }

    /**
     * Fournisseurs dont les numéros peuvent venir sur notre serveur (page Serveur SIP).
     */
    public function selfhostedList(): JsonResponse
    {
        return response()->json(
            Provider::orderBy('name')->get(['id', 'name', 'enabled', 'color', 'config'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'enabled' => (bool) $p->enabled, 'color' => $p->color, 'selfhosted' => (bool) ($p->config['selfhosted'] ?? false)])
                ->values(),
        );
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
