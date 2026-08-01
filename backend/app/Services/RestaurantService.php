<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\KitchenOrderTicket;
use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Modules\Industry\FoodBeverage\Services\RecipeConsumptionService;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The floor and the kitchen.
 *
 * Everything here exists because a restaurant order is long-lived. Items arrive
 * in rounds, each round is fired to the stations that have to cook it, and the
 * bill is settled once at the end. Three rules do the real work:
 *
 *  - A table holds one open order. Seating a table that already has one is a
 *    double-booking, not a second ticket.
 *  - A line that has gone to the kitchen is voided, never deleted. The food was
 *    cooked; the loss belongs to somebody and the ticket has to show it.
 *  - Firing splits by station, so the bar never sees the tandoor's dockets.
 */
class RestaurantService
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
        private readonly BrokerCommissionService $brokerCommissions,
        /**
         * Ingredient draw-down on settle, from the Food & Beverage industry
         * plugin. This class is itself F&B domain code that predates the plugin
         * layer, so the dependency is within one vertical rather than core
         * reaching into a plugin — but it does mean the plugin's Models and
         * Services directories are load-bearing for settling a bill.
         */
        private readonly RecipeConsumptionService $recipeConsumption,
    ) {}

    /**
     * Seat a party. The unique index on (tenant, open table) is the real
     * guarantee; this check exists to turn the race into a readable message
     * rather than a constraint violation.
     */
    public function assertTableFree(?int $tableId): void
    {
        if (! $tableId) {
            return;
        }

        $table = RestaurantTable::find($tableId);

        if (! $table) {
            return;
        }

        if (! $table->is_active) {
            throw new RuntimeException("Table {$table->name} is not in service.");
        }

        if (RestaurantOrder::where('restaurant_table_id', $tableId)->where('status', 'open')->exists()) {
            throw new RuntimeException("Table {$table->name} already has an open order.");
        }
    }

    /** Move a table's status, keeping the floor view honest. */
    public function setTableStatus(?int $tableId, string $status): void
    {
        if ($tableId) {
            RestaurantTable::whereKey($tableId)->update(['status' => $status]);
        }
    }

    /**
     * Re-price the ticket from its billable lines.
     *
     * Voided lines are excluded, and the service charge is taken on the
     * discounted food value but *before* tax — which is the order the customer
     * sees it in on an Indian restaurant bill.
     */
    public function reprice(RestaurantOrder $order): RestaurantOrder
    {
        $items = $order->billableItems()->get();

        if ($items->isEmpty()) {
            // Packaging and delivery are deliberately *not* zeroed here: an empty
            // ticket has no food value, and charging for the box around nothing
            // would be the wrong bill. They come back the moment a dish is keyed.
            $order->fill([
                'subtotal' => 0, 'item_discount_total' => 0, 'discount_amount' => 0,
                'service_charge' => 0, 'tax_amount' => 0, 'round_off' => 0, 'total' => 0,
            ])->save();

            return $order;
        }

        $priced = $this->pricing->price(
            $items->map(fn (RestaurantOrderItem $i) => [
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'tax_percent' => (float) $i->tax_percent,
                'discount_type' => $i->discount_type,
                'discount_value' => (float) $i->discount_value,
                '_item_id' => $i->id,
            ])->all(),
            $order->discount_type,
            (float) $order->discount_value,
        );

        foreach ($priced['lines'] as $line) {
            $id = $line['source']['_item_id'] ?? null;
            if (! $id) {
                continue;
            }
            $items->firstWhere('id', $id)?->forceFill([
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ])->save();
        }

        $header = $priced['header'];

        $serviceCharge = round(
            (float) $header['taxable'] * (float) $order->service_charge_percent / 100,
            2,
        );

        /**
         * Parcel and delivery are flat amounts the counter sets, not percentages,
         * and they ride on the bill the same way the service charge does: added to
         * the payable, not folded into the taxable food value. That keeps them
         * consistent with how service charge has always behaved here, and keeps
         * the food's own tax figure honest — a bigger box does not change the GST
         * on a biryani.
         */
        $packaging = round((float) $order->packaging_charge, 2);
        $delivery = round((float) $order->delivery_charge, 2);

        $preRound = (float) $header['taxable']
            + $serviceCharge
            + $packaging
            + $delivery
            + (float) $header['tax_amount'];
        $total = round($preRound, 0);

        $order->fill([
            'subtotal' => $header['subtotal'],
            'item_discount_total' => $header['item_discount_total'],
            'discount_amount' => $header['discount_amount'],
            'service_charge' => $serviceCharge,
            'tax_amount' => $header['tax_amount'],
            'round_off' => round($total - $preRound, 2),
            'total' => $total,
        ])->save();

        return $order;
    }

    /**
     * Fire everything still pending, one KOT per station.
     *
     * Returns the tickets raised. Safe to call twice — a round with nothing
     * pending raises nothing rather than a duplicate docket.
     *
     * @return Collection<int, KitchenOrderTicket>
     */
    public function fire(RestaurantOrder $order, ?int $userId = null): Collection
    {
        $pending = $order->pendingItems()->with('product:id,kitchen_station')->get();

        if ($pending->isEmpty()) {
            return collect();
        }

        // Null station means the main kitchen — a shop that never configured
        // stations still gets one working docket.
        $byStation = $pending->groupBy(fn (RestaurantOrderItem $i) => $i->product?->kitchen_station ?? 'kitchen');

        $tickets = collect();

        foreach ($byStation as $station => $items) {
            $ticket = KitchenOrderTicket::create([
                'kot_number' => KitchenOrderTicket::nextNumber(),
                'restaurant_order_id' => $order->id,
                'station' => in_array($station, KitchenOrderTicket::STATIONS, true) ? $station : 'kitchen',
                'status' => 'pending',
                'fired_at' => now(),
                'created_by' => $userId,
            ]);

            RestaurantOrderItem::whereIn('id', $items->pluck('id'))->update([
                'kitchen_order_ticket_id' => $ticket->id,
                'status' => 'sent',
            ]);

            $tickets->push($ticket);
        }

        return $tickets;
    }

    /**
     * Void a line that has already gone to the kitchen.
     *
     * Kept on the ticket rather than removed: the dish was cooked, and a bill
     * that simply lost the line hides who wore the cost.
     */
    public function voidItem(RestaurantOrderItem $item, string $reason, ?int $userId = null): RestaurantOrderItem
    {
        if ($item->status === 'void') {
            return $item;
        }

        $item->forceFill([
            'status' => 'void',
            'void_reason' => $reason,
        ])->save();

        $this->reprice($item->order);

        return $item;
    }

    /**
     * Settle the ticket: raise the Sales Order + Invoice, free the table.
     *
     * Idempotent — a ticket that already has an invoice returns it untouched,
     * so a double-tap at a busy till cannot bill a table twice.
     */
    public function settle(RestaurantOrder $order, ?int $userId = null): ?Invoice
    {
        if ($order->invoice_id) {
            return $order->invoice()->first();
        }

        if (! $order->isOpen()) {
            throw new RuntimeException("Order {$order->order_number} is not open.");
        }

        $this->reprice($order);
        $order->refresh();

        $items = $order->billableItems()->get();

        if ($items->isEmpty() || (float) $order->total <= 0) {
            throw new RuntimeException("Order {$order->order_number} has nothing to bill.");
        }

        // Anything still sitting with the kitchen is fired first — a table does
        // not leave with food nobody was told to cook.
        $this->fire($order, $userId);

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $order->customer_id ?? $this->walkInCustomerId(),
            'warehouse_id' => $this->fallbackWarehouseId($order),
            'status' => 'delivered',
            'channel' => 'restaurant',
            'order_date' => now()->toDateString(),
            'subtotal' => $order->subtotal,
            'item_discount_total' => $order->item_discount_total,
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'round_off' => $order->round_off,
            'total' => $order->total,
            'notes' => trim(
                "Restaurant order {$order->order_number}"
                .($order->table ? " — table {$order->table->name}" : " — {$order->order_type}"),
            ),
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        foreach ($items as $item) {
            $salesOrder->items()->create([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'tax_percent' => $item->tax_percent,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]);
        }

        /**
         * Service charge, packaging and delivery are all real revenue the customer
         * paid, so each has to be on the document as its own line — the way the
         * bill shows them. Rolling them into the food would misstate both the food
         * total and, at year end, what the shop actually earned on delivery.
         */
        foreach ([
            ['amount' => (float) $order->service_charge, 'sku' => 'SERVICE-CHARGE', 'name' => 'Service Charge', 'note' => 'Restaurant service charge.'],
            ['amount' => (float) $order->packaging_charge, 'sku' => 'PACKAGING-CHARGE', 'name' => 'Packaging Charge', 'note' => 'Parcel / takeaway packaging.'],
            ['amount' => (float) $order->delivery_charge, 'sku' => 'DELIVERY-CHARGE', 'name' => 'Delivery Charge', 'note' => 'Home delivery charge.'],
        ] as $charge) {
            if ($charge['amount'] <= 0) {
                continue;
            }

            $product = $this->chargeProduct($charge['sku'], $charge['name'], $charge['note']);
            $salesOrder->items()->create([
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => 1,
                'unit_price' => $charge['amount'],
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'line_total' => $charge['amount'],
            ]);
        }

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $salesOrder->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $order->total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        /**
         * Move the stock the meal actually ate — ingredients for dishes that have
         * a recipe, the item itself for anything sold as bought.
         *
         * Placed after the invoice-exists guard at the top of this method, so the
         * idempotency that protects against a double-tap at the till protects the
         * draw-down too: a ticket cannot be depleted twice. Inside the caller's
         * transaction, so a failure here rolls the whole settlement back rather
         * than leaving a billed table with unconsumed stock.
         */
        $this->recipeConsumption->consumeForOrder(
            $order,
            (int) $salesOrder->warehouse_id,
            $userId,
        );

        $order->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
            'status' => 'settled',
            'settled_at' => now(),
        ])->save();

        // The table needs wiping before the next party, not seating instantly.
        $this->setTableStatus($order->restaurant_table_id, 'cleaning');

        return $invoice;
    }

    /**
     * The catalogue product a bill-level charge is billed under, created on first
     * use — same reasoning as the service module's labour line: an invoice line
     * needs a product, and a bill cannot wait for someone to create one.
     *
     * Flagged `is_service` so nothing tries to deplete stock for it, which is also
     * what keeps RecipeConsumptionService from looking for a recipe for "Delivery
     * Charge".
     */
    private function chargeProduct(string $sku, string $name, string $description): Product
    {
        return Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'description' => $description,
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    /** Most restaurant bills are anonymous; they still need a customer row. */
    private function walkInCustomerId(): int
    {
        return \App\Models\Customer::firstOrCreate(
            ['code' => 'WALKIN'],
            ['name' => 'Walk-in Customer', 'type' => 'retail', 'is_active' => true],
        )->id;
    }

    private function fallbackWarehouseId(RestaurantOrder $order): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before settling order {$order->order_number}.");
        }

        return (int) $id;
    }
}
