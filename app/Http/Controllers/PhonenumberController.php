<?php

namespace App\Http\Controllers;

use App\Jobs\DashboardRoutePhoneNumber;
use App\Models\Company;
use App\Models\Phonenumber;
use App\Models\Provider;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PhonenumberController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Phonenumbers');
    }

    public function create(): Response
    {
        return Inertia::render('PhonenumberForm', [
            'phonenumber' => null,
            'companies' => $this->getCompanies(),
            'providers' => $this->getProviders(),
            'sources' => $this->getSources(),
            'providersCompanies' => $this->getProvidersCompanies(),
            'sourcesProvidersCompanies' => $this->getSourcesProvidersCompanies(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'phonenumber' => ['required', 'string', 'regex:/^\+33[1-9]\d{8}$/'],
            'company_id' => 'required|exists:companies,id',
            'provider_id' => 'required|exists:providers,id',
            'only_source_id' => 'nullable|exists:sources,id',
            'source_id' => 'nullable|exists:sources,id',
            'custom_routing_data' => 'nullable|string|max:255',
            'telon_routing_id' => 'nullable|string|max:255',
            'assigned_at' => 'nullable|date',
            'display_expires_at' => 'nullable|date',
            'real_expires_at' => 'nullable|date',
        ]);

        $phonenumber = Phonenumber::create($validated);

        // Dispatcher le job de routing
        DashboardRoutePhoneNumber::dispatch($phonenumber->id);

        return redirect()->route('phonenumbers.index')
            ->with('success', 'Numéro créé avec succès. Le routing est en cours.');
    }

    public function edit(Phonenumber $phonenumber): Response
    {
        return Inertia::render('PhonenumberForm', [
            'phonenumber' => $phonenumber,
            'companies' => $this->getCompanies(),
            'providers' => $this->getProviders(),
            'sources' => $this->getSources(),
            'providersCompanies' => $this->getProvidersCompanies(),
            'sourcesProvidersCompanies' => $this->getSourcesProvidersCompanies(),
        ]);
    }

    public function update(Request $request, Phonenumber $phonenumber)
    {
        $validated = $request->validate([
            'phonenumber' => ['required', 'string', 'regex:/^\+33[1-9]\d{8}$/'],
            'company_id' => 'required|exists:companies,id',
            'provider_id' => 'required|exists:providers,id',
            'only_source_id' => 'nullable|exists:sources,id',
            'source_id' => 'nullable|exists:sources,id',
            'custom_routing_data' => 'nullable|string|max:255',
            'telon_routing_id' => 'nullable|string|max:255',
            'assigned_at' => 'nullable|date',
            'display_expires_at' => 'nullable|date',
            'real_expires_at' => 'nullable|date',
        ]);

        $phonenumber->update($validated);

        return redirect()->route('phonenumbers.index')
            ->with('success', 'Numéro modifié avec succès.');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Phonenumber::with(['company', 'provider', 'onlySource', 'source']);

        // Inclure les numéros supprimés si demandé
        if ($request->filled('include_deleted') && $request->include_deleted === 'yes') {
            $query->withTrashed();
        } elseif ($request->filled('only_deleted') && $request->only_deleted === 'yes') {
            $query->onlyTrashed();
        }

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('phonenumber', 'like', "%{$search}%");
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }

        if ($request->filled('provider_id')) {
            $query->where('provider_id', (int) $request->provider_id);
        }

        if ($request->filled('only_source_id')) {
            if ($request->only_source_id === 'null') {
                $query->whereNull('only_source_id');
            } else {
                $query->where('only_source_id', (int) $request->only_source_id);
            }
        }

        if ($request->filled('source_id')) {
            if ($request->source_id === 'null') {
                $query->whereNull('source_id');
            } else {
                $query->where('source_id', (int) $request->source_id);
            }
        }

        if ($request->filled('assigned_status')) {
            if ($request->assigned_status === 'assigned') {
                $query->whereNotNull('assigned_at');
            } elseif ($request->assigned_status === 'unassigned') {
                $query->whereNull('assigned_at');
            }
        }

        if ($request->filled('routing_status')) {
            if ($request->routing_status === 'error') {
                $query->whereNotNull('routing_error');
            } elseif ($request->routing_status === 'no_error') {
                $query->whereNull('routing_error');
            }
        }

        // Tri
        $sortable = ['id', 'phonenumber', 'company_id', 'provider_id', 'assigned_at', 'created_at', 'total_assignments', 'total_duration'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'id';
        $dir = $request->dir === 'desc' ? 'desc' : 'asc';

        $perPage = min($request->input('per_page', 50), 1000);
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
            'total' => Phonenumber::count(),
            'active' => Phonenumber::active()->count(),
            'deleted' => Phonenumber::onlyTrashed()->count(),
            'assigned' => Phonenumber::assigned()->count(),
            'unassigned' => Phonenumber::unassigned()->count(),
            'with_routing_error' => Phonenumber::withRoutingError()->count(),
        ]);
    }

    public function hasInvalidRouting(): JsonResponse
    {
        // Check for numbers with explicit routing errors
        $hasErrors = Phonenumber::whereNotNull('routing_error')->exists();

        if (!$hasErrors) {
            // Check for numbers NEVER assigned (no assigned_at) NOT pointing to scr.sip.twilio.com
            $hasErrors = Phonenumber::whereNull('assigned_at')
                ->where(function ($query) {
                    $query->whereNull('current_endpoint')
                          ->orWhere('current_endpoint', '!=', 'scr.sip.twilio.com');
                })
                ->exists();
        }

        if (!$hasErrors) {
            // Check for ASSIGNED numbers (not expired) pointing to scr.sip.twilio.com
            $hasErrors = Phonenumber::whereNotNull('assigned_at')
                ->where(function ($query) {
                    $query->whereNull('real_expires_at')
                          ->orWhere('real_expires_at', '>', now());
                })
                ->where('current_endpoint', 'scr.sip.twilio.com')
                ->exists();
        }

        if (!$hasErrors) {
            // Check for numbers expired for more than 2 minutes but NOT pointing to scr.sip.twilio.com
            $twoMinutesAgo = now()->subMinutes(2);
            $hasErrors = Phonenumber::whereNotNull('real_expires_at')
                ->where('real_expires_at', '<', $twoMinutesAgo)
                ->where(function ($query) {
                    $query->whereNull('current_endpoint')
                          ->orWhere('current_endpoint', '!=', 'scr.sip.twilio.com');
                })
                ->exists();
        }

        return response()->json([
            'has_invalid_routing' => $hasErrors,
        ]);
    }

    // Actions en masse
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
        ]);

        // Séparer les numéros assignés des non-assignés
        $assignedNumbers = Phonenumber::whereIn('id', $validated['ids'])
            ->whereNotNull('assigned_at')
            ->get();

        $unassignedNumbers = Phonenumber::whereIn('id', $validated['ids'])
            ->whereNull('assigned_at')
            ->get();

        // Pour les numéros assignés, on marque will_be_deleted = true
        $markedCount = 0;
        foreach ($assignedNumbers as $number) {
            $number->update(['will_be_deleted' => true]);
            $markedCount++;
        }

        // Pour les numéros non-assignés, on supprime vraiment
        $deletedCount = 0;
        foreach ($unassignedNumbers as $number) {
            $number->delete();
            $deletedCount++;
        }

        $message = [];
        if ($markedCount > 0) {
            $message[] = "{$markedCount} numéro(s) marqué(s) pour suppression";
        }
        if ($deletedCount > 0) {
            $message[] = "{$deletedCount} numéro(s) supprimé(s)";
        }

        return response()->json([
            'success' => true,
            'message' => implode(', ', $message) . '.',
            'marked' => $markedCount,
            'deleted' => $deletedCount,
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
        ]);

        $count = Phonenumber::whereIn('id', $validated['ids'])->withTrashed()->restore();

        return response()->json([
            'success' => true,
            'message' => "{$count} numéro(s) restauré(s) avec succès.",
            'count' => $count,
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
            'updates' => 'required|array',
            'updates.company_id' => 'sometimes|exists:companies,id',
            'updates.provider_id' => 'sometimes|exists:providers,id',
            'updates.only_source_id' => 'sometimes|nullable|exists:sources,id',
            'updates.source_id' => 'sometimes|nullable|exists:sources,id',
        ]);

        $count = Phonenumber::whereIn('id', $validated['ids'])->update($validated['updates']);

        return response()->json([
            'success' => true,
            'message' => "{$count} numéro(s) modifié(s) avec succès.",
            'count' => $count,
        ]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
            'source_id' => 'required|exists:sources,id',
        ]);

        $count = Phonenumber::whereIn('id', $validated['ids'])->update([
            'source_id' => $validated['source_id'],
            'assigned_at' => now(),
            'last_assigned_at' => now(),
        ]);

        Phonenumber::whereIn('id', $validated['ids'])->increment('total_assignments');

        return response()->json([
            'success' => true,
            'message' => "{$count} numéro(s) assigné(s) avec succès.",
            'count' => $count,
        ]);
    }

    public function bulkUpdateSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
            'source_id' => 'nullable|exists:sources,id',
        ]);

        $count = Phonenumber::whereIn('id', $validated['ids'])->update([
            'only_source_id' => $validated['source_id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} numéro(s) modifié(s) avec succès.",
            'count' => $count,
        ]);
    }

    public function manualRoute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:phonenumbers,id',
        ]);

        // Dispatcher les jobs de routing pour tous les numéros
        foreach ($validated['ids'] as $phonenumberId) {
            DashboardRoutePhoneNumber::dispatch($phonenumberId);
        }

        $count = count($validated['ids']);

        return response()->json([
            'success' => true,
            'message' => "{$count} numéro(s) en cours de routing. Le processus se fait en arrière-plan.",
            'count' => $count,
        ]);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|exists:phonenumbers,id',
        ]);

        $phonenumber = Phonenumber::find($validated['id']);
        $phonenumber->update(['will_be_deleted' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Suppression annulée.',
        ]);
    }

    public function forceDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|exists:phonenumbers,id',
        ]);

        $phonenumber = Phonenumber::find($validated['id']);
        $phonenumber->will_be_deleted = false;
        $phonenumber->save();
        $phonenumber->delete();

        return response()->json([
            'success' => true,
            'message' => 'Numéro supprimé.',
        ]);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'numbers' => 'required|array',
            'numbers.*.phonenumber' => 'required|string',
            'numbers.*.company_id' => 'required|exists:companies,id',
            'numbers.*.provider_id' => 'required|exists:providers,id',
            'numbers.*.only_source_id' => 'nullable|exists:sources,id',
        ]);

        $created = 0;
        $skipped = 0;
        $errors = [];
        $processedNumbers = [];
        $createdIds = [];

        // Regex pour valider le format E.164 français uniquement
        $e164Regex = '/^\+33[1-9]\d{8}$/';

        DB::transaction(function () use ($validated, &$created, &$skipped, &$errors, &$processedNumbers, &$createdIds, $e164Regex) {
            foreach ($validated['numbers'] as $index => $number) {
                $phonenumber = $number['phonenumber'];

                // Vérifier le format E.164 français
                if (!preg_match($e164Regex, $phonenumber)) {
                    $skipped++;
                    continue; // Ignorer les numéros mal formatés
                }

                // Vérifier si c'est un doublon dans le lot actuel
                if (in_array($phonenumber, $processedNumbers)) {
                    $skipped++;
                    continue; // Ignorer les doublons dans le lot
                }

                // Vérifier si le numéro existe déjà en base
                if (Phonenumber::where('phonenumber', $phonenumber)->exists()) {
                    $skipped++;
                    continue; // Ignorer les doublons en base
                }

                try {
                    $phonenumberModel = Phonenumber::create($number);
                    $processedNumbers[] = $phonenumber;
                    $createdIds[] = $phonenumberModel->id;
                    $created++;
                } catch (\Exception $e) {
                    $errors[] = "Ligne " . ($index + 1) . ": " . $e->getMessage();
                }
            }
        });

        // Dispatcher les jobs de routing pour tous les numéros créés
        foreach ($createdIds as $phonenumberId) {
            DashboardRoutePhoneNumber::dispatch($phonenumberId);
        }

        return response()->json([
            'success' => count($errors) === 0,
            'message' => "{$created} numéro(s) importé(s) avec succès" . ($skipped > 0 ? ", {$skipped} ignoré(s) (doublons ou format invalide)" : "") . ". Le routing est en cours.",
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        return response()->json([
            'companies' => $this->getCompanies(),
            'providers' => $this->getProviders(),
            'sources' => $this->getSources(),
        ]);
    }

    private function getCompanies(): array
    {
        return Company::orderBy('name')
            ->get()
            ->map(fn($c) => ['value' => (string)$c->id, 'label' => $c->name])
            ->toArray();
    }

    private function getProviders(): array
    {
        return Provider::orderBy('name')
            ->get()
            ->map(fn($p) => ['value' => (string)$p->id, 'label' => $p->name])
            ->toArray();
    }

    private function getSources(): array
    {
        return Source::orderBy('name')
            ->get()
            ->map(fn($s) => ['value' => (string)$s->id, 'label' => $s->name])
            ->toArray();
    }

    private function getProvidersCompanies(): array
    {
        return DB::table('providers_companies')
            ->select('id', 'provider_id', 'company_id')
            ->get()
            ->map(fn($pc) => [
                'id' => (int)$pc->id,
                'provider_id' => (string)$pc->provider_id,
                'company_id' => (string)$pc->company_id,
            ])
            ->toArray();
    }

    private function getSourcesProvidersCompanies(): array
    {
        return DB::table('sources_providers_companies')
            ->select('source_id', 'providers_companies_id')
            ->get()
            ->map(fn($spc) => [
                'source_id' => (string)$spc->source_id,
                'providers_companies_id' => (int)$spc->providers_companies_id,
            ])
            ->toArray();
    }
}
