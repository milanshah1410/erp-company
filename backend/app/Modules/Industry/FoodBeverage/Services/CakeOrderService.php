<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Modules\Industry\FoodBeverage\Models\CakeOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking a cake, and billing it when it goes out of the door.
 *
 * The document lives for the week between the two — which is the reason it exists
 * at all. Nothing is sold at booking: the shop has taken a deposit against a cake
 * it has not made. The sale is raised at handover, on the 'cake' channel, and
 * carries the advance across as a payment so the customer's ledger shows the ₹500
 * they left on Tuesday against the ₹2,000 invoice they settled on Saturday.
 *
 * Status is the only thing that gates any of this. 'ready' is deliberately not
 * 'delivered': a finished cake waiting in the display fridge for a customer who is
 * late is the normal Saturday afternoon of a bakery, and a shop that had to mark it
 * delivered to get it out of the oven queue would lose track of what it still owes
 * somebody.
 */
class CakeOrderService
{
    /** SKU the bespoke-cake line bills under when the order names no catalogue product. */
    public const PRODUCT_SKU = 'CUSTOM-CAKE';

    /**
     * Book a cake.
     *
     * The customer is optional: most cake orders are taken over a counter from
     * someone who will never be a ledger customer, and forcing a customer row
     * first is how a bakery ends up with two hundred "Walk-in" duplicates. A name
     * and a phone number are enough to make the cake and to ring whoever ordered
     * it — the ledger customer is only needed at handover, and `deliver()` creates
     * one then if there still isn't one.
     *
     * @param  array<string, mixed>  $data
     */
    public function book(array $data, ?int $userId = null): CakeOrder
    {
        return CakeOrder::create([
            'order_number' => CakeOrder::nextNumber(),
            'status' => 'booked',
            'created_by' => $userId,
            ...$data,
        ]);
    }

    /**
     * Move the order along.
     *
     * Cancelling is allowed from any open state — a customer changes their mind,
     * and a bakery needs the slot back. Delivering is not done here: it raises
     * money, so it goes through `deliver()`.
     */
    public function setStatus(CakeOrder $order, string $status): CakeOrder
    {
        if ($status === 'delivered') {
            throw new RuntimeException('Use deliver() to hand a cake over — it raises the bill.');
        }

        if (! in_array($status, ['booked', 'in_production', 'ready', 'cancelled'], true)) {
            throw new RuntimeException("Unknown cake order status '{$status}'.");
        }

        if ($order->status === 'delivered') {
            throw new RuntimeException("Cake order {$order->order_number} has already been billed.");
        }

        if ($status === 'cancelled' && (float) $order->advance_amount > 0) {
            /**
             * Cancelling does not refund. The advance stays on the record and
             * whoever is allowed to give money back does that through Sales — a
             * bakery that could refund from this screen is a bakery where the
             * counter can empty the till.
             */
            $order->notes = trim((string) $order->notes."\nCancelled holding an advance of ".number_format((float) $order->advance_amount, 2));
        }

        $order->forceFill(['status' => $status, 'notes' => $order->notes])->save();

        return $order;
    }

    /**
     * Hand the cake over: raise the SalesOrder + Invoice, carry the advance across.
     *
     * Idempotent — an order that already has an invoice returns it untouched, so a
     * double-tap at a busy Saturday counter cannot bill a cake twice.
     */
    public function deliver(CakeOrder $order, string $advanceMode = 'cash', ?int $userId = null): Invoice
    {
        if ($order->invoice_id) {
            return $order->invoice()->first();
        }

        if ($order->status === 'cancelled') {
            throw new RuntimeException("Cake order {$order->order_number} was cancelled.");
        }

        $payable = $order->payable();

        if ($payable <= 0) {
            throw new RuntimeException("Cake order {$order->order_number} has nothing to bill.");
        }

        return DB::transaction(function () use ($order, $payable, $advanceMode, $userId) {
            $customerId = $order->customer_id ?: $this->customerForWalkIn($order);

            $salesOrder = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $customerId,
                'warehouse_id' => $this->fallbackWarehouseId($order),
                'status' => 'delivered',
                'channel' => 'cake',
                'order_date' => now()->toDateString(),
                'subtotal' => $payable,
                'tax_amount' => 0,
                'total' => $payable,
                'notes' => "Cake order {$order->order_number}"
                    .($order->message_on_cake ? " — \"{$order->message_on_cake}\"" : ''),
                'created_by' => $userId,
                'delivered_at' => now(),
            ]);

            $salesOrder->items()->create([
                'product_id' => $order->product_id ?: $this->cakeProduct()->id,
                'variant_id' => null,
                'quantity' => 1,
                'unit_price' => (float) $order->price,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'line_total' => (float) $order->price,
            ]);

            /**
             * Delivery is charged as its own line, the way the customer sees it on
             * the bill — the same treatment the restaurant gives packaging and
             * delivery. Rolling it into the cake would misstate both the cake's
             * price and what the shop earned on delivering things.
             */
            if ((float) $order->delivery_charge > 0) {
                $delivery = Product::firstOrCreate(
                    ['sku' => 'DELIVERY-CHARGE'],
                    [
                        'name' => 'Delivery Charge',
                        'description' => 'Home delivery charge.',
                        'is_service' => true,
                        'is_active' => true,
                        'cost_price' => 0,
                        'selling_price' => 0,
                        'tax_percent' => 0,
                    ],
                );

                $salesOrder->items()->create([
                    'product_id' => $delivery->id,
                    'variant_id' => null,
                    'quantity' => 1,
                    'unit_price' => (float) $order->delivery_charge,
                    'tax_percent' => 0,
                    'tax_amount' => 0,
                    'line_total' => (float) $order->delivery_charge,
                ]);
            }

            $invoice = Invoice::create([
                'invoice_number' => Invoice::nextNumber(),
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $customerId,
                'invoice_date' => now()->toDateString(),
                'total' => $payable,
                'paid_amount' => 0,
                'status' => 'unpaid',
            ]);

            /**
             * The advance becomes a real payment against the invoice rather than
             * just a smaller balance. It is money the shop already banked, and a
             * cash book that never saw it is a cash book that does not reconcile.
             * Dated to the booking, because that is when it was taken.
             */
            $advance = min((float) $order->advance_amount, $payable);

            if ($advance > 0) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount' => $advance,
                    'mode' => in_array($advanceMode, ['cash', 'card', 'upi'], true) ? $advanceMode : 'cash',
                    'payment_date' => $order->created_at->toDateString(),
                    'notes' => "Advance on cake order {$order->order_number}",
                    'created_by' => $userId,
                ]);

                $invoice->forceFill(['paid_amount' => $advance])->save();
                $invoice->refreshStatus();
                $invoice->save();
            }

            $order->forceFill([
                'customer_id' => $customerId,
                'sales_order_id' => $salesOrder->id,
                'invoice_id' => $invoice->id,
                'status' => 'delivered',
            ])->save();

            return $invoice;
        });
    }

    /**
     * The bakery's board: what is due, soonest first, plus what is late.
     *
     * @return array{due_today: int, due_tomorrow: int, overdue: int, in_production: int, ready: int}
     */
    public function boardCounts(): array
    {
        $open = CakeOrder::query()->open()->get(['status', 'required_at']);

        return [
            'due_today' => $open->filter(fn (CakeOrder $o) => $o->required_at->isToday())->count(),
            'due_tomorrow' => $open->filter(fn (CakeOrder $o) => $o->required_at->isTomorrow())->count(),
            'overdue' => $open->filter(fn (CakeOrder $o) => $o->isOverdue())->count(),
            'in_production' => $open->where('status', 'in_production')->count(),
            'ready' => $open->where('status', 'ready')->count(),
        ];
    }

    /** The catalogue line a bespoke cake bills under, created on first use. */
    private function cakeProduct(): Product
    {
        return Product::firstOrCreate(
            ['sku' => self::PRODUCT_SKU],
            [
                'name' => 'Custom Cake',
                'description' => 'Made-to-order cake, priced per order.',
                // Not a service: it is a cake. But it is never stocked, so it
                // carries no reorder level and nothing tries to deplete it —
                // a bespoke cake's ingredients leave stock through the bakery's
                // production order, not through this line.
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    /**
     * A ledger customer for a counter booking, created at handover.
     *
     * Named from the order rather than pooled into one "Walk-in" row, because by
     * the time a cake is handed over the shop knows who it is for and will want to
     * find them again next birthday.
     */
    private function customerForWalkIn(CakeOrder $order): int
    {
        /**
         * Matched on the phone number when there is one: it is the only stable
         * identity a counter has, and two "Priya"s in a neighbourhood are not the
         * same customer while two orders on one number almost always are.
         */
        if ($order->customer_phone) {
            $existing = Customer::query()->where('phone', $order->customer_phone)->value('id');

            if ($existing) {
                return (int) $existing;
            }
        }

        // `code` is required and unique per tenant, so it is derived from the
        // order that created the customer — traceable, and never a collision.
        return (int) Customer::create([
            'code' => 'C-'.$order->order_number,
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'type' => 'retail',
            'is_active' => true,
        ])->id;
    }

    private function fallbackWarehouseId(CakeOrder $order): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before billing cake order {$order->order_number}.");
        }

        return (int) $id;
    }
}
