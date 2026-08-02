<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Services;

use App\Models\Batch;
use App\Models\Product;
use App\Models\Setting;
use App\Modules\Industry\Cosmetics\Models\OpenUnit;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * How long the stock is still good for — and what the counter does about it.
 *
 * Two clocks run in a cosmetics shop and core only knows about one of them.
 *
 *  - The **printed expiry** on the batch. Core holds it (`batches.expiry_date`)
 *    and picks stock FEFO against it, which is the right behaviour and is not
 *    re-implemented here. What core does not do is *refuse*: an ERP will happily
 *    bill a foundation that went off in March, because for most trades a date
 *    slipping past is a markdown decision rather than a wall. In this trade it
 *    is a wall — selling expired cosmetics is an offence under the Drugs and
 *    Cosmetics Rules, and the shop finds out from an inspector.
 *
 *  - The **period after opening**, which exists nowhere in core because for
 *    every other trade there is no such thing as opened stock. A tester's clock
 *    starts the day the seal breaks and runs on the pack's PAO, not its printed
 *    date. See OpenUnit.
 *
 * This service is the one place both are read, and it answers three questions:
 * what is dying (the board), what is on this bill (the counter check), and may
 * this bill be rung up at all (assertSellable).
 *
 * The policy is three tenant settings rather than columns, the same shape
 * Distribution's credit enforcement uses. It is one shop-wide decision an owner
 * changes twice a year — not a per-article property somebody would have to key
 * five thousand times, and not a constant, because the honest answer differs by
 * shop: a chain with a compliance officer blocks short-dated stock outright, and
 * a single counter clearing its shelf at 40% off needs to be able to sell it.
 */
class ShelfLifeService
{
    /** Refuse a bill carrying expired stock, rather than only warning about it. */
    public const KEY_BLOCK_EXPIRED = 'cosmetics_block_expired_sale';

    /** Days of remaining life below which a line is flagged at the till. */
    public const KEY_MIN_SHELF_LIFE = 'cosmetics_min_sale_shelf_life_days';

    /** How far ahead the board looks. */
    public const KEY_NEAR_EXPIRY = 'cosmetics_near_expiry_days';

    public const DEFAULTS = [
        self::KEY_BLOCK_EXPIRED => '1',
        // Zero by default: a shop that has not thought about it should get the
        // legal floor (nothing expired leaves the counter) and no more. A
        // short-dated warning nobody asked for trains staff to click past
        // warnings, which is worse than not having one.
        self::KEY_MIN_SHELF_LIFE => '0',
        self::KEY_NEAR_EXPIRY => '90',
    ];

    /** @return array{block_expired: bool, min_shelf_life_days: int, near_expiry_days: int} */
    public function policy(): array
    {
        return [
            'block_expired' => filter_var(
                Setting::get(self::KEY_BLOCK_EXPIRED, self::DEFAULTS[self::KEY_BLOCK_EXPIRED]),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'min_shelf_life_days' => max(0, (int) Setting::get(
                self::KEY_MIN_SHELF_LIFE,
                self::DEFAULTS[self::KEY_MIN_SHELF_LIFE],
            )),
            'near_expiry_days' => max(1, (int) Setting::get(
                self::KEY_NEAR_EXPIRY,
                self::DEFAULTS[self::KEY_NEAR_EXPIRY],
            )),
        ];
    }

    /**
     * The shelf-life board: everything with a clock on it, worst first.
     *
     * Three lists rather than one, because they are three different jobs. Expired
     * batches come off the shelf today. Short-dated batches get marked down or
     * pushed to the front. Overdue testers get thrown away and replaced — and
     * that last one is the list nobody has anywhere else, which is why it shares
     * a screen with the other two instead of hiding behind its own menu row.
     *
     * @return array<string, mixed>
     */
    public function board(?int $withinDays = null, ?int $warehouseId = null): array
    {
        $policy = $this->policy();
        $within = $withinDays !== null ? max(1, $withinDays) : $policy['near_expiry_days'];
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays($within);

        $batches = Batch::query()
            ->with(['product:id,name,sku,pao_months', 'variant:id,name,sku', 'warehouse:id,name'])
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $horizon->toDateString())
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('expiry_date')
            ->limit(500)
            ->get();

        $expired = [];
        $expiring = [];

        foreach ($batches as $batch) {
            $row = $this->batchRow($batch, $today);
            // Sorted into the two lists by the only thing that matters about the
            // date: whether it has already gone past.
            if ($row['days_left'] < 0) {
                $expired[] = $row;
            } else {
                $expiring[] = $row;
            }
        }

        $units = OpenUnit::query()
            ->with(['product:id,name,sku', 'variant:id,name,sku', 'warehouse:id,name'])
            ->open()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $horizon->toDateString())
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('expires_on')
            ->limit(200)
            ->get()
            ->map(fn (OpenUnit $unit) => $this->unitRow($unit))
            ->all();

        return [
            'policy' => $policy,
            'within_days' => $within,
            'expired' => $expired,
            'expiring' => $expiring,
            'open_units' => $units,
            'summary' => [
                'expired_batches' => count($expired),
                'expired_quantity' => round((float) array_sum(array_column($expired, 'quantity')), 3),
                'expired_value' => round((float) array_sum(array_column($expired, 'value')), 2),
                'expiring_batches' => count($expiring),
                'expiring_value' => round((float) array_sum(array_column($expiring, 'value')), 2),
                'overdue_units' => count(array_filter($units, fn (array $u) => $u['days_left'] !== null && $u['days_left'] < 0)),
                'open_units' => OpenUnit::query()->open()->count(),
            ],
        ];
    }

    /**
     * What the till would be selling: for each line, the batch FEFO will reach
     * for and whether it is fit to go out.
     *
     * Read-only and non-destructive — it looks at the same batches
     * InventoryTrackingService::consumeBatchFefo() would drain, in the same
     * order, without touching them. That duplication is deliberate and narrow:
     * the consume path locks rows and decrements them, which is exactly what a
     * pre-check must not do, and the ordering rule it mirrors ("oldest expiry
     * first, nulls last") is one line.
     *
     * @param  list<array<string, mixed>>  $items  POS cart lines.
     * @return array<string, mixed>
     */
    public function inspect(array $items, ?int $warehouseId = null): array
    {
        $policy = $this->policy();
        $today = now()->startOfDay();

        $products = Product::query()
            ->whereIn('id', array_values(array_filter(array_column($items, 'product_id'))))
            ->get(['id', 'name', 'sku', 'track_batch', 'track_expiry'])
            ->keyBy('id');

        $issues = [];

        foreach ($items as $item) {
            $product = $products->get((int) ($item['product_id'] ?? 0));

            // A product that tracks neither batch nor expiry has no clock on it —
            // a hairbrush, a comb, a set of brushes. Nothing to say about it.
            if (! $product || ! ($product->track_batch || $product->track_expiry)) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $allocated = 0.0;

            $batches = Batch::query()
                ->where('product_id', $product->id)
                ->where('variant_id', $item['variant_id'] ?? null)
                ->when($warehouseId ?? ($item['warehouse_id'] ?? null), fn ($q, $wh) => $q->where('warehouse_id', $wh))
                ->where('quantity', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->orderBy('id')
                ->get();

            foreach ($batches as $batch) {
                if ($allocated >= $quantity) {
                    break;
                }

                $take = min($quantity - $allocated, (float) $batch->quantity);
                $allocated += $take;

                if (! $batch->expiry_date) {
                    continue;
                }

                $daysLeft = (int) $today->diffInDays($batch->expiry_date->copy()->startOfDay(), false);

                if ($daysLeft < 0) {
                    $issues[] = [
                        'product_id' => $product->id,
                        'product' => $product->name,
                        'batch_number' => $batch->batch_number,
                        'expiry_date' => $batch->expiry_date->toDateString(),
                        'days_left' => $daysLeft,
                        'quantity' => round($take, 3),
                        'severity' => $policy['block_expired'] ? 'block' : 'warn',
                        'message' => sprintf(
                            '%s — batch %s expired on %s.',
                            $product->name,
                            $batch->batch_number,
                            $batch->expiry_date->format('d M Y'),
                        ),
                    ];

                    continue;
                }

                /**
                 * Short-dated but not expired. Never a block, whatever the
                 * policy says: this stock is legal to sell, the shop paid for it,
                 * and the only sensible counter behaviour is to say so and let
                 * the customer decide. Blocking it would turn a markdown into
                 * a write-off.
                 */
                if ($policy['min_shelf_life_days'] > 0 && $daysLeft < $policy['min_shelf_life_days']) {
                    $issues[] = [
                        'product_id' => $product->id,
                        'product' => $product->name,
                        'batch_number' => $batch->batch_number,
                        'expiry_date' => $batch->expiry_date->toDateString(),
                        'days_left' => $daysLeft,
                        'quantity' => round($take, 3),
                        'severity' => 'warn',
                        'message' => sprintf(
                            '%s — batch %s has %d day(s) left (%s).',
                            $product->name,
                            $batch->batch_number,
                            $daysLeft,
                            $batch->expiry_date->format('d M Y'),
                        ),
                    ];
                }
            }
        }

        $blocking = array_values(array_filter($issues, fn (array $i) => $i['severity'] === 'block'));

        return [
            'policy' => $policy,
            'issues' => $issues,
            'blocked' => $blocking !== [],
            'blocking' => $blocking,
        ];
    }

    /**
     * Refuse a bill carrying expired stock.
     *
     * Called before a single row is written, so a refused sale leaves no stock
     * movement or half-built order behind — the same contract the pharmacy's
     * prescription gate and the toy shop's certificate check keep at the same
     * point in the checkout.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws RuntimeException
     */
    public function assertSellable(array $items, ?int $warehouseId = null): void
    {
        $result = $this->inspect($items, $warehouseId);

        if (! $result['blocked']) {
            return;
        }

        throw new RuntimeException(
            'This bill cannot be completed: '
            .implode(' ', array_column($result['blocking'], 'message'))
            .' Remove the line or issue from a different batch.',
        );
    }

    /** @return array<string, mixed> */
    private function batchRow(Batch $batch, Carbon $today): array
    {
        $daysLeft = (int) $today->diffInDays($batch->expiry_date->copy()->startOfDay(), false);

        return [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'product_id' => $batch->product_id,
            'product' => $batch->product?->name,
            'sku' => $batch->product?->sku,
            'variant' => $batch->variant?->name,
            'warehouse' => $batch->warehouse?->name,
            'expiry_date' => $batch->expiry_date->toDateString(),
            'days_left' => $daysLeft,
            'quantity' => (float) $batch->quantity,
            // What walking away from it costs, which is the number that decides
            // whether it gets marked down or quietly left to die.
            'value' => round((float) $batch->quantity * (float) $batch->unit_cost, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function unitRow(OpenUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'product_id' => $unit->product_id,
            'product' => $unit->product?->name,
            'variant' => $unit->variant?->name,
            'warehouse' => $unit->warehouse?->name,
            'purpose' => $unit->purpose,
            'opened_on' => $unit->opened_on?->toDateString(),
            'pao_months' => $unit->pao_months,
            'expires_on' => $unit->expires_on?->toDateString(),
            'days_left' => $unit->daysLeft(),
            'quantity' => (float) $unit->quantity,
            'status' => $unit->status,
        ];
    }
}
