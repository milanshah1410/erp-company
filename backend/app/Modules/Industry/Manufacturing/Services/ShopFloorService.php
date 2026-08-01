<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Services;

use App\Models\ManufacturingOrder;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use App\Services\StockService;
use RuntimeException;

/**
 * The two places where the shop floor touches something outside itself: stock,
 * and the production run's own numbers.
 *
 * Both are here rather than in the controllers because both have to stay true
 * across create, edit and delete. A scrap note that took stock out on Monday and
 * is corrected on Tuesday has to put the difference back; an inspection deleted
 * after it failed a run has to leave the run's rejected quantity as if it had
 * never been written. Spread across controller actions, that is four places to
 * get the same rule right.
 */
class ShopFloorService
{
    public function __construct(private readonly StockService $stock) {}

    /*
    |--------------------------------------------------------------------------
    | Scrap → stock
    |--------------------------------------------------------------------------
    */

    /**
     * Take scrapped material out of the warehouse.
     *
     * Only material scrap moves: finished output rejected on the line was never
     * received into stock, so posting it out would drive the balance negative
     * for goods that never arrived (see ProductionScrap).
     *
     * Posted with `allowNegative: true`. This is a deliberate exception to the
     * usual refusal: the material is physically in a bin by the machine and is
     * unusable whatever the system believes it has, and blocking the record
     * would leave the shop with scrap it cannot write down — the ledger would be
     * "right" and the warehouse wrong. A negative balance here is a visible
     * signal that issue postings are behind, which is the correct thing to
     * surface rather than suppress.
     */
    public function postScrap(ProductionScrap $scrap, ?int $userId = null): void
    {
        if ($scrap->is_posted || ! $scrap->shouldMoveStock()) {
            return;
        }

        $this->stock->outward(
            warehouseId: (int) $scrap->warehouse_id,
            productId: (int) $scrap->product_id,
            variantId: $scrap->variant_id,
            quantity: (float) $scrap->quantity,
            type: 'scrap',
            referenceType: ProductionScrap::class,
            referenceId: (int) $scrap->id,
            referenceNumber: $scrap->scrap_number,
            userId: $userId,
            allowNegative: true,
        );

        $scrap->forceFill(['is_posted' => true, 'posted_at' => now()])->save();
    }

    /**
     * Put a posted scrap's stock back, for a correction or a deletion.
     *
     * Booked as an `adjustment` rather than another `scrap` row, and at the cost
     * the scrap was written off at: the ledger should read "this was put back",
     * not "this was scrapped inward", and re-valuing it at today's average would
     * silently move money between the scrap loss and stock value. This mirrors
     * how a cancelled manufacturing order returns its consumed materials.
     */
    public function reverseScrap(ProductionScrap $scrap, ?int $userId = null): void
    {
        if (! $scrap->is_posted) {
            return;
        }

        $this->stock->inward(
            warehouseId: (int) $scrap->warehouse_id,
            productId: (int) $scrap->product_id,
            variantId: $scrap->variant_id,
            quantity: (float) $scrap->quantity,
            unitCost: (float) $scrap->unit_cost,
            type: 'adjustment',
            referenceType: ProductionScrap::class,
            referenceId: (int) $scrap->id,
            referenceNumber: $scrap->scrap_number,
            userId: $userId,
        );

        $scrap->forceFill(['is_posted' => false, 'posted_at' => null])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Quality → the run
    |--------------------------------------------------------------------------
    */

    /**
     * Re-derive a run's rejected quantity from its inspections.
     *
     * Summed from the inspection rows rather than incremented as they are
     * written: an edited or deleted inspection would leave a running total
     * quietly wrong, and a rejected quantity nobody can reconcile to its
     * inspections is worse than none at all.
     *
     * Rework is excluded — it goes back to the floor and may still be sold, so
     * counting it as rejected would overstate the loss.
     */
    public function syncOrderRejections(?ManufacturingOrder $order): void
    {
        if (! $order) {
            return;
        }

        $rejected = (float) QualityInspection::query()
            ->where('manufacturing_order_id', $order->id)
            ->sum('rejected_quantity');

        $order->forceFill(['quantity_rejected' => round($rejected, 3)])->save();
    }

    /**
     * Charge a run for the hours it spent on a machine.
     *
     * Kept as a separate act from editing the order because it is arithmetic the
     * shop should be able to redo: hours × the machine's rate, written into the
     * order's machine bucket and rolled into its finished cost. Refuses when the
     * machine has no rate, rather than silently charging nothing — a zero here
     * would be indistinguishable from "we ran it for free".
     */
    public function chargeMachineTime(ManufacturingOrder $order, float $hours): float
    {
        $machine = $order->machine;

        if (! $machine) {
            throw new RuntimeException('This order is not loaded on a machine.');
        }

        $rate = $machine->hourlyRate();
        if ($rate <= 0) {
            throw new RuntimeException("Machine {$machine->code} has no hourly cost set.");
        }

        $cost = round($hours * $rate, 2);
        $order->update(['machine_cost' => (float) $order->machine_cost + $cost]);
        $order->recalcCosts();

        return $cost;
    }
}
