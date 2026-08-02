<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Services;

use App\Models\WarehouseStock;
use App\Modules\Industry\Gift\Models\GiftPackBuild;
use App\Modules\Industry\Gift\Models\GiftPackItem;
use App\Services\StockService;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns a hamper build into stock: contents out, hampers in.
 *
 * The same shape as the Agriculture plugin's FarmProductionService and the mirror
 * of Food & Beverage's recipe consumption — one document, two directions, one
 * `posted_at` guard. What differs is *when* it happens, and that is the whole
 * design decision behind gift packs:
 *
 * A restaurant combo expands when it is sold, because there is no such physical
 * object as a meal deal and nothing to count on a shelf. A hamper is the opposite:
 * it is built in the back room, wrapped, put on a shelf, counted at stock-take and
 * sold by scanning one barcode. So the stock moves when it is *assembled*. Waiting
 * for the sale would mean the shelf held forty hampers the system had never heard
 * of, while still showing forty baskets' worth of chocolate it no longer had —
 * through the one season a gift shop makes its year in.
 *
 * Two deliberate asymmetries between posting and reversing:
 *
 *  - **A build refuses to go negative.** Assembling hampers is a back-room entry
 *    with nobody waiting at a counter, so a shortfall means the stock file is
 *    wrong and the honest thing is to say so before the shop prices a season's
 *    hampers off it. (A till is the opposite case, which is why POS and recipe
 *    consumption allow negative — refusing there would stop the shop.)
 *  - **A reversal does not.** By the time a miscount is noticed some of those
 *    hampers may already have been sold, and blocking the correction would leave
 *    the wrong number standing, which is worse than a negative balance.
 */
class GiftPackService
{
    public function __construct(private readonly StockService $stock) {}

    /** Ledger type for finished hampers entering stock. */
    public const BUILD = 'gift_pack_build';

    /** Ledger type for the contents leaving it. */
    public const CONSUME = 'gift_pack_consume';

    /**
     * Assemble the batch: draw down every component, bring the hampers in, and
     * record what one hamper actually cost.
     *
     * Expects to run inside the caller's transaction — a half-applied build
     * (chocolate gone, no hampers) is worse than a failed save. Returns false and
     * does nothing when the build is already posted, so a retry is always safe.
     *
     * @throws RuntimeException when the pack has no contents, or a component is short
     */
    public function post(GiftPackBuild $build, ?int $userId = null): bool
    {
        if ($build->isPosted()) {
            return false;
        }

        $quantity = (float) $build->quantity;

        if ($quantity <= 0) {
            throw new RuntimeException('A build must assemble at least one hamper.');
        }

        $items = $build->pack->items()->with('product:id,name,cost_price')->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('This pack has no contents yet, so there is nothing to assemble.');
        }

        /**
         * Every component is checked *before* anything moves.
         *
         * StockService already refuses to take a balance negative, so the build
         * would fail either way — but it would fail on the fourth line, having
         * already emptied the first three. Callers are told to wrap this in a
         * transaction and the controller does, so those movements roll back; a
         * pre-flight check means the guarantee does not depend on the caller
         * remembering, and it lets the shop be told *what* is short in one go
         * rather than one component per attempt.
         */
        $this->assertAvailable($build, $items, $quantity);

        /**
         * What the batch actually cost, accumulated from the ledger rows rather
         * than from the products' cost prices: StockService costs an issue by the
         * company's configured method (FIFO, LIFO or weighted average), and the
         * hamper should enter stock at what came out of the layers — not at a
         * list price nobody paid.
         */
        $batchCost = 0.0;

        foreach ($items as $item) {
            $required = round((float) $item->quantity * $quantity, 3);

            if ($required <= 0) {
                continue;
            }

            $ledger = $this->stock->post(
                warehouseId: (int) $build->warehouse_id,
                productId: (int) $item->product_id,
                variantId: $item->variant_id ? (int) $item->variant_id : null,
                type: self::CONSUME,
                quantityIn: 0,
                quantityOut: $required,
                referenceType: GiftPackBuild::class,
                referenceId: (int) $build->id,
                referenceNumber: $build->build_number,
                notes: 'Assembled into '.$build->pack->name,
                userId: $userId,
            );

            $batchCost += $required * (float) $ledger->unit_cost;
        }

        $unitCost = round($batchCost / $quantity, 4);

        $this->stock->post(
            warehouseId: (int) $build->warehouse_id,
            productId: (int) $build->pack->product_id,
            variantId: null,
            type: self::BUILD,
            quantityIn: $quantity,
            quantityOut: 0,
            unitCost: $unitCost,
            referenceType: GiftPackBuild::class,
            referenceId: (int) $build->id,
            referenceNumber: $build->build_number,
            notes: 'Built from '.$build->pack->code,
            userId: $userId,
        );

        $build->forceFill([
            'unit_cost' => $unitCost,
            'posted_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Take a build back apart in the books: hampers out, contents back on the
     * shelf at what they were costed out at.
     *
     * Posted as `adjustment` in both directions — the same choice Agriculture and
     * ManufacturingOrderController make when unwinding a consumed order. A
     * reversal is a correction to the books, not a batch of hampers that was
     * disassembled, and the build/consume totals for a season must stay true to
     * what was really assembled.
     */
    public function unpost(GiftPackBuild $build, ?int $userId = null): bool
    {
        if (! $build->isPosted()) {
            return false;
        }

        $quantity = (float) $build->quantity;

        $this->stock->post(
            warehouseId: (int) $build->warehouse_id,
            productId: (int) $build->pack->product_id,
            variantId: null,
            type: 'adjustment',
            quantityIn: 0,
            quantityOut: $quantity,
            referenceType: GiftPackBuild::class,
            referenceId: (int) $build->id,
            referenceNumber: $build->build_number,
            notes: 'Build reversed: '.$build->pack->code,
            userId: $userId,
            // Some of them may already be sold. See the class docblock.
            allowNegative: true,
        );

        foreach ($build->pack->items as $item) {
            $required = round((float) $item->quantity * $quantity, 3);

            if ($required <= 0) {
                continue;
            }

            $this->stock->post(
                warehouseId: (int) $build->warehouse_id,
                productId: (int) $item->product_id,
                variantId: $item->variant_id ? (int) $item->variant_id : null,
                type: 'adjustment',
                quantityIn: $required,
                quantityOut: 0,
                // Back in at what the hamper was carried at per component share,
                // which is the closest honest figure to what left.
                unitCost: $this->componentCost($item),
                referenceType: GiftPackBuild::class,
                referenceId: (int) $build->id,
                referenceNumber: $build->build_number,
                notes: 'Returned from reversed build',
                userId: $userId,
            );
        }

        $build->forceFill(['posted_at' => null])->save();

        return true;
    }

    /**
     * Refuse the whole build if any component is short, naming all of them.
     *
     * Checked against the build's own warehouse, not free stock everywhere: a
     * hamper is assembled from the shelves it is standing next to, and chocolate
     * sitting in another branch will not go in the basket.
     *
     * @param  Collection<int, GiftPackItem>  $items
     *
     * @throws RuntimeException
     */
    private function assertAvailable(GiftPackBuild $build, Collection $items, float $quantity): void
    {
        $short = [];

        foreach ($items as $item) {
            $required = round((float) $item->quantity * $quantity, 3);

            if ($required <= 0) {
                continue;
            }

            $onHand = (float) WarehouseStock::query()
                ->where('warehouse_id', $build->warehouse_id)
                ->where('product_id', $item->product_id)
                ->when(
                    $item->variant_id === null,
                    fn ($q) => $q->whereNull('variant_id'),
                    fn ($q) => $q->where('variant_id', $item->variant_id),
                )
                ->sum('quantity');

            if ($onHand + 0.0001 < $required) {
                $short[] = sprintf(
                    '%s (need %s, have %s)',
                    $item->product?->name ?? 'product #'.$item->product_id,
                    $this->trim($required),
                    $this->trim($onHand),
                );
            }
        }

        if ($short !== []) {
            throw new RuntimeException(
                'Not enough stock to build this batch: '.implode('; ', $short).'.',
            );
        }
    }

    /** 6.000 reads as 6 to a shopkeeper, and 2.500 as 2.5. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /**
     * What one of a component is worth on the way back in. The product's cost
     * price: the layers it originally came out of are gone, and inventing a
     * weighted-average figure here would only look more precise than it is.
     */
    private function componentCost(GiftPackItem $item): float
    {
        return (float) ($item->product?->cost_price ?? 0);
    }
}
