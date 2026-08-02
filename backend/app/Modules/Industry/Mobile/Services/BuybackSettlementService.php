<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Modules\Industry\Mobile\Models\Buyback;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a trade-in into money and, if the shop wants it, into stock.
 *
 * Settlement writes a `buyback` tender against the new bill rather than a discount
 * on it. That is the choice `old_gold_receipts` made, for the same reasons: the new
 * handset is billed at the price on the shelf so the sale is comparable with every
 * other sale of that model, GST is charged on the real consideration, and what the
 * shop allowed for the old phone stays visible as its own figure instead of
 * dissolving into a discount percentage nobody can account for at month end.
 *
 * Taking the handset into stock is a second, separate act — see takeIntoStock().
 */
class BuybackSettlementService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Set an accepted trade-in off against an invoice.
     *
     * Refuses on everything that would spend the same handset twice, or spend it on
     * a bill it cannot cover.
     */
    public function settle(Buyback $buyback, Invoice $invoice, ?int $userId = null): Buyback
    {
        if ($buyback->status !== 'accepted') {
            throw new RuntimeException(
                'Only a trade-in the customer has actually handed over can settle a bill. Accept it first.',
            );
        }

        if ($buyback->isSettled()) {
            throw new RuntimeException('This trade-in has already been set off against a bill.');
        }

        $value = round((float) $buyback->offered_value, 2);

        if ($value <= 0) {
            throw new RuntimeException('This trade-in was taken in at no value, so there is nothing to set off.');
        }

        return DB::transaction(function () use ($buyback, $invoice, $value, $userId) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            $balance = round(
                (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount,
                2,
            );

            /**
             * A trade-in worth more than the bill it is being spent on is a normal
             * situation in this trade, not a mis-key: the customer exchanged a good
             * phone for a cheaper one. But the difference is cash the shop owes them
             * — a refund — and that is not something this register can quietly
             * pretend away, so it says so rather than capping the tender and losing
             * the balance.
             */
            if ($value > $balance + 0.005) {
                throw new RuntimeException(sprintf(
                    'The trade-in is worth %s and only %s is outstanding on this bill. '
                    .'Settle it against a larger bill, or pay the difference back separately.',
                    number_format($value, 2),
                    number_format(max(0, $balance), 2),
                ));
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'mode' => 'buyback',
                'amount' => $value,
                'payment_date' => now()->toDateString(),
                'notes' => "Part-exchange {$buyback->buyback_number} — {$buyback->label()}",
                'created_by' => $userId,
            ]);

            $invoice->forceFill([
                'paid_amount' => round((float) $invoice->paid_amount + $value, 2),
            ])->save();
            $invoice->refreshStatus();

            $buyback->forceFill([
                'status' => 'settled',
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'settled_on' => now()->toDateString(),
                // A trade-in settled against a bill belongs to whoever that bill was
                // raised to, even if the quote was taken from a walk-in.
                'customer_id' => $buyback->customer_id ?? $invoice->customer_id,
            ])->save();

            return $buyback->fresh(['invoice', 'payment', 'customer']);
        });
    }

    /**
     * Put the handset on the shelf.
     *
     * Booked in at the value allowed for it, which is what the shop paid — so the
     * margin on the eventual resale comes out of ordinary stock costing rather than
     * being tracked a second time here.
     *
     * The product is the caller's choice on purpose. A used handset is not the new
     * one: it wants its own catalogue line ("iPhone 12 128GB — Refurbished"), priced
     * and taxed differently, and a grade D trade-in usually wants a spares line
     * instead. Guessing would put second-hand units into new-phone stock.
     */
    public function takeIntoStock(
        Buyback $buyback,
        Product $product,
        int $warehouseId,
        ?int $userId = null,
    ): Buyback {
        if (! in_array($buyback->status, ['accepted', 'settled'], true)) {
            throw new RuntimeException('Only a handset the shop is actually holding can be taken into stock.');
        }

        if ($buyback->isInStock()) {
            throw new RuntimeException('This handset has already been taken into stock.');
        }

        return DB::transaction(function () use ($buyback, $product, $warehouseId, $userId) {
            $this->stock->post(
                warehouseId: $warehouseId,
                productId: $product->id,
                variantId: null,
                type: 'purchase',
                quantityIn: 1,
                quantityOut: 0,
                unitCost: (float) $buyback->offered_value,
                referenceType: Buyback::class,
                referenceId: $buyback->id,
                referenceNumber: $buyback->buyback_number,
                notes: 'Part-exchange intake — '.$buyback->label(),
                userId: $userId,
            );

            $buyback->forceFill([
                'stock_product_id' => $product->id,
                'stock_warehouse_id' => $warehouseId,
                'taken_into_stock_at' => now(),
            ])->save();

            return $buyback->fresh(['stockProduct', 'stockWarehouse']);
        });
    }
}
