<?php

namespace App\Services;

use App\Models\Tailor;
use App\Models\TailorBonus;
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
     * Record a completion event against a work order: accrue the earning on the
     * accepted pieces, tally any QC rejects, roll up the quantity/status and,
     * when requested, receive the accepted finished pieces into a warehouse.
     * Runs atomically.
     *
     * `qty` is the accepted (good) quantity — it alone is paid and stocked.
     * `rejected_qty` failed QC and is never paid: a `rework` reject stays in the
     * pending pool for the tailor to redo, a `scrap` reject is written off so the
     * order can still close. Caller validates qty > 0 and qty ≤ remaining.
     */
    public function complete(TailorWork $work, array $data): TailorWorkCompletion
    {
        return DB::transaction(function () use ($work, $data) {
            $qty = round((float) $data['qty'], 2);
            $rejectedQty = round((float) ($data['rejected_qty'] ?? 0), 2);
            $rejectAction = $rejectedQty > 0 ? ($data['reject_action'] ?? 'rework') : null;
            $rate = (float) $work->rate;
            $amount = round($qty * $rate, 2); // rejects are unpaid
            $receive = (bool) ($data['received_to_stock'] ?? false);
            $warehouseId = $data['warehouse_id'] ?? null;

            $completion = $work->completions()->create([
                'tailor_id' => $work->tailor_id,
                'qty' => $qty,
                'rejected_qty' => $rejectedQty,
                'reject_action' => $rejectAction,
                'rejection_reason' => $rejectedQty > 0 ? ($data['rejection_reason'] ?? null) : null,
                'rate' => $rate,
                'amount' => $amount,
                'completed_at' => $data['completed_at'] ?? now()->toDateString(),
                'received_to_stock' => $receive && $warehouseId,
                'warehouse_id' => $receive ? $warehouseId : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $work->completed_qty = round((float) $work->completed_qty + $qty, 2);
            $work->rejected_qty = round((float) $work->rejected_qty + $rejectedQty, 2);
            if ($rejectAction === 'scrap') {
                $work->scrapped_qty = round((float) $work->scrapped_qty + $rejectedQty, 2);
            }
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

    /** Create a bonus record for a tailor (credits the ledger once approved). */
    public function awardBonus(Tailor $tailor, array $data): TailorBonus
    {
        $basis = $data['basis'] ?? 'manual';
        $pieces = isset($data['pieces']) ? round((float) $data['pieces'], 2) : null;
        $rate = isset($data['rate']) ? round((float) $data['rate'], 2) : null;
        $amount = isset($data['amount'])
            ? round((float) $data['amount'], 2)
            : $this->computeBonus($tailor, $data);

        return TailorBonus::create([
            'tailor_id' => $tailor->id,
            'bonus_number' => TailorBonus::nextNumber(),
            'basis' => $basis,
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'pieces' => $pieces,
            'rate' => $rate,
            'amount' => $amount,
            'status' => $data['status'] ?? 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Suggest a bonus amount from a tailor's completions in a period, according
     * to the chosen basis. This does not persist anything — it is used both by
     * the "Calculate" preview and as a fallback when no explicit amount is given.
     *
     *  - pieces:     (accepted pieces above `target`) × `rate`
     *  - efficiency: accepted pieces × `rate`, scaled by the accept ratio
     *  - ontime:     flat `rate` when the reject ratio stays within tolerance
     *  - manual:     no derivation (0)
     */
    public function computeBonus(Tailor $tailor, array $params): float
    {
        $basis = $params['basis'] ?? 'manual';
        $rate = (float) ($params['rate'] ?? 0);

        $completions = $tailor->completions();
        if (! empty($params['period_start'])) {
            $completions->whereDate('completed_at', '>=', $params['period_start']);
        }
        if (! empty($params['period_end'])) {
            $completions->whereDate('completed_at', '<=', $params['period_end']);
        }
        $completions = $completions->get();

        $accepted = round((float) $completions->sum('qty'), 2);
        $rejected = round((float) $completions->sum('rejected_qty'), 2);
        $submitted = $accepted + $rejected;

        $amount = match ($basis) {
            'pieces' => max(0.0, $accepted - (float) ($params['target'] ?? 0)) * $rate,
            'efficiency' => $submitted > 0 ? $accepted * $rate * ($accepted / $submitted) : 0.0,
            'ontime' => ($submitted > 0 && ($rejected / $submitted) <= (float) ($params['tolerance'] ?? 0.05)) ? $rate : 0.0,
            default => 0.0,
        };

        return round($amount, 2);
    }
}
