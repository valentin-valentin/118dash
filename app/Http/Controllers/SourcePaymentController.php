<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Source;
use App\Models\SourcePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SourcePaymentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Soldes');
    }

    public function data(Request $request): JsonResponse
    {
        $query = SourcePayment::with('source');

        if ($request->filled('source_id')) {
            $ids = is_array($request->source_id)
                ? $request->source_id
                : array_filter(explode(',', (string) $request->source_id), fn ($v) => $v !== '');
            if (!empty($ids)) {
                $query->whereIn('source_id', $ids);
            }
        }

        if ($request->filled('type') && in_array($request->type, [SourcePayment::TYPE_CREDIT, SourcePayment::TYPE_DEBIT], true)) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('description', 'like', "%{$search}%");
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }

        $sortable = ['id', 'created_at', 'amount', 'type'];
        $sort = in_array($request->sort, $sortable, true) ? $request->sort : 'created_at';
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';

        $perPage = min((int) $request->input('per_page', 50), 100);
        $paginator = $query->orderBy($sort, $dir)->paginate($perPage);

        return response()->json([
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        $sources = Source::orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
            ]);

        return response()->json([
            'sources' => $sources,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_id' => 'required|exists:sources,id',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:credit,debit',
            'description' => 'nullable|string|max:255',
        ]);

        $payment = DB::transaction(function () use ($validated) {
            $source = Source::lockForUpdate()->findOrFail($validated['source_id']);

            $delta = $validated['type'] === SourcePayment::TYPE_CREDIT
                ? $validated['amount']
                : -$validated['amount'];

            $source->solde = (float) $source->solde + $delta;
            $source->save();

            return SourcePayment::create([
                'source_id' => $validated['source_id'],
                'amount' => $validated['amount'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
            ]);
        });

        $payment->load('source');

        return response()->json([
            'payment' => $payment,
            'new_solde' => $payment->source->solde,
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_source_id' => 'required|exists:sources,id',
            'to_source_id' => 'required|exists:sources,id|different:from_source_id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $result = DB::transaction(function () use ($validated) {
            $from = Source::lockForUpdate()->findOrFail($validated['from_source_id']);
            $to = Source::lockForUpdate()->findOrFail($validated['to_source_id']);

            $amount = $validated['amount'];
            $baseDescription = $validated['description'] ?? null;

            $debitDescription = trim(sprintf(
                'Transfert vers %s%s',
                $to->name,
                $baseDescription ? ' — ' . $baseDescription : ''
            ));

            $creditDescription = trim(sprintf(
                'Transfert depuis %s%s',
                $from->name,
                $baseDescription ? ' — ' . $baseDescription : ''
            ));

            $from->solde = (float) $from->solde - $amount;
            $from->save();

            $to->solde = (float) $to->solde + $amount;
            $to->save();

            $debit = SourcePayment::create([
                'source_id' => $from->id,
                'amount' => $amount,
                'type' => SourcePayment::TYPE_DEBIT,
                'description' => $debitDescription,
            ]);

            $credit = SourcePayment::create([
                'source_id' => $to->id,
                'amount' => $amount,
                'type' => SourcePayment::TYPE_CREDIT,
                'description' => $creditDescription,
            ]);

            return [
                'debit' => $debit,
                'credit' => $credit,
                'from_solde' => $from->solde,
                'to_solde' => $to->solde,
            ];
        });

        return response()->json($result);
    }

    public function recalculatePreview(Source $source): JsonResponse
    {
        set_time_limit(300);

        $totalPayoutCalls = (float) Call::where('source_id', $source->id)
            ->sum('payout_source');

        $totalCredits = (float) SourcePayment::where('source_id', $source->id)
            ->where('type', SourcePayment::TYPE_CREDIT)
            ->sum('amount');

        $totalDebits = (float) SourcePayment::where('source_id', $source->id)
            ->where('type', SourcePayment::TYPE_DEBIT)
            ->sum('amount');

        $expectedSolde = round($totalPayoutCalls + $totalCredits - $totalDebits, 2);
        $currentSolde = (float) $source->solde;
        $difference = round($expectedSolde - $currentSolde, 2);

        return response()->json([
            'source_id' => $source->id,
            'total_payout_calls' => round($totalPayoutCalls, 2),
            'total_credits' => round($totalCredits, 2),
            'total_debits' => round($totalDebits, 2),
            'expected_solde' => $expectedSolde,
            'current_solde' => round($currentSolde, 2),
            'difference' => $difference,
            'matches' => abs($difference) < 0.01,
        ]);
    }

    public function syncSolde(Source $source): JsonResponse
    {
        set_time_limit(300);

        $totalPayoutCalls = (float) Call::where('source_id', $source->id)
            ->sum('payout_source');

        $totalCredits = (float) SourcePayment::where('source_id', $source->id)
            ->where('type', SourcePayment::TYPE_CREDIT)
            ->sum('amount');

        $totalDebits = (float) SourcePayment::where('source_id', $source->id)
            ->where('type', SourcePayment::TYPE_DEBIT)
            ->sum('amount');

        $expectedSolde = round($totalPayoutCalls + $totalCredits - $totalDebits, 2);

        $source->solde = $expectedSolde;
        $source->save();

        return response()->json([
            'source_id' => $source->id,
            'new_solde' => $expectedSolde,
        ]);
    }
}
