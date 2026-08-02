<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Services;

use App\Models\Warehouse;
use App\Modules\Industry\Gift\Models\GiftWrap;
use App\Services\StockService;

/**
 * The wrap desk's two state changes, and the stock behind them.
 *
 * Wrapping is not free even when it is not charged for: a shop gets through
 * hundreds of sheets, boxes and reels of ribbon in a season, buys them by the
 * hundred, and discovers on the busiest evening of the year that it ran out on
 * Tuesday. So finishing a parcel draws its material down like any other issue.
 *
 * Three rules:
 *
 *  1. **Once per parcel.** `material_posted_at` is the guard. A parcel marked
 *     wrapped, reopened because the customer changed their mind about the colour,
 *     and marked wrapped again must not eat two sheets of paper — the second wrap
 *     is the shop's mistake to absorb, not a second issue to post.
 *  2. **Negative is allowed.** Unlike a hamper build, this happens with a customer
 *     standing at the counter and the paper already cut. Refusing to record what
 *     has visibly happened teaches staff to stop recording it; a negative balance
 *     is a visible problem to reconcile.
 *  3. **Cancelling does not give the paper back.** A parcel cancelled after it was
 *     wrapped was really wrapped, and the material really went. Reversing it would
 *     make the stock file tidier and less true.
 */
class GiftWrapService
{
    public function __construct(private readonly StockService $stock) {}

    /** Ledger type for wrapping material leaving the shelf. */
    public const MOVEMENT = 'gift_wrap_consume';

    /**
     * Mark a parcel wrapped, and deplete what it was wrapped in.
     *
     * Returns the number of stock movements posted (0 or 1) — the caller reports
     * it, because "wrapped, nothing depleted" is a legitimate outcome for an
     * option that tracks no material and staff should not be told stock moved when
     * it did not.
     */
    public function markWrapped(GiftWrap $wrap, ?int $userId = null): int
    {
        $posted = $this->consumeMaterial($wrap, $userId);

        $wrap->forceFill([
            'status' => 'wrapped',
            'wrapped_at' => $wrap->wrapped_at ?? now(),
            'wrapped_by' => $wrap->wrapped_by ?? $userId,
        ])->save();

        return $posted;
    }

    /** Handed over. Nothing moves — the goods left stock when they were sold. */
    public function markCollected(GiftWrap $wrap): void
    {
        $wrap->forceFill([
            'status' => 'collected',
            'collected_at' => $wrap->collected_at ?? now(),
        ])->save();
    }

    /**
     * Called off. See rule 3: whatever was already used stays used.
     */
    public function cancel(GiftWrap $wrap, ?string $reason = null): void
    {
        $wrap->forceFill([
            'status' => 'cancelled',
            'notes' => trim((string) $wrap->notes."\nCancelled: ".($reason ?: 'no reason given')),
        ])->save();
    }

    /**
     * Draw down the wrapping material, once.
     *
     * @return int movements posted
     */
    private function consumeMaterial(GiftWrap $wrap, ?int $userId): int
    {
        if ($wrap->materialPosted()) {
            return 0;
        }

        $option = $wrap->option;

        if (! $option || ! $option->consumesStock()) {
            return 0;
        }

        $warehouseId = $wrap->warehouse_id ?? $this->defaultWarehouseId();

        if (! $warehouseId) {
            return 0;
        }

        $this->stock->post(
            warehouseId: (int) $warehouseId,
            productId: (int) $option->consumes_product_id,
            variantId: null,
            type: self::MOVEMENT,
            quantityIn: 0,
            quantityOut: (float) $option->consume_quantity,
            referenceType: GiftWrap::class,
            referenceId: (int) $wrap->id,
            referenceNumber: $wrap->wrap_number,
            notes: 'Gift wrap: '.($wrap->wrap_label ?: $option->name),
            userId: $userId,
            // See rule 2.
            allowNegative: true,
        );

        $wrap->forceFill([
            'warehouse_id' => $warehouseId,
            'material_posted_at' => now(),
        ])->save();

        return 1;
    }

    /**
     * Which shelf the paper comes off when the desk does not say — which is
     * almost always, because there is one counter and nobody thinks of it as a
     * warehouse.
     */
    private function defaultWarehouseId(): ?int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }
}
