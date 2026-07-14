<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyTransactionResource;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    /** Per-customer points ledger (newest first) with a balance summary. */
    public function index(Request $request, Customer $customer)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $transactions = $customer->loyaltyTransactions()
            ->with('invoice:id,invoice_number')
            ->orderByDesc('id')
            ->paginate($perPage);

        return LoyaltyTransactionResource::collection($transactions)
            ->additional(['summary' => $this->summary($customer)]);
    }

    /**
     * Record a points movement. earn/adjust credit the balance; redeem/expire
     * debit it. Points are always submitted as a positive magnitude and the
     * sign is applied here. Redemptions cannot exceed the current balance.
     */
    public function store(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:earn,redeem,adjust,expire'],
            'points' => ['required', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $magnitude = (int) $validated['points'];
        $isCredit = in_array($validated['type'], LoyaltyTransaction::CREDIT_TYPES, true);
        $signed = $isCredit ? $magnitude : -$magnitude;

        if (! $isCredit && $magnitude > $customer->loyaltyPoints()) {
            return response()->json([
                'message' => 'Insufficient points balance for this redemption.',
            ], 422);
        }

        $transaction = $customer->loyaltyTransactions()->create([
            'type' => $validated['type'],
            'points' => $signed,
            'invoice_id' => $validated['invoice_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return (new LoyaltyTransactionResource($transaction->load('invoice')))
            ->additional(['summary' => $this->summary($customer)])
            ->response()
            ->setStatusCode(201);
    }

    /** Aggregate earned / redeemed / current balance for a customer. */
    private function summary(Customer $customer): array
    {
        $rows = $customer->loyaltyTransactions()
            ->selectRaw('type, SUM(points) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'customer_id' => $customer->id,
            'balance' => (int) $rows->sum(),
            'earned' => (int) ($rows['earn'] ?? 0),
            'redeemed' => (int) abs($rows['redeem'] ?? 0),
            'adjusted' => (int) ($rows['adjust'] ?? 0),
            'expired' => (int) abs($rows['expire'] ?? 0),
        ];
    }
}
