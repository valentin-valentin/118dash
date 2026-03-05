<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Provider;
use App\Models\ProviderCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Companies');
    }

    public function create(): Response
    {
        return Inertia::render('CompanyForm', [
            'company' => null,
            'availableProviders' => $this->getAvailableProviders(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'enabled' => 'boolean',
            'color' => 'nullable|string|max:50',
            'providers' => 'nullable|array',
            'providers.*.provider_id' => 'required|exists:providers,id',
            'providers.*.payout' => 'nullable|numeric',
        ]);

        DB::transaction(function () use ($validated) {
            $company = Company::create([
                'name' => $validated['name'],
                'enabled' => $validated['enabled'] ?? true,
                'color' => $validated['color'] ?? 'green',
            ]);

            if (!empty($validated['providers'])) {
                foreach ($validated['providers'] as $provider) {
                    ProviderCompany::create([
                        'company_id' => $company->id,
                        'provider_id' => $provider['provider_id'],
                        'payout' => $provider['payout'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('companies.index')
            ->with('success', 'Company créée avec succès.');
    }

    public function edit(Company $company): Response
    {
        $company->load(['providerCompanies.provider']);

        return Inertia::render('CompanyForm', [
            'company' => $company,
            'availableProviders' => $this->getAvailableProviders(),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'enabled' => 'boolean',
            'color' => 'nullable|string|max:50',
            'providers' => 'nullable|array',
            'providers.*.provider_id' => 'required|exists:providers,id',
            'providers.*.payout' => 'nullable|numeric',
        ]);

        DB::transaction(function () use ($validated, $company) {
            $company->update([
                'name' => $validated['name'],
                'enabled' => $validated['enabled'] ?? true,
                'color' => $validated['color'] ?? 'green',
            ]);

            // Récupérer les IDs des providers soumis
            $submittedProviderIds = collect($validated['providers'] ?? [])->pluck('provider_id')->toArray();

            // Mettre à jour ou créer les associations
            if (!empty($validated['providers'])) {
                foreach ($validated['providers'] as $provider) {
                    ProviderCompany::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'provider_id' => $provider['provider_id'],
                        ],
                        [
                            'payout' => $provider['payout'] ?? null,
                        ]
                    );
                }
            }

            // Supprimer les associations qui ne sont plus dans la liste
            // ET qui ne sont pas utilisées par des sources
            $company->providerCompanies()
                ->whereNotIn('provider_id', $submittedProviderIds)
                ->whereDoesntHave('sourceProviderCompanies')
                ->delete();
        });

        return redirect()->route('companies.index')
            ->with('success', 'Company modifiée avec succès.');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Company::with(['providerCompanies.provider']);

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('enabled')) {
            $query->where('enabled', $request->enabled === 'yes');
        }

        // Tri
        $sortable = ['id', 'name', 'enabled', 'created_at'];
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
            'total' => Company::count(),
            'active' => Company::where('enabled', true)->count(),
            'inactive' => Company::where('enabled', false)->count(),
        ]);
    }

    private function getAvailableProviders(): array
    {
        return Provider::orderBy('name')
            ->get()
            ->map(function ($provider) {
                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'enabled' => $provider->enabled,
                ];
            })
            ->toArray();
    }
}
