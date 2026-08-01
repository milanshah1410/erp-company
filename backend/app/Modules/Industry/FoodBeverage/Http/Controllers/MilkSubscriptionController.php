<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\FoodBeverage\Http\Requests\MilkSubscriptionRequest;
use App\Modules\Industry\FoodBeverage\Models\MilkSubscription;
use App\Modules\Industry\FoodBeverage\Models\MilkSubscriptionDelivery;
use App\Modules\Industry\FoodBeverage\Services\MilkSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Standing orders, the daily round sheet, and the month-end bill.
 *
 * The three are one screen's worth of work in a dairy office: the standing orders
 * are edited rarely, the sheet is walked every morning, and the bill is raised once
 * a month from what the sheet recorded. Keeping them on one controller is what keeps
 * the invariant visible — the bill is built from the deliveries, never from the
 * standing order.
 */
class MilkSubscriptionController extends Controller
{
    public function __construct(private readonly MilkSubscriptionService $milk) {}

    /*
    |--------------------------------------------------------------------------
    | Standing orders
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $query = MilkSubscription::query()
            ->with(['customer:id,code,name,phone,address', 'product:id,sku,name', 'route:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subscription_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'shift', 'delivery_route_id', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        $query->orderByRaw("FIELD(status, 'active', 'paused', 'cancelled')")->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (MilkSubscription $s) => $this->row($s))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function store(MilkSubscriptionRequest $request)
    {
        $data = $request->validated();

        $subscription = MilkSubscription::create([
            'subscription_number' => MilkSubscription::nextNumber(),
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'created_by' => $request->user()?->id,
            ...$data,
        ]);

        return response()->json([
            'data' => $this->row($subscription->fresh(['customer', 'product', 'route'])),
        ], 201);
    }

    public function update(MilkSubscriptionRequest $request, MilkSubscription $milkSubscription)
    {
        $data = $request->validated();

        $milkSubscription->update([
            ...$data,
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
        ]);

        return response()->json([
            'data' => $this->row($milkSubscription->fresh(['customer', 'product', 'route'])),
        ]);
    }

    public function destroy(MilkSubscription $milkSubscription)
    {
        /**
         * A subscription with deliveries is cancelled, not deleted: those rows are
         * either already billed or about to be, and cascading them away would take
         * a month's revenue with them.
         */
        if ($milkSubscription->deliveries()->exists()) {
            $milkSubscription->update(['status' => 'cancelled']);

            return response()->json([
                'message' => 'This subscription has delivery history, so it has been cancelled instead of deleted.',
            ]);
        }

        $milkSubscription->delete();

        return response()->json(['message' => 'Subscription deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | The round
    |--------------------------------------------------------------------------
    */

    /** Write today's sheet, pre-filled from the standing orders. */
    public function generateRound(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'delivery_route_id' => ['nullable', 'exists:delivery_routes,id'],
        ]);

        $created = $this->milk->generateRound(
            $data['date'] ?? null,
            isset($data['delivery_route_id']) ? (int) $data['delivery_route_id'] : null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => $created === 0
                ? 'Nothing new to add — the sheet was already written for that day.'
                : "{$created} doorsteps added to the sheet.",
            'data' => ['created' => $created],
        ]);
    }

    /** The sheet itself, in walking order. */
    public function roundSheet(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'delivery_route_id' => ['nullable', 'exists:delivery_routes,id'],
            'shift' => ['nullable', Rule::in(['morning', 'evening'])],
        ]);

        $rows = $this->milk->roundSheet(
            $data['date'] ?? null,
            isset($data['delivery_route_id']) ? (int) $data['delivery_route_id'] : null,
            $data['shift'] ?? null,
        );

        return response()->json([
            'data' => $rows->map(fn (MilkSubscriptionDelivery $d) => [
                'id' => $d->id,
                'subscription_id' => $d->milk_subscription_id,
                'subscription_number' => $d->subscription?->subscription_number,
                'customer' => $d->subscription?->customer?->name,
                'phone' => $d->subscription?->customer?->phone,
                'address' => $d->subscription?->customer?->address,
                'product' => $d->subscription?->product?->name,
                'delivery_date' => $d->delivery_date?->toDateString(),
                'shift' => $d->shift,
                'quantity' => (float) $d->quantity,
                'rate' => (float) $d->rate,
                'amount' => (float) $d->amount,
                'status' => $d->status,
                'is_billed' => $d->invoice_id !== null,
                'remarks' => $d->remarks,
            ])->values(),
            'totals' => [
                'doorsteps' => $rows->count(),
                'litres' => round((float) $rows->sum('quantity'), 3),
                'amount' => round((float) $rows->sum('amount'), 2),
                'skipped' => $rows->where('status', 'skipped')->count(),
            ],
        ]);
    }

    /** Correct one doorstep — what was actually left, or that it was skipped. */
    public function recordDelivery(Request $request, MilkSubscriptionDelivery $delivery)
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000'],
            'status' => ['required', Rule::in(['delivered', 'skipped', 'extra'])],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $row = $this->milk->record(
                $delivery,
                (float) $data['quantity'],
                $data['status'],
                isset($data['rate']) ? (float) $data['rate'] : null,
                $data['remarks'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $row->id,
                'quantity' => (float) $row->quantity,
                'rate' => (float) $row->rate,
                'amount' => (float) $row->amount,
                'status' => $row->status,
            ],
        ]);
    }

    /**
     * Bill a period — one customer, or everybody with unbilled deliveries.
     *
     * The whole-period run is the month-end button. It is safe to press twice: only
     * unbilled rows are picked up.
     */
    public function bill(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'exists:customers,id'],
        ]);

        if (isset($data['customer_id'])) {
            $invoice = $this->milk->bill(
                (int) $data['customer_id'],
                $data['from'],
                $data['to'],
                $request->user()?->id,
            );

            if (! $invoice) {
                return response()->json([
                    'message' => 'Nothing unbilled for this customer in that period.',
                ], 422);
            }

            return response()->json([
                'message' => "Billed as invoice {$invoice->invoice_number}.",
                'data' => [
                    'invoices' => 1,
                    'amount' => (float) $invoice->total,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
            ]);
        }

        $result = $this->milk->billPeriod($data['from'], $data['to'], $request->user()?->id);

        return response()->json([
            'message' => $result['invoices'] === 0
                ? 'Nothing unbilled in that period.'
                : "{$result['invoices']} invoices raised.",
            'data' => $result,
        ]);
    }

    private function summary(): array
    {
        $subs = MilkSubscription::query()->get(['status', 'quantity_per_day', 'rate', 'shift']);
        $active = $subs->where('status', 'active');

        return [
            'total' => $subs->count(),
            'active' => $active->count(),
            'paused' => $subs->where('status', 'paused')->count(),
            /**
             * Daily litres and daily revenue at full delivery — the dairy's
             * planning number, which is why it counts a 'both' subscription twice.
             */
            'daily_litres' => round($active->sum(
                fn (MilkSubscription $s) => (float) $s->quantity_per_day * count($s->shifts()),
            ), 3),
            'daily_value' => round($active->sum(
                fn (MilkSubscription $s) => (float) $s->quantity_per_day * (float) $s->rate * count($s->shifts()),
            ), 2),
            'unbilled_amount' => round(
                (float) MilkSubscriptionDelivery::query()->billable()->sum('amount'),
                2,
            ),
        ];
    }

    private function row(MilkSubscription $s): array
    {
        return [
            'id' => $s->id,
            'subscription_number' => $s->subscription_number,
            'customer_id' => $s->customer_id,
            'customer' => $s->customer?->name,
            'phone' => $s->customer?->phone,
            'address' => $s->customer?->address,
            'product_id' => $s->product_id,
            'product' => $s->product?->name,
            'variant_id' => $s->variant_id,
            'quantity_per_day' => (float) $s->quantity_per_day,
            'rate' => (float) $s->rate,
            'shift' => $s->shift,
            'days' => array_map('intval', $s->days ?? []),
            'delivery_route_id' => $s->delivery_route_id,
            'route' => $s->route?->name,
            'start_date' => $s->start_date?->toDateString(),
            'end_date' => $s->end_date?->toDateString(),
            'status' => $s->status,
            'due_today' => $s->isDueOn(now()),
            'daily_value' => round((float) $s->quantity_per_day * (float) $s->rate * count($s->shifts()), 2),
            'notes' => $s->notes,
        ];
    }
}
