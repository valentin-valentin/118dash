<?php

namespace App\Http\Controllers;

use App\Models\ProviderCompany;
use App\Models\Source;
use App\Models\SourceProviderCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sources');
    }

    public function create(): Response
    {
        return Inertia::render('SourceForm', [
            'source' => null,
            'availableProviderCompanies' => $this->getAvailableProviderCompanies(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_key' => 'required|string|max:255|unique:sources,api_key',
            'fingerprint' => 'boolean',
            'only_dedicated_phonenumber' => 'boolean',
            'color' => 'nullable|string|max:50',
            'max_concurrent_numbers' => 'nullable|integer|min:1',
            'payout_call' => 'nullable|numeric',
            'payout_minute' => 'nullable|numeric',
            'associations' => 'nullable|array',
            'associations.*.providers_companies_id' => 'required|exists:providers_companies,id',
            'associations.*.weight' => 'required|integer|min:1',
            'associations.*.payout' => 'nullable|numeric',
        ]);

        DB::transaction(function () use ($validated) {
            $source = Source::create([
                'name' => $validated['name'],
                'api_key' => $validated['api_key'],
                'fingerprint' => $validated['fingerprint'] ?? false,
                'only_dedicated_phonenumber' => $validated['only_dedicated_phonenumber'] ?? false,
                'color' => $validated['color'] ?? 'cyan',
                'max_concurrent_numbers' => $validated['max_concurrent_numbers'] ?? null,
                'payout_call' => $validated['payout_call'] ?? null,
                'payout_minute' => $validated['payout_minute'] ?? null,
            ]);

            if (!empty($validated['associations'])) {
                foreach ($validated['associations'] as $association) {
                    SourceProviderCompany::create([
                        'source_id' => $source->id,
                        'providers_companies_id' => $association['providers_companies_id'],
                        'weight' => $association['weight'],
                        'payout' => $association['payout'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('sources.index')
            ->with('success', 'Source créée avec succès.');
    }

    public function edit(Source $source): Response
    {
        $source->load(['sourceProviderCompanies.providerCompany.provider', 'sourceProviderCompanies.providerCompany.company']);

        return Inertia::render('SourceForm', [
            'source' => $source,
            'availableProviderCompanies' => $this->getAvailableProviderCompanies(),
        ]);
    }

    public function update(Request $request, Source $source)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_key' => 'required|string|max:255|unique:sources,api_key,' . $source->id,
            'fingerprint' => 'boolean',
            'only_dedicated_phonenumber' => 'boolean',
            'color' => 'nullable|string|max:50',
            'max_concurrent_numbers' => 'nullable|integer|min:1',
            'payout_call' => 'nullable|numeric',
            'payout_minute' => 'nullable|numeric',
            'associations' => 'nullable|array',
            'associations.*.providers_companies_id' => 'required|exists:providers_companies,id',
            'associations.*.weight' => 'required|integer|min:1',
            'associations.*.payout' => 'nullable|numeric',
        ]);

        DB::transaction(function () use ($validated, $source) {
            $source->update([
                'name' => $validated['name'],
                'api_key' => $validated['api_key'],
                'fingerprint' => $validated['fingerprint'] ?? false,
                'only_dedicated_phonenumber' => $validated['only_dedicated_phonenumber'] ?? false,
                'color' => $validated['color'] ?? 'cyan',
                'max_concurrent_numbers' => $validated['max_concurrent_numbers'] ?? null,
                'payout_call' => $validated['payout_call'] ?? null,
                'payout_minute' => $validated['payout_minute'] ?? null,
            ]);

            // Supprimer les anciennes associations
            $source->sourceProviderCompanies()->delete();

            // Recréer les associations
            if (!empty($validated['associations'])) {
                foreach ($validated['associations'] as $association) {
                    SourceProviderCompany::create([
                        'source_id' => $source->id,
                        'providers_companies_id' => $association['providers_companies_id'],
                        'weight' => $association['weight'],
                        'payout' => $association['payout'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('sources.index')
            ->with('success', 'Source modifiée avec succès.');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Source::with(['sourceProviderCompanies.providerCompany.provider', 'sourceProviderCompanies.providerCompany.company']);

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('api_key', 'like', "%{$search}%");
            });
        }

        // Tri
        $sortable = ['id', 'name', 'created_at'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'name';
        $dir = $request->dir === 'desc' ? 'desc' : 'asc';

        $perPage = min($request->input('per_page', 50), 100);
        $paginator = $query->orderBy($sort, $dir)->paginate($perPage);

        // Ajouter le nombre de numéros assignables pour chaque association
        $items = $paginator->items();
        foreach ($items as $source) {
            $totalAssignable = 0;
            foreach ($source->sourceProviderCompanies as $spc) {
                if ($spc->providerCompany) {
                    // Compter les numéros non assignés pour ce couple provider-company
                    $spc->assignable_count = \App\Models\Phonenumber::where('provider_id', $spc->providerCompany->provider_id)
                        ->where('company_id', $spc->providerCompany->company_id)
                        ->whereNull('assigned_at')
                        ->where('will_be_deleted', false)
                        ->count();

                    $totalAssignable += $spc->assignable_count;
                }
            }
            // Total de numéros assignables pour cette source
            $source->total_assignable = $totalAssignable;
        }

        return response()->json([
            'items' => $items,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Source::count(),
            'with_fingerprint' => Source::where('fingerprint', true)->count(),
            'with_dedicated_phonenumber' => Source::where('only_dedicated_phonenumber', true)->count(),
        ]);
    }

    private function getAvailableProviderCompanies(): array
    {
        return ProviderCompany::with(['provider', 'company'])
            ->get()
            ->map(function ($pc) {
                return [
                    'id' => $pc->id,
                    'label' => $pc->provider->name . ' - ' . $pc->company->name,
                    'provider_name' => $pc->provider->name,
                    'company_name' => $pc->company->name,
                    'payout' => $pc->payout,
                ];
            })
            ->toArray();
    }
}
