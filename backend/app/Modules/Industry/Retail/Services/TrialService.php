<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Services;

use App\Modules\Industry\Retail\Models\GarmentTrial;
use App\Modules\Industry\Retail\Models\GarmentTrialItem;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The trial room.
 *
 * **Why a trial reserves stock instead of moving it.** The obvious modelling —
 * issue posts an outward movement, return posts an inward one — reads well until
 * the customer keeps the garment. Then the sale has to bill it, the sale posts
 * its own outward movement, and the same shirt has left the building twice. The
 * usual fixes are worse than the problem: billing without posting stock puts a
 * silent exception into the one code path every trade depends on, and returning
 * the piece to stock on the way to the till writes a movement that never
 * physically happened.
 *
 * So an issue takes `reserved_quantity` instead. That is exactly what the column
 * means — on the books, spoken for, not available to sell — and it is the same
 * mechanism a confirmed sales order already uses. Availability at the counter
 * drops the moment the garment leaves, which is the behaviour that matters;
 * on-hand still shows it, which is honest, because the shop does still own it.
 * When the customer buys, the reservation is released and the ordinary POS sale
 * moves the stock once. When they bring it back, the reservation is released and
 * nothing else happens, because nothing else did.
 *
 * Everything here is idempotent on the item's `returned_quantity` /
 * `sold_quantity` counters rather than on the trial's status, so a slip settled
 * in two visits behaves the same as one settled in one.
 */
class TrialService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Put the garments on the slip out on trial: reserve every line.
     *
     * @param  list<array{product_id: int, variant_id?: int|null, quantity: float, unit_price?: float, remarks?: string|null}>  $items
     */
    public function issue(GarmentTrial $trial, array $items): GarmentTrial
    {
        if ($items === []) {
            throw new RuntimeException('A trial needs at least one garment on it.');
        }

        DB::transaction(function () use ($trial, $items) {
            foreach ($items as $item) {
                $quantity = round((float) $item['quantity'], 3);

                if ($quantity <= 0) {
                    continue;
                }

                $trial->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => round((float) ($item['unit_price'] ?? 0), 2),
                    'remarks' => $item['remarks'] ?? null,
                ]);

                $this->stock->reserve(
                    (int) $trial->warehouse_id,
                    (int) $item['product_id'],
                    $item['variant_id'] ?? null,
                    $quantity,
                );
            }
        });

        return $trial->fresh(['items.product', 'items.variant', 'customer']);
    }

    /**
     * Settle lines on an open slip: some came back, some were kept.
     *
     * Both outcomes release the reservation — the difference is only what the
     * counters say afterwards, and whether the pieces are now waiting to be
     * billed. Quantities are validated against what is actually still out, so a
     * double-keyed return cannot drive a line negative or release stock twice.
     *
     * @param  list<array{item_id: int, returned_quantity?: float, sold_quantity?: float, remarks?: string|null}>  $lines
     */
    public function settle(GarmentTrial $trial, array $lines): GarmentTrial
    {
        if (! $trial->isOpen()) {
            throw new RuntimeException('This trial is already closed.');
        }

        DB::transaction(function () use ($trial, $lines) {
            $items = $trial->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                /** @var GarmentTrialItem|null $item */
                $item = $items->get((int) $line['item_id']);

                if (! $item) {
                    continue;
                }

                $returned = round((float) ($line['returned_quantity'] ?? 0), 3);
                $sold = round((float) ($line['sold_quantity'] ?? 0), 3);
                $moving = $returned + $sold;

                if ($moving <= 0) {
                    continue;
                }

                $outstanding = $item->outstandingQuantity();

                if ($moving > $outstanding + 0.0005) {
                    throw new RuntimeException(
                        "Only {$outstanding} of \"{$item->label()}\" is still out on this trial.",
                    );
                }

                $item->returned_quantity = round((float) $item->returned_quantity + $returned, 3);
                $item->sold_quantity = round((float) $item->sold_quantity + $sold, 3);
                if (array_key_exists('remarks', $line) && $line['remarks'] !== null) {
                    $item->remarks = $line['remarks'];
                }
                $item->save();

                // Released either way: a kept garment is released so the sale can
                // take it out of stock properly, a returned one because it is
                // back on the rail.
                $this->stock->release(
                    (int) $trial->warehouse_id,
                    (int) $item->product_id,
                    $item->variant_id,
                    $moving,
                );
            }
        });

        return $this->refreshStatus($trial->fresh(['items.product', 'items.variant', 'customer']));
    }

    /**
     * Abandon a slip that never really happened — mis-keyed, or the customer
     * changed their mind at the door. Every outstanding reservation is released
     * and nothing is recorded as returned or sold, because neither is true.
     */
    public function cancel(GarmentTrial $trial, ?string $reason = null): GarmentTrial
    {
        if (! $trial->isOpen()) {
            throw new RuntimeException('This trial is already closed.');
        }

        DB::transaction(function () use ($trial, $reason) {
            foreach ($trial->items()->lockForUpdate()->get() as $item) {
                $this->stock->release(
                    (int) $trial->warehouse_id,
                    (int) $item->product_id,
                    $item->variant_id,
                    $item->outstandingQuantity(),
                );
            }

            $trial->forceFill([
                'status' => 'cancelled',
                'closed_at' => now(),
                'notes' => trim((string) $trial->notes."\nCancelled: ".($reason ?: '—')),
            ])->save();
        });

        return $trial->fresh(['items.product', 'items.variant', 'customer']);
    }

    /**
     * Record which bill the kept pieces were rung up on.
     *
     * The sale itself is not created here on purpose. A garment sale is an
     * ordinary counter sale — it prices, discounts, takes a tender mix, awards
     * points and posts stock — and re-implementing that so the trial screen could
     * own it would produce a second, worse checkout. The trial's job ends at
     * saying "these went out, these came back, these were bought, and here is the
     * invoice that proves it".
     */
    public function linkInvoice(GarmentTrial $trial, int $invoiceId): GarmentTrial
    {
        $trial->forceFill(['invoice_id' => $invoiceId])->save();

        return $this->refreshStatus($trial->fresh(['items.product', 'items.variant', 'customer']));
    }

    /**
     * Close the slip once nothing is outstanding, and say how it ended.
     *
     * `converted` rather than a plain `closed` when anything was kept, because
     * the number a shop wants out of its trial book is how many trials turn into
     * sales — and a single closed state cannot answer that.
     */
    public function refreshStatus(GarmentTrial $trial): GarmentTrial
    {
        if (! $trial->isOpen()) {
            return $trial;
        }

        if ($trial->outstandingQuantity() > 0.0005) {
            return $trial;
        }

        $sold = (float) $trial->items->sum('sold_quantity');

        $trial->forceFill([
            'status' => $sold > 0 ? 'converted' : 'closed',
            'closed_at' => now(),
        ])->save();

        return $trial;
    }
}
