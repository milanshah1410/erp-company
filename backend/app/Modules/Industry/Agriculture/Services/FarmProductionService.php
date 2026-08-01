<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Services;

use App\Modules\Industry\Agriculture\Models\FarmProductionLog;
use App\Modules\Industry\Agriculture\Models\LivestockFlock;
use App\Services\StockService;

/**
 * Turns a day's farm entry into stock movements and flock counters.
 *
 * The mirror image of the Food & Beverage plugin's RecipeConsumptionService: a
 * restaurant consumes stock into a dish, a farm creates stock out of a herd.
 * Nothing a farm sells was ever purchased, so unless production posts inward,
 * every milk invoice drives stock negative and the farm's own output looks like
 * a shortage.
 *
 * Three things move together, and they move together on purpose:
 *
 *  1. Produce **in** — milk, eggs, manure — at the entered cost.
 *  2. Feed **out** — the cost that decides whether the farm makes money, and the
 *     one that leaves the store every day whether or not anything is sold.
 *  3. Flock counters — mortality, culls and the roll-ups every poultry ratio is
 *     computed from.
 *
 * `posted_at` is the guard for all three. A farm entry is keyed on a phone in a
 * shed with one bar of signal; the double-tap is not hypothetical, and it must
 * not deplete a sack of feed twice.
 */
class FarmProductionService
{
    public function __construct(private readonly StockService $stock) {}

    /** Ledger type for output coming off the farm. */
    public const PRODUCE = 'farm_produce';

    /** Ledger type for feed drawn into the shed. */
    public const FEED = 'feed_consume';

    /**
     * Apply a log: produce in, feed out, counters moved.
     *
     * Returns false and does nothing if the row is already posted, so a retry is
     * always safe. Expects to run inside the caller's transaction — a partly
     * applied log (milk in, feed not out) is worse than a failed save.
     */
    public function post(FarmProductionLog $log, ?int $userId = null): bool
    {
        if ($log->isPosted()) {
            return false;
        }

        if ((float) $log->quantity > 0) {
            $this->move(
                $log,
                productId: $log->product_id,
                quantityIn: (float) $log->quantity,
                quantityOut: 0,
                unitCost: (float) $log->unit_cost,
                type: self::PRODUCE,
                userId: $userId,
            );
        }

        if ($log->feed_product_id && (float) $log->feed_quantity > 0) {
            $this->move(
                $log,
                productId: $log->feed_product_id,
                quantityIn: 0,
                quantityOut: (float) $log->feed_quantity,
                unitCost: 0,
                type: self::FEED,
                userId: $userId,
            );
        }

        $this->applyFlockCounters($log, 1);

        $log->forceFill(['posted_at' => now()])->save();

        return true;
    }

    /**
     * Undo a posted log: opposite movements, counters wound back.
     *
     * A wrong yield gets noticed the next morning, so correcting one has to be a
     * first-class operation rather than a database edit. Reversals post as
     * `adjustment` (the same choice ManufacturingOrderController makes when
     * cancelling a consumed order) so the produce and feed totals for a period
     * stay true to what was actually produced and fed — a reversal is a
     * correction to the books, not a milking that ran backwards.
     */
    public function unpost(FarmProductionLog $log, ?int $userId = null): bool
    {
        if (! $log->isPosted()) {
            return false;
        }

        if ((float) $log->quantity > 0) {
            // The produce may already have been sold or delivered by the time
            // the entry is corrected. Blocking the correction would leave the
            // wrong number standing, which is worse than a negative balance.
            $this->move(
                $log,
                productId: $log->product_id,
                quantityIn: 0,
                quantityOut: (float) $log->quantity,
                unitCost: 0,
                type: 'adjustment',
                userId: $userId,
            );
        }

        if ($log->feed_product_id && (float) $log->feed_quantity > 0) {
            $this->move(
                $log,
                productId: $log->feed_product_id,
                quantityIn: (float) $log->feed_quantity,
                quantityOut: 0,
                unitCost: 0,
                type: 'adjustment',
                userId: $userId,
            );
        }

        $this->applyFlockCounters($log, -1);

        $log->forceFill(['posted_at' => null])->save();

        return true;
    }

    /**
     * One stock movement for this log, in either direction.
     *
     * Goes through StockService::post() rather than its inward()/outward()
     * helpers for one reason: **every** movement here allows a negative balance,
     * and only post() takes that flag on an inward.
     *
     * That is not laxity, it is the direction the doctrine has to run on a farm.
     * StockService guards the *resulting* balance, not the direction of travel —
     * so once a shed has drawn feed it never keyed an opening balance for, its
     * balance is negative, and an inward that only brings it back to −6 is
     * refused exactly like an outward would be. The reversal of a feed entry
     * would then fail, which would leave the wrong figure standing and no way to
     * correct it. Same on the produce side: a farm that sold this morning's milk
     * before anyone keyed the milking must still be able to key it.
     *
     * A negative balance is a visible problem to reconcile. A shed that has
     * learned the system rejects its entries stops making them, and that problem
     * is invisible.
     */
    private function move(
        FarmProductionLog $log,
        int $productId,
        float $quantityIn,
        float $quantityOut,
        float $unitCost,
        string $type,
        ?int $userId,
    ): void {
        $this->stock->post(
            warehouseId: $log->warehouse_id,
            productId: $productId,
            variantId: null,
            type: $type,
            quantityIn: $quantityIn,
            quantityOut: $quantityOut,
            unitCost: $unitCost,
            referenceType: FarmProductionLog::class,
            referenceId: $log->id,
            referenceNumber: $this->reference($log),
            userId: $userId,
            allowNegative: true,
        );
    }

    /**
     * Move a flock's denormalised counters by this log, in the given direction
     * (+1 posting, -1 unposting).
     *
     * `current_birds` is recomputed from opening minus everything that left,
     * rather than incremented: a farm that corrects three entries in a row would
     * otherwise accumulate drift in the one number the shed counts by hand every
     * morning. Clamped at zero because the counters are unsigned, and a shed that
     * has recorded more deaths than birds placed has a data-entry problem to fix
     * rather than a reason for the screen to 500.
     */
    private function applyFlockCounters(FarmProductionLog $log, int $direction): void
    {
        if (! $log->flock_id) {
            return;
        }

        $flock = $log->flock ?? LivestockFlock::find($log->flock_id);

        if (! $flock) {
            return;
        }

        $mortality = max(0, (int) $flock->mortality_birds + $direction * (int) $log->mortality_count);
        $culled = max(0, (int) $flock->culled_birds + $direction * (int) $log->culled_count);
        $feed = max(0, round((float) $flock->feed_consumed_kg + $direction * (float) $log->feed_quantity, 3));

        // Eggs are the produce quantity, but only when the product being logged
        // is what the flock lays; a manure or a litter line must not inflate the
        // egg count, and quantity is only eggs when the log is an egg log.
        $eggs = $this->isEggLog($log)
            ? max(0, (int) $flock->eggs_collected + $direction * (int) round((float) $log->quantity))
            : (int) $flock->eggs_collected;

        $flock->forceFill([
            'mortality_birds' => $mortality,
            'culled_birds' => $culled,
            'feed_consumed_kg' => $feed,
            'eggs_collected' => $eggs,
            'current_birds' => max(0, (int) $flock->opening_birds - $mortality - $culled - (int) $flock->sold_birds),
        ])->save();
    }

    /**
     * Is this log's output the flock's eggs?
     *
     * Decided by the product's own agri classification and name rather than by
     * asking the user to tick a box: the shed keys "Eggs, 4,200" and should not
     * also have to tell the system that eggs are eggs. A layer flock logging
     * anything else — manure, spent litter — leaves the egg count alone.
     */
    private function isEggLog(FarmProductionLog $log): bool
    {
        $product = $log->product ?? $log->product()->first();

        if (! $product) {
            return false;
        }

        return str_contains(strtolower((string) $product->name), 'egg');
    }

    /** Human reference on the ledger row, so a movement is traceable to a shift. */
    private function reference(FarmProductionLog $log): string
    {
        $source = $log->sourceLabel() ?? 'FARM';

        return $source.'/'.$log->log_date->format('Y-m-d').'/'.strtoupper(substr((string) $log->session, 0, 1));
    }
}
