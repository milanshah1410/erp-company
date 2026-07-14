<?php

namespace App\Services;

use App\Models\Tailor;
use App\Models\TailorPayment;
use App\Models\TailorWork;
use App\Models\TailorWorkCompletion;
use Illuminate\Support\Facades\DB;

/**
 * Tailor job-work accounting. One place for the money and stock math behind
 * work completion (earnings accrual + optional finished-goods receipt) and
 * tailor payouts. The per-piece rate is snapshotted on the work order at issue
 * time, so completions cost at that rate regardless of later rate-card edits.
 */
class TailorWorkService
{
    public function __construct(private StockService $stock)
    {
    }

    /**
     * Record a completion event against a work order: accrue the earning,
     * roll up the completed quantity/status and, when requested, receive the
     * finished pieces into a warehouse. Runs atomically. Caller must validate
     * that qty is > 0 and does not exceed the remaining quantity.
     */
    public function complete(TailorWork $work, array $data): TailorWorkCompletion
    {
        return DB::transaction(function () use ($work, $data) {
            $qty = round((float) $data['qty'], 2);
            $rate = (float) $work->rate;
            $amount = round($qty * $rate, 2);
            $receive = (bool) ($data['received_to_stock'] ?? false);
            $warehouseId = $data['warehouse_id'] ?? null;

            $completion = $work->completions()->create([
                'tailor_id' => $work->tailor_id,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => $amount,
                'completed_at' => $data['completed_at'] ?? now()->toDateString(),
                'received_to_stock' => $receive && $warehouseId,
                'warehouse_id' => $receive ? $warehouseId : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $work->completed_qty = round((float) $work->completed_qty + $qty, 2);
            $work->refreshStatus();

            if ($completion->received_to_stock) {
                $this->stock->inward(
                    warehouseId: (int) $completion->warehouse_id,
                    productId: $work->product_id,
                    variantId: $work->product_variant_id,
                    quantity: $qty,
                    unitCost: $rate, // conversion cost per finished piece
                    type: 'production',
                    referenceType: TailorWorkCompletion::class,
                    referenceId: $completion->id,
                    referenceNumber: $work->work_number,
                    userId: $data['created_by'] ?? null,
                );
            }

            return $completion;
        });
    }

    /** Record a payout to a tailor (a ledger debit against their earnings). */
    public function pay(Tailor $tailor, array $data): TailorPayment
    {
        return TailorPayment::create([
            'tailor_id' => $tailor->id,
            'payment_number' => TailorPayment::nextNumber(),
            'amount' => round((float) $data['amount'], 2),
            'mode' => $data['mode'] ?? 'cash',
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }
}
