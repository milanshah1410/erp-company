<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyTransactionResource;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Setting;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

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

    /**
     * The programme screen: every customer holding points, richest first, with
     * the programme totals across the top.
     *
     * Aggregated in SQL rather than by loading customers and summing per row —
     * a counter that has been running points for a year has more members than a
     * page of N+1 queries can survive.
     */
    public function members(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $balances = LoyaltyTransaction::query()
            ->join('customers', 'customers.id', '=', 'loyalty_transactions.customer_id')
            ->groupBy('loyalty_transactions.customer_id', 'customers.name', 'customers.phone')
            ->select([
                'loyalty_transactions.customer_id',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
                DB::raw('SUM(loyalty_transactions.points) as balance'),
                DB::raw("SUM(CASE WHEN loyalty_transactions.points > 0 THEN loyalty_transactions.points ELSE 0 END) as earned"),
                DB::raw("SUM(CASE WHEN loyalty_transactions.points < 0 THEN -loyalty_transactions.points ELSE 0 END) as redeemed"),
                DB::raw('MAX(loyalty_transactions.created_at) as last_activity_at'),
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $balances->where(function ($q) use ($search) {
                $q->where('customers.name', 'like', "%{$search}%")
                    ->orWhere('customers.phone', 'like', "%{$search}%");
            });
        }

        // Members who have spent everything they earned are still members, but a
        // zero row is noise on a screen whose job is "who can redeem today?".
        if ($request->boolean('with_balance_only', true)) {
            $balances->havingRaw('SUM(loyalty_transactions.points) > 0');
        }

        $page = $balances
            ->orderByDesc('balance')
            ->paginate($perPage);

        $page->getCollection()->transform(fn ($row) => [
            'customer_id' => (int) $row->customer_id,
            'customer_name' => $row->customer_name,
            'customer_phone' => $row->customer_phone,
            'balance' => (int) $row->balance,
            'earned' => (int) $row->earned,
            'redeemed' => (int) $row->redeemed,
            'value' => $this->loyalty->valueOf((int) $row->balance),
            'last_activity_at' => $row->last_activity_at,
        ]);

        return response()->json($page->toArray() + [
            'programme' => $this->programmeTotals(),
            'config' => $this->loyalty->config(),
        ]);
    }

    /** The programme's rules, for the settings card and the POS redeem box. */
    public function config()
    {
        return response()->json(['data' => $this->loyalty->config()]);
    }

    /**
     * Programme-wide totals: points outstanding, ever earned, ever redeemed, and
     * what the outstanding balance is worth if every member spent it today.
     */
    private function programmeTotals(): array
    {
        $row = LoyaltyTransaction::query()
            ->selectRaw('SUM(points) as balance')
            ->selectRaw('SUM(CASE WHEN points > 0 THEN points ELSE 0 END) as earned')
            ->selectRaw('SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) as redeemed')
            ->selectRaw('COUNT(DISTINCT customer_id) as members')
            ->first();

        $balance = (int) ($row->balance ?? 0);

        return [
            'members' => (int) ($row->members ?? 0),
            'balance' => $balance,
            'earned' => (int) ($row->earned ?? 0),
            'redeemed' => (int) ($row->redeemed ?? 0),
            'liability' => $this->loyalty->valueOf($balance),
            'currency_symbol' => Setting::currencySymbol(),
        ];
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
