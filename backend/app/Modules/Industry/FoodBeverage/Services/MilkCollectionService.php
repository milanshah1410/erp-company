<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Modules\Industry\FoodBeverage\Models\MilkCollection;
use App\Modules\Industry\FoodBeverage\Models\MilkRateChart;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The morning and evening round: milk in, and what the dairy owes for it.
 *
 * Two decisions carry this whole feature.
 *
 * The farmer is a *supplier*. So a fortnight's collections settle into the same
 * payables ledger as a feed bill, the ageing report includes farmers, and none of
 * the accounting is written twice. What the collection adds is the quality reading
 * — fat, SNF, CLR — that a supplier ledger has no vocabulary for.
 *
 * The litres are *stock*. They arrive off the round and leave on the delivery
 * boy's cycle, so a collection posts inward and a subscription delivery posts
 * outward, both on their own movement types. A dairy that could not see its own
 * closing balance of milk would be an inventory system that stopped at the door.
 */
class MilkCollectionService
{
    /** Ledger movement for litres arriving off the round. */
    public const MOVEMENT = 'milk_collect';

    /** SKU of the raw-milk product collections are received against. */
    public const PRODUCT_SKU = 'RAW-MILK';

    public function __construct(private readonly StockService $stock) {}

    /**
     * Record one can.
     *
     * Prices it from the chart in force unless the counter has overridden the rate
     * — which happens, and is exactly the kind of thing that must be possible and
     * visible rather than impossible and worked around on paper.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, ?int $userId = null): MilkCollection
    {
        return DB::transaction(function () use ($data, $userId) {
            $collection = new MilkCollection([
                'collection_number' => MilkCollection::nextNumber(),
                'created_by' => $userId,
                ...$data,
            ]);

            $override = isset($data['rate_per_litre']) && (float) $data['rate_per_litre'] > 0;

            if (! $override) {
                $chart = isset($data['milk_rate_chart_id'])
                    ? MilkRateChart::find($data['milk_rate_chart_id'])
                    : MilkRateChart::inForce(
                        (string) ($data['milk_type'] ?? 'cow'),
                        (string) ($data['collection_date'] ?? now()->toDateString()),
                    );

                if (! $chart) {
                    throw new RuntimeException(
                        'No milk rate chart is in force — create one, or key the rate on the entry.',
                    );
                }

                $collection->applyRate($chart);
            } else {
                $collection->amount = round(
                    (float) $collection->quantity_litres * (float) $collection->rate_per_litre,
                    2,
                );
            }

            $collection->save();

            $this->receiveStock($collection, $userId);

            return $collection;
        });
    }

    /**
     * Bring the litres into the chilling centre's stock.
     *
     * Costed at the rate the farmer was paid, so the dairy's milk stock is valued
     * at what it actually cost rather than at a list price nobody paid.
     */
    private function receiveStock(MilkCollection $collection, ?int $userId): void
    {
        $quantity = (float) $collection->quantity_litres;

        if ($quantity <= 0) {
            return;
        }

        $this->stock->inward(
            warehouseId: $this->fallbackWarehouseId(),
            productId: $this->milkProduct()->id,
            variantId: null,
            quantity: $quantity,
            unitCost: (float) $collection->rate_per_litre,
            type: self::MOVEMENT,
            referenceType: MilkCollection::class,
            referenceId: (int) $collection->id,
            referenceNumber: $collection->collection_number,
            userId: $userId,
        );
    }

    /**
     * Settle a farmer's period: one purchase invoice over many collections.
     *
     * A dairy pays its farmers for a fortnight as one bill, so this raises one
     * document across the period's unsettled rows rather than a payment per can.
     *
     * The purchase order it hangs off is a formality the schema requires — a
     * purchase invoice belongs to an order — and it is created already 'received'
     * with no GRN, because the milk was received at the counter, one can at a time,
     * and posting it inward again here would double the dairy's stock.
     *
     * Idempotent by construction: only rows with no purchase invoice are picked up,
     * so running the fortnight twice bills the second half of it, not all of it
     * again.
     */
    public function settle(
        Supplier $farmer,
        string $fromDate,
        string $toDate,
        ?int $userId = null,
    ): ?PurchaseInvoice {
        $collections = MilkCollection::query()
            ->where('supplier_id', $farmer->id)
            ->unsettled()
            ->whereBetween('collection_date', [$fromDate, $toDate])
            ->orderBy('collection_date')
            ->get();

        if ($collections->isEmpty()) {
            return null;
        }

        $litres = round((float) $collections->sum('quantity_litres'), 3);
        $amount = round((float) $collections->sum('amount'), 2);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($farmer, $collections, $litres, $amount, $fromDate, $toDate, $userId) {
            $product = $this->milkProduct();
            $warehouseId = $this->fallbackWarehouseId();

            $order = PurchaseOrder::create([
                'po_number' => PurchaseOrder::nextNumber(),
                'supplier_id' => $farmer->id,
                'warehouse_id' => $warehouseId,
                // Already received: see the note above. The milk came in at the
                // counter, so this document exists to carry the money, not to
                // expect a delivery.
                'status' => 'received',
                'order_date' => $toDate,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total' => $amount,
                'notes' => "Milk collection {$fromDate} to {$toDate} — {$collections->count()} collections, {$litres} L",
                'created_by' => $userId,
            ]);

            $item = $order->items()->create([
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => $litres,
                'received_quantity' => $litres,
                // Weighted average across the period, which is what the farmer's
                // slip shows as their rate for the fortnight.
                'unit_cost' => $litres > 0 ? round($amount / $litres, 4) : 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'line_total' => $amount,
            ]);

            $invoice = PurchaseInvoice::create([
                'invoice_number' => 'MILK-'.$fromDate.'-'.$toDate,
                'purchase_order_id' => $order->id,
                'supplier_id' => $farmer->id,
                'invoice_date' => $toDate,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total' => $amount,
                'status' => 'approved',
                'match_status' => 'matched',
                'notes' => "Milk purchased from {$farmer->name}, {$fromDate} to {$toDate}.",
                'created_by' => $userId,
            ]);

            $invoice->items()->create([
                'purchase_item_id' => $item->id,
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => $litres,
                'unit_cost' => $litres > 0 ? round($amount / $litres, 4) : 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'line_total' => $amount,
            ]);

            MilkCollection::query()
                ->whereIn('id', $collections->pluck('id'))
                ->update([
                    'purchase_invoice_id' => $invoice->id,
                    'settled_at' => now(),
                ]);

            return $invoice;
        });
    }

    /**
     * Farmer-wise payable for a period — the slip a collection centre prints.
     *
     * @return list<array{
     *     supplier_id: int, supplier: string, collections: int, litres: float,
     *     avg_fat: float, avg_snf: float, amount: float, unsettled: float
     * }>
     */
    public function farmerSummary(string $fromDate, string $toDate): array
    {
        $rows = MilkCollection::query()
            ->with('supplier:id,name')
            ->whereBetween('collection_date', [$fromDate, $toDate])
            ->get();

        return $rows->groupBy('supplier_id')->map(function ($group) {
            $litres = round((float) $group->sum('quantity_litres'), 3);

            return [
                'supplier_id' => (int) $group->first()->supplier_id,
                'supplier' => (string) ($group->first()->supplier?->name ?? '—'),
                'collections' => $group->count(),
                'litres' => $litres,
                /**
                 * Litre-weighted, not a mean of the percentages: a 40-litre can at
                 * 6% fat and a 2-litre can at 3% do not average to 4.5%, and a
                 * dairy paying on the unweighted number pays the wrong amount.
                 */
                'avg_fat' => $litres > 0 ? round($group->sum(
                    fn (MilkCollection $c) => (float) $c->quantity_litres * (float) $c->fat_percent,
                ) / $litres, 2) : 0.0,
                'avg_snf' => $litres > 0 ? round($group->sum(
                    fn (MilkCollection $c) => (float) $c->quantity_litres * (float) $c->snf_percent,
                ) / $litres, 2) : 0.0,
                'amount' => round((float) $group->sum('amount'), 2),
                'unsettled' => round((float) $group->whereNull('purchase_invoice_id')->sum('amount'), 2),
            ];
        })->sortByDesc('amount')->values()->all();
    }

    /** The product raw milk is stocked as, created on first collection. */
    public function milkProduct(): Product
    {
        return Product::firstOrCreate(
            ['sku' => self::PRODUCT_SKU],
            [
                'name' => 'Raw Milk',
                'description' => 'Milk received from farmers, in litres.',
                'is_service' => false,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    private function fallbackWarehouseId(): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException('Create a warehouse before recording milk collections.');
        }

        return (int) $id;
    }
}
