<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Modules\Industry\FoodBeverage\Models\MilkSubscription;
use App\Modules\Industry\FoodBeverage\Models\MilkSubscriptionDelivery;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The milk round: what is due at each door today, what was actually left, and the
 * bill at the end of the month.
 *
 * The standing order and the deliveries are separate on purpose, and this service
 * only ever bills from the deliveries. A month billed from the instruction is a
 * month of arguments — the family was away for a fortnight, the boy missed a
 * Tuesday — so the sheet is generated from the instruction, corrected by whoever
 * walked the round, and the invoice is built from the corrections.
 *
 * Every step is idempotent. A round sheet generated twice does not double the
 * doorstep, and a month billed twice does not double the bill: the day's rows are
 * keyed on (subscription, date, shift), and billing only picks up rows that have no
 * invoice yet.
 */
class MilkSubscriptionService
{
    /** Ledger movement for litres going out on the round. */
    public const MOVEMENT = 'milk_delivery';

    public function __construct(private readonly StockService $stock) {}

    /**
     * Write today's round sheet: one row per door, per shift, pre-filled with the
     * standing quantity.
     *
     * Pre-filling is the point. A round of two hundred houses cannot be keyed from
     * nothing every morning — the boy corrects the exceptions and leaves the rest,
     * which is also why an untouched row counts as delivered.
     *
     * @return int Rows created (rows that already existed are left exactly as they are).
     */
    public function generateRound(?string $onDate = null, ?int $routeId = null, ?int $userId = null): int
    {
        $date = Carbon::parse($onDate ?: now()->toDateString())->startOfDay();

        $subscriptions = MilkSubscription::query()
            ->active()
            ->when($routeId, fn ($q) => $q->where('delivery_route_id', $routeId))
            ->get();

        $created = 0;

        foreach ($subscriptions as $subscription) {
            if (! $subscription->isDueOn($date)) {
                continue;
            }

            foreach ($subscription->shifts() as $shift) {
                /**
                 * firstOrCreate against the day-unique index, so re-running the
                 * generator — which a dairy will, when a route is added
                 * mid-morning — cannot overwrite a correction somebody has
                 * already keyed.
                 */
                $row = MilkSubscriptionDelivery::firstOrCreate(
                    [
                        'milk_subscription_id' => $subscription->id,
                        'delivery_date' => $date->toDateString(),
                        'shift' => $shift,
                    ],
                    [
                        'quantity' => $subscription->quantity_per_day,
                        'rate' => $subscription->rate,
                        'amount' => round(
                            (float) $subscription->quantity_per_day * (float) $subscription->rate,
                            2,
                        ),
                        'status' => 'delivered',
                        'recorded_by' => $userId,
                    ],
                );

                $created += $row->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * Correct one doorstep.
     *
     * The rate is not re-read from the subscription: a row keeps the rate it was
     * written with, so a mid-month revision bills the days before it at the old
     * rate and the days after at the new one. Only an explicit rate argument
     * changes it.
     */
    public function record(
        MilkSubscriptionDelivery $delivery,
        float $quantity,
        string $status = 'delivered',
        ?float $rate = null,
        ?string $remarks = null,
        ?int $userId = null,
    ): MilkSubscriptionDelivery {
        if ($delivery->invoice_id) {
            throw new RuntimeException(
                'This delivery has already been billed — raise a credit note instead of editing it.',
            );
        }

        if (! in_array($status, ['delivered', 'skipped', 'extra'], true)) {
            throw new RuntimeException("Unknown delivery status '{$status}'.");
        }

        $delivery->forceFill([
            'quantity' => $status === 'skipped' ? 0 : max(0, $quantity),
            'rate' => $rate !== null ? $rate : $delivery->rate,
            'status' => $status,
            'remarks' => $remarks,
            'recorded_by' => $userId,
        ]);

        $delivery->recompute()->save();

        return $delivery;
    }

    /**
     * Bill a customer's period: one invoice over the month's delivered rows.
     *
     * Returns null when there is nothing unbilled — which is the answer a
     * month-end run wants for a household that was away all month, rather than a
     * zero-value invoice nobody can do anything with.
     */
    public function bill(
        int $customerId,
        string $fromDate,
        string $toDate,
        ?int $userId = null,
    ): ?Invoice {
        $rows = MilkSubscriptionDelivery::query()
            ->billable()
            ->whereBetween('delivery_date', [$fromDate, $toDate])
            ->whereHas('subscription', fn ($q) => $q->where('customer_id', $customerId))
            ->with('subscription:id,customer_id,product_id,variant_id,subscription_number')
            ->orderBy('delivery_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = round((float) $rows->sum('amount'), 2);

        if ($total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($rows, $customerId, $fromDate, $toDate, $total, $userId) {
            $warehouseId = $this->fallbackWarehouseId();

            $salesOrder = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'status' => 'delivered',
                'channel' => 'milk',
                'order_date' => $toDate,
                'subtotal' => $total,
                'tax_amount' => 0,
                'total' => $total,
                'notes' => "Milk supply {$fromDate} to {$toDate} — {$rows->count()} deliveries",
                'created_by' => $userId,
                'delivered_at' => now(),
            ]);

            /**
             * One line per product, not per day. A household's bill reads "31
             * litres of toned milk", not thirty-one lines of one litre — and the
             * daily detail is still on the delivery rows for anyone who asks.
             */
            foreach ($this->linesByProduct($rows) as $line) {
                $salesOrder->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_percent' => 0,
                    'tax_amount' => 0,
                    'line_total' => $line['amount'],
                ]);

                $this->stock->outward(
                    warehouseId: $warehouseId,
                    productId: $line['product_id'],
                    variantId: $line['variant_id'],
                    quantity: $line['quantity'],
                    type: self::MOVEMENT,
                    referenceType: SalesOrder::class,
                    referenceId: (int) $salesOrder->id,
                    referenceNumber: $salesOrder->so_number,
                    userId: $userId,
                    /**
                     * A dairy's doorstep milk has already left the building by the
                     * time the month is billed. Refusing to invoice because the
                     * stock ledger disagrees would hold up the money for a
                     * bookkeeping problem — the negative balance is the visible
                     * signal to go and fix the collections.
                     */
                    allowNegative: true,
                );
            }

            $invoice = Invoice::create([
                'invoice_number' => Invoice::nextNumber(),
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $customerId,
                'invoice_date' => $toDate,
                'total' => $total,
                'paid_amount' => 0,
                'status' => 'unpaid',
            ]);

            // Stamping the invoice on the rows is what makes this idempotent.
            MilkSubscriptionDelivery::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Bill every household with unbilled deliveries in a period.
     *
     * @return array{invoices: int, amount: float, customers: int}
     */
    public function billPeriod(string $fromDate, string $toDate, ?int $userId = null): array
    {
        $customerIds = MilkSubscriptionDelivery::query()
            ->billable()
            ->whereBetween('delivery_date', [$fromDate, $toDate])
            ->with('subscription:id,customer_id')
            ->get()
            ->pluck('subscription.customer_id')
            ->filter()
            ->unique()
            ->values();

        $invoices = 0;
        $amount = 0.0;

        foreach ($customerIds as $customerId) {
            $invoice = $this->bill((int) $customerId, $fromDate, $toDate, $userId);

            if ($invoice) {
                $invoices++;
                $amount += (float) $invoice->total;
            }
        }

        return [
            'invoices' => $invoices,
            'amount' => round($amount, 2),
            'customers' => $customerIds->count(),
        ];
    }

    /**
     * Aggregate the period's rows into one line per product/variant.
     *
     * The unit price is the litre-weighted average of the rates actually charged,
     * so a bill whose rate changed mid-month still multiplies out to the same
     * total as the days it was built from.
     *
     * @param  Collection<int, MilkSubscriptionDelivery>  $rows
     * @return list<array{product_id: int, variant_id: int|null, quantity: float, unit_price: float, amount: float}>
     */
    private function linesByProduct(Collection $rows): array
    {
        $grouped = $rows->groupBy(fn (MilkSubscriptionDelivery $r) => implode(':', [
            (int) $r->subscription->product_id,
            (int) ($r->subscription->variant_id ?? 0),
        ]));

        $out = [];

        foreach ($grouped as $key => $group) {
            [$productId, $variantId] = array_map('intval', explode(':', (string) $key));

            $quantity = round((float) $group->sum('quantity'), 3);
            $amount = round((float) $group->sum('amount'), 2);

            $out[] = [
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'quantity' => $quantity,
                'unit_price' => $quantity > 0 ? round($amount / $quantity, 2) : 0.0,
                'amount' => $amount,
            ];
        }

        return $out;
    }

    /**
     * Today's round sheet for a route, in walking order.
     *
     * @return Collection<int, MilkSubscriptionDelivery>
     */
    public function roundSheet(?string $onDate = null, ?int $routeId = null, ?string $shift = null): Collection
    {
        return MilkSubscriptionDelivery::query()
            ->whereDate('delivery_date', $onDate ?: now()->toDateString())
            ->when($shift, fn ($q) => $q->where('shift', $shift))
            ->whereHas(
                'subscription',
                fn ($q) => $q->when($routeId, fn ($qq) => $qq->where('delivery_route_id', $routeId)),
            )
            ->with([
                'subscription:id,subscription_number,customer_id,product_id,delivery_route_id',
                'subscription.customer:id,name,phone,address',
                'subscription.product:id,name',
            ])
            ->orderBy('shift')
            ->orderBy('id')
            ->get();
    }

    private function fallbackWarehouseId(): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException('Create a warehouse before billing milk subscriptions.');
        }

        return (int) $id;
    }
}
