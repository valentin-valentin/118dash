<?php

namespace App\Http\Controllers;

use App\Models\Blacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlacklistController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Blacklists');
    }

    public function create(): Response
    {
        return Inertia::render('BlacklistForm', [
            'blacklist' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'phonenumber' => ['required', 'string', 'regex:/^\+33[1-9]\d{8}$/'],
            'source' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        Blacklist::create([
            'phonenumber' => $validated['phonenumber'],
            'source' => $validated['source'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        return redirect()->route('blacklists.index')
            ->with('success', 'Blacklist créée avec succès.');
    }

    public function edit(Blacklist $blacklist): Response
    {
        return Inertia::render('BlacklistForm', [
            'blacklist' => $blacklist,
        ]);
    }

    public function update(Request $request, Blacklist $blacklist)
    {
        $validated = $request->validate([
            'phonenumber' => ['required', 'string', 'regex:/^\+33[1-9]\d{8}$/'],
            'source' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        $blacklist->update([
            'phonenumber' => $validated['phonenumber'],
            'source' => $validated['source'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        return redirect()->route('blacklists.index')
            ->with('success', 'Blacklist modifiée avec succès.');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Blacklist::query();

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phonenumber', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        // Tri
        $sortable = ['id', 'phonenumber', 'source', 'created_at', 'updated_at'];
        $sort = in_array($request->sort, $sortable) ? $request->sort : 'phonenumber';
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
            'total' => Blacklist::count(),
        ]);
    }
}
