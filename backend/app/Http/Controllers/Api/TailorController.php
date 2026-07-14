<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TailorRequest;
use App\Http\Resources\TailorPaymentResource;
use App\Http\Resources\TailorResource;
use App\Models\Tailor;
use Illuminate\Http\Request;

class TailorController extends Controller
{
    public function index(Request $request)
    {
        $query = Tailor::query()->with('contractor:id,name');

        if ($request->filled('contractor_id')) {
            $query->where('contractor_id', $request->query('contractor_id'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return TailorResource::collection($query->paginate($perPage));
    }

    public function store(TailorRequest $request)
    {
        $tailor = Tailor::create($request->validated());

        return (new TailorResource($tailor))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tailor $tailor)
    {
        return new TailorResource($tailor->load('contractor:id,name'));
    }

    public function update(TailorRequest $request, Tailor $tailor)
    {
        $tailor->update($request->validated());

        return new TailorResource($tailor);
    }

    public function destroy(Tailor $tailor)
    {
        if ($tailor->works()->exists() || $tailor->payments()->exists()) {
            return response()->json([
                'message' => 'This tailor has work or payment history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $tailor->delete();

        return response()->json(['message' => 'Tailor deleted.']);
    }

    /**
     * Chronological tailor ledger: opening balance, then each work completion
     * (credit / earning) and payment (debit / payout), with a running balance.
     * The tail balance equals the tailor's outstanding (unpaid) amount.
     */
    public function ledger(Request $request, Tailor $tailor)
    {
        $completions = $tailor->completions()
            ->with('work:id,work_number')
            ->get()
            ->map(fn ($c) => [
                'date' => $c->completed_at?->toDateString(),
                'sort' => $c->created_at?->toDateTimeString(),
                'type' => 'Work completed',
                'reference' => $c->work?->work_number,
                'credit' => (float) $c->amount,
                'debit' => 0.0,
            ]);

        $payments = $tailor->payments()
            ->get()
            ->map(fn ($p) => [
                'date' => $p->payment_date?->toDateString(),
                'sort' => $p->created_at?->toDateTimeString(),
                'type' => 'Payment ('.$p->mode.')',
                'reference' => $p->payment_number,
                'credit' => 0.0,
                'debit' => (float) $p->amount,
            ]);

        // Approved / paid bonuses are ledger credits (earnings).
        $bonuses = $tailor->bonuses()
            ->whereIn('status', ['approved', 'paid'])
            ->get()
            ->map(fn ($b) => [
                'date' => $b->created_at?->toDateString(),
                'sort' => $b->created_at?->toDateTimeString(),
                'type' => 'Bonus ('.$b->basis.')',
                'reference' => $b->bonus_number,
                'credit' => (float) $b->amount,
                'debit' => 0.0,
            ]);

        $opening = (float) $tailor->opening_balance;
        $running = $opening;

        $entries = $completions->concat($payments)->concat($bonuses)
            ->sortBy('sort')
            ->values()
            ->map(function ($row) use (&$running) {
                $running = round($running + $row['credit'] - $row['debit'], 2);
                $row['balance'] = $running;
                unset($row['sort']);

                return $row;
            })
            ->all();

        return response()->json([
            'data' => $entries,
            'summary' => [
                'tailor' => new TailorResource($tailor),
                'opening_balance' => round($opening, 2),
                'total_earned' => $tailor->totalEarned(),
                'total_paid' => $tailor->totalPaid(),
                'outstanding_balance' => $tailor->outstandingBalance(),
            ],
        ]);
    }

    /** Payments recorded against this tailor, newest first. */
    public function payments(Request $request, Tailor $tailor)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        return TailorPaymentResource::collection(
            $tailor->payments()->orderByDesc('id')->paginate($perPage)
        );
    }
}
