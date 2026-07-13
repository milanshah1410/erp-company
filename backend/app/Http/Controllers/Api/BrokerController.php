<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerRequest;
use App\Http\Resources\BrokerResource;
use App\Http\Resources\BrokerSettlementResource;
use App\Models\Broker;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    public function index(Request $request)
    {
        $query = Broker::query();

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

        return BrokerResource::collection($query->paginate($perPage));
    }

    public function store(BrokerRequest $request)
    {
        $broker = Broker::create($request->validated());

        return (new BrokerResource($broker))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Broker $broker)
    {
        return new BrokerResource($broker);
    }

    public function update(BrokerRequest $request, Broker $broker)
    {
        $broker->update($request->validated());

        return new BrokerResource($broker);
    }

    public function destroy(Broker $broker)
    {
        if ($broker->commissions()->exists() || $broker->settlements()->exists()) {
            return response()->json([
                'message' => 'This broker has commission or settlement history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $broker->delete();

        return response()->json(['message' => 'Broker deleted.']);
    }

    /**
     * Chronological broker ledger: opening balance, then each commission
     * accrual (credit) and settlement payout (debit), with a running balance.
     * The tail balance equals the broker's outstanding (pending) commission.
     */
    public function ledger(Request $request, Broker $broker)
    {
        $commissions = $broker->commissions()
            ->with('invoice:id,invoice_number')
            ->get()
            ->map(fn ($c) => [
                'date' => $c->created_at?->toDateString(),
                'sort' => $c->created_at?->toDateTimeString(),
                'type' => $c->type === 'reversal' ? 'Commission reversal' : 'Commission',
                'reference' => $c->invoice?->invoice_number,
                'credit' => (float) $c->commission_amount,
                'debit' => 0.0,
            ]);

        $settlements = $broker->settlements()
            ->get()
            ->map(fn ($s) => [
                'date' => $s->settlement_date?->toDateString(),
                'sort' => $s->created_at?->toDateTimeString(),
                'type' => 'Settlement ('.$s->mode.')',
                'reference' => $s->settlement_number,
                'credit' => 0.0,
                'debit' => (float) $s->amount,
            ]);

        $opening = (float) $broker->opening_balance;
        $running = $opening;

        $entries = $commissions->concat($settlements)
            ->sortBy('sort')
            ->values()
            ->map(function ($row) use (&$running) {
                $running = round($running + $row['credit'] - $row['debit'], 2);
                $row['balance'] = $running;
                unset($row['sort']);

                return $row;
            })
            ->all();

        $totalEarned = round((float) $broker->commissions()->sum('commission_amount'), 2);
        $totalSettled = round((float) $broker->settlements()->sum('amount'), 2);

        return response()->json([
            'data' => $entries,
            'summary' => [
                'broker' => new BrokerResource($broker),
                'opening_balance' => round($opening, 2),
                'total_earned' => $totalEarned,
                'total_settled' => $totalSettled,
                'pending_commission' => $broker->outstandingCommission(),
            ],
        ]);
    }

    /** Settlement payouts recorded against this broker, newest first. */
    public function settlements(Request $request, Broker $broker)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        return BrokerSettlementResource::collection(
            $broker->settlements()->orderByDesc('id')->paginate($perPage)
        );
    }
}
