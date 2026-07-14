<?php

namespace App\Services;

use App\Models\EmbroideryPayment;
use App\Models\EmbroideryReceipt;
use App\Models\EmbroideryVendor;
use App\Models\EmbroideryWork;
use Illuminate\Support\Facades\DB;

/**
 * Embroidery job-work accounting. One place for the money and stock math behind
 * receiving embroidered pieces (earnings accrual + optional finished-goods
 * receipt) and vendor payouts. The per-piece cost is snapshotted on the dispatch
 * at issue time, so receipts cost at that rate regardless of later edits.
 */
class EmbroideryWorkService
{
    public function __construct(private StockService $stock)
    {
    }

    /**
     * Record a receive event against a dispatch: accrue the earning, roll up the
     * received quantity/status and, when requested, receive the embroidered
     * pieces into a warehouse. Runs atomically. Caller must validate that qty is
     * > 0 and does not exceed the pending quantity.
     */
    public function receive(EmbroideryWork $work, array $data): EmbroideryReceipt
    {
        return DB::transaction(function () use ($work, $data) {
            $qty = round((float) $data['qty'], 2);
            $cost = (float) $work->cost;
            $amount = round($qty * $cost, 2);
            $receive = (bool) ($data['received_to_stock'] ?? false);
            $warehouseId = $data['warehouse_id'] ?? null;

            $receipt = $work->receipts()->create([
                'embroidery_vendor_id' => $work->embroidery_vendor_id,
                'qty' => $qty,
                'cost' => $cost,
                'amount' => $amount,
                'received_at' => $data['received_at'] ?? now()->toDateString(),
                'received_to_stock' => $receive && $warehouseId,
                'warehouse_id' => $receive ? $warehouseId : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $work->received_qty = round((float) $work->received_qty + $qty, 2);
            $work->refreshStatus();

            if ($receipt->received_to_stock) {
                $this->stock->inward(
                    warehouseId: (int) $receipt->warehouse_id,
                    productId: $work->product_id,
                    variantId: $work->product_variant_id,
                    quantity: $qty,
                    unitCost: $cost, // embroidery conversion cost per finished piece
                    type: 'production',
                    referenceType: EmbroideryReceipt::class,
                    referenceId: $receipt->id,
                    referenceNumber: $work->work_number,
                    userId: $data['created_by'] ?? null,
                );
            }

            return $receipt;
        });
    }

    /** Record a payout to an embroidery vendor (a ledger debit against earnings). */
    public function pay(EmbroideryVendor $vendor, array $data): EmbroideryPayment
    {
        return EmbroideryPayment::create([
            'embroidery_vendor_id' => $vendor->id,
            'payment_number' => EmbroideryPayment::nextNumber(),
            'amount' => round((float) $data['amount'], 2),
            'mode' => $data['mode'] ?? 'cash',
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }
}
