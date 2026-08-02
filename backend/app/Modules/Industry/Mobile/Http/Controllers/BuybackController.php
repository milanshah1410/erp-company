<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Modules\Industry\Mobile\Http\Requests\BuybackRequest;
use App\Modules\Industry\Mobile\Models\Buyback;
use App\Modules\Industry\Mobile\Services\BuybackSettlementService;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Part-exchange: the old handset taken in against the new one.
 *
 * The register's shape follows the trade's own sequence. A valuation is quoted over
 * the counter and most quotes die there. The ones that do not turn into the shop
 * holding somebody's phone and owing them its value — an open credit, which is why
 * `accepted` is a state of its own and settlement is a separate act. A customer
 * commonly sells today and buys on the next visit.
 *
 * Settlement writes a `buyback` tender against the bill rather than a discount on it,
 * so the new phone bills at the price on the shelf. See BuybackSettlementService for
 * why that matters.
 */
class BuybackController extends Controller
{
    public function __construct(private readonly BuybackSettlementService $settlement) {}

    public function index(Request $request)
    {
        $query = Buyback::query()->with([
            'customer:id,name,phone',
            'invoice:id,invoice_number,invoice_date',
            'stockProduct:id,sku,name',
            'stockWarehouse:id,name',
        ]);

        if ($search = $request->query('search')) {
            $digits = Imei::normalize((string) $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('buyback_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model_name', 'like', "%{$search}%")
                    ->orWhere('device_serial', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if ($digits) {
                    $q->orWhere('imei', 'like', "%{$digits}%");
                }
            });
        }

        foreach (['status', 'grade', 'customer_id', 'brand'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Handsets the shop is holding whose value it still owes — the ledger a manager
        // checks, because every row is a liability sitting in a drawer.
        if ($request->boolean('open_credit')) {
            $query->openCredit();
        }

        // Taken in and never put anywhere: stock the shop owns and cannot sell, because
        // nobody decided what it is.
        if ($request->boolean('unstocked')) {
            $query->whereIn('status', ['accepted', 'settled'])->whereNull('taken_into_stock_at');
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Buyback $b) => $this->row($b))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(Buyback $buyback)
    {
        $buyback->load([
            'customer:id,name,phone', 'invoice:id,invoice_number,invoice_date,total',
            'payment:id,amount,mode,payment_date',
            'stockProduct:id,sku,name', 'stockWarehouse:id,name',
        ]);

        return response()->json(['data' => $this->row($buyback, detailed: true)]);
    }

    public function store(BuybackRequest $request)
    {
        $data = $request->validated();

        $buyback = Buyback::create($data + [
            'buyback_number' => Buyback::nextNumber(),
            'quoted_on' => $data['quoted_on'] ?? now()->toDateString(),
            'status' => 'quoted',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->row($buyback->fresh(['customer'])),
            'imei_valid' => $buyback->imei === null ? null : Imei::isValid($buyback->imei),
        ], 201);
    }

    /**
     * Re-value a quote.
     *
     * Refused once the handset has been taken in: at that point the figure is what the
     * customer was paid or is owed, and revising it after the fact rewrites a debt. A
     * re-grade before acceptance is ordinary, and this is where it happens.
     */
    public function update(BuybackRequest $request, Buyback $buyback)
    {
        if ($buyback->status !== 'quoted') {
            return response()->json([
                'message' => 'This handset has already been taken in, so its valuation can no longer be changed.',
            ], 422);
        }

        $buyback->update($request->validated());

        return response()->json(['data' => $this->row($buyback->fresh(['customer']))]);
    }

    /**
     * Move a quote along.
     *
     * `accepted` is the one that matters: the customer has handed the phone over and
     * the shop now owes them its value. It needs a customer on it, because a credit
     * owed to nobody in particular is a credit nobody can claim.
     */
    public function progress(Request $request, Buyback $buyback)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['quoted', 'accepted', 'rejected', 'expired'])],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($buyback->isSettled()) {
            throw ValidationException::withMessages([
                'status' => ['This trade-in has been set off against a bill and can no longer be moved.'],
            ]);
        }

        $customerId = $data['customer_id'] ?? $buyback->customer_id;

        if ($data['status'] === 'accepted' && ! $customerId) {
            throw ValidationException::withMessages([
                'customer_id' => [
                    'Name the customer. The shop is taking their handset and owing them its value — that credit has to belong to somebody.',
                ],
            ]);
        }

        /**
         * Walking an already-stocked handset back to a quote would leave stock on the
         * shelf that the register says was never taken in.
         */
        if ($buyback->isInStock() && $data['status'] !== 'accepted') {
            throw ValidationException::withMessages([
                'status' => ['This handset is already in stock. Its intake cannot be undone from here.'],
            ]);
        }

        $buyback->forceFill([
            'status' => $data['status'],
            'customer_id' => $customerId,
            'accepted_on' => $data['status'] === 'accepted'
                ? ($buyback->accepted_on ?? now()->toDateString())
                : $buyback->accepted_on,
            'notes' => isset($data['reason'])
                ? trim((string) $buyback->notes."\n".ucfirst($data['status']).': '.$data['reason'])
                : $buyback->notes,
        ])->save();

        return response()->json(['data' => $this->row($buyback->fresh(['customer']))]);
    }

    /** Set the credit off against a bill. */
    public function settle(Request $request, Buyback $buyback)
    {
        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);

        try {
            $this->settlement->settle(
                $buyback,
                Invoice::findOrFail($data['invoice_id']),
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['invoice_id' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($buyback->fresh(['customer', 'invoice', 'payment']), detailed: true),
        ]);
    }

    /** Put the handset on the shelf, booked in at what the shop allowed for it. */
    public function takeIntoStock(Request $request, Buyback $buyback)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
        ]);

        try {
            $this->settlement->takeIntoStock(
                $buyback,
                Product::findOrFail($data['product_id']),
                (int) $data['warehouse_id'],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['product_id' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($buyback->fresh(['customer', 'stockProduct', 'stockWarehouse']), detailed: true),
        ]);
    }

    public function destroy(Buyback $buyback)
    {
        if (! in_array($buyback->status, ['quoted', 'rejected', 'expired'], true)) {
            return response()->json([
                'message' => 'This handset has been taken in. A trade-in the shop is holding cannot be deleted from the register.',
            ], 422);
        }

        $buyback->delete();

        return response()->json(['message' => 'Valuation deleted.']);
    }

    private function summary(): array
    {
        $rows = Buyback::query()->get([
            'id', 'status', 'offered_value', 'bonus_value', 'invoice_id', 'taken_into_stock_at',
        ]);

        return [
            'quoted' => $rows->where('status', 'quoted')->count(),
            'accepted' => $rows->where('status', 'accepted')->count(),
            'settled' => $rows->where('status', 'settled')->count(),
            // What the shop is holding and has not yet paid for in goods: the liability
            // sitting in the drawer.
            'open_credit' => round((float) $rows
                ->where('status', 'accepted')
                ->whereNull('invoice_id')
                ->sum('offered_value'), 2),
            // Bonus money the brand owes the shop back.
            'bonus_recoverable' => round((float) $rows
                ->whereIn('status', ['accepted', 'settled'])
                ->sum('bonus_value'), 2),
            'awaiting_stock' => $rows
                ->whereIn('status', ['accepted', 'settled'])
                ->whereNull('taken_into_stock_at')
                ->count(),
        ];
    }

    private function row(Buyback $b, bool $detailed = false): array
    {
        $base = [
            'id' => $b->id,
            'buyback_number' => $b->buyback_number,
            'customer_id' => $b->customer_id,
            'customer' => $b->customer?->name,
            'brand' => $b->brand,
            'model_name' => $b->model_name,
            'label' => $b->label(),
            'imei' => $b->imei,
            'storage' => $b->storage,
            'colour' => $b->colour,
            'age_months' => $b->age_months,
            'grade' => $b->grade,
            'base_value' => (float) $b->base_value,
            'deduction' => (float) $b->deduction,
            'bonus_value' => (float) $b->bonus_value,
            'offered_value' => (float) $b->offered_value,
            'status' => $b->status,
            'quoted_on' => $b->quoted_on?->toDateString(),
            'valid_until' => $b->valid_until?->toDateString(),
            // A quote past its date is not a live offer, and used-handset prices move
            // weekly.
            'is_live' => $b->isLive(),
            'is_settled' => $b->isSettled(),
            'in_stock' => $b->isInStock(),
            'invoice_number' => $b->invoice?->invoice_number,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'device_serial' => $b->device_serial,
            'defects' => $b->defects,
            'has_box' => (bool) $b->has_box,
            'has_charger' => (bool) $b->has_charger,
            'has_bill' => (bool) $b->has_bill,
            'accepted_on' => $b->accepted_on?->toDateString(),
            'settled_on' => $b->settled_on?->toDateString(),
            'invoice_id' => $b->invoice_id,
            'payment_id' => $b->payment_id,
            'stock_product_id' => $b->stock_product_id,
            'stock_product' => $b->stockProduct?->name,
            'stock_warehouse' => $b->stockWarehouse?->name,
            'taken_into_stock_at' => $b->taken_into_stock_at?->toIso8601String(),
            'notes' => $b->notes,
        ];
    }
}
