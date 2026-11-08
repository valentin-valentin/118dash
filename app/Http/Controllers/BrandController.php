<?php

namespace App\Http\Controllers;

use App\Models\Call;

use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marques : les entreprises vers lesquelles les agents transfèrent (table brands, lue par le poste des agents).
 * La vérification des numéros de transfert est sur une page à part (BrandCheckController), comme l'audit RSVA des numéros.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Brands', ['types' => $this->types()]);
    }

    public function create(): Response
    {
        return Inertia::render('BrandForm', ['brand' => null, 'types' => $this->types()]);
    }

    public function store(Request $request)
    {
        Brand::create($this->validated($request));

        return redirect()->route('brands.index')->with('success', 'Marque créée.');
    }

    public function edit(Brand $brand): Response
    {
        return Inertia::render('BrandForm', ['brand' => $brand, 'types' => $this->types()]);
    }

    public function update(Request $request, Brand $brand)
    {
        $brand->update($this->validated($request));

        return redirect()->route('brands.index')->with('success', 'Marque modifiée.');
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'country' => 'nullable|in:FR,BE,DE',
            // Numéro de transfert au format international : +33…, +32…
            'phonenumber' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'note' => 'nullable|string',
            'scenario' => 'nullable|string',
            'image' => 'nullable|url|max:255',
            'icon' => 'nullable|url|max:255',
            'enabled' => 'boolean',
            'can_transfer' => 'boolean',
            'can_offer_callback' => 'boolean',
            'transfer_announcement' => 'boolean',
        ], [
            'phonenumber.regex' => 'Numéro au format international, par exemple +33123456789.',
        ]);

        return $validated;
    }

    private function types(): array
    {
        return Brand::query()->whereNotNull('type')->where('type', '!=', '')->distinct()->orderBy('type')->pluck('type')->all();
    }

    public function data(Request $request): JsonResponse
    {
        $query = Brand::query();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%");
                // « 01 23 45 », « 0123… » ou « +33123… » : on compare les chiffres sans le 0 initial
                if (strlen($digits) >= 4) {
                    $q->orWhere('phonenumber', 'like', '%' . ltrim(preg_replace('/^(33|32|49)/', '', $digits), '0') . '%');
                }
            });
        }
        foreach (['enabled', 'can_transfer', 'transfer_announcement'] as $flag) {
            if ($request->filled($flag)) {
                $query->where($flag, $request->input($flag) === 'yes');
            }
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }
        match ($request->input('phonenumber')) {
            'yes' => $query->whereNotNull('phonenumber')->where('phonenumber', '!=', ''),
            'no' => $query->where(fn ($q) => $q->whereNull('phonenumber')->orWhere('phonenumber', '')),
            default => null,
        };

        $sortable = ['id', 'name', 'type', 'country', 'enabled', 'can_transfer', 'updated_at'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'name';
        $dir = $request->dir === 'desc' ? 'desc' : 'asc';
        $perPage = min((int) $request->input('per_page', 50), 500);
        $paginator = $query->orderBy($sort, $dir)->paginate($perPage);

        // Transferts des 30 derniers jours vers chaque marque de la page (nom de marque enregistré sur l'appel)
        $names = collect($paginator->items())->pluck('name')->all();
        $transfers = Call::excludeTests(DB::table('calls'))
            ->where('called_at', '>=', now()->subDays(30))
            ->whereIn('brand_name', $names)
            ->where('duration_transfer', '>', 0)
            ->groupBy('brand_name')
            ->selectRaw('brand_name, COUNT(*) AS n')
            ->pluck('n', 'brand_name');

        return response()->json([
            'items' => collect($paginator->items())->map(fn ($b) => [...$b->toArray(), 'transfers_30d' => (int) ($transfers[$b->name] ?? 0)]),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function stats(): JsonResponse
    {
        $row = DB::table('brands')->selectRaw("
            COUNT(*) AS total,
            SUM(enabled = 1) AS active,
            SUM(enabled = 0) AS inactive,
            SUM(phonenumber IS NULL OR phonenumber = '') AS without_number
        ")->first();

        return response()->json(array_map('intval', (array) $row));
    }
}
