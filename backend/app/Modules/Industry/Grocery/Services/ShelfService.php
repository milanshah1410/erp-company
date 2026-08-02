<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Services;

use App\Models\Batch;
use App\Models\StockLocation;
use App\Models\WarehouseLocation;
use App\Modules\Industry\Grocery\Models\ShelfPlacement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shop floor as a supermarket sees it: aisles, shelves, what belongs on each
 * and what is missing from it right now.
 *
 * **Why replenishment writes stock here when the parlour screen refused to.**
 * ParlourStockService is read-only on principle — moving a tub between freezers
 * is a stock transfer, and a second path for it would reconcile differently. The
 * difference is that a transfer moves stock between *warehouses*, and filling a
 * shelf does not: the backroom and the shelf are two locations inside one store,
 * so the warehouse's on-hand is identical before and after. There is no existing
 * path for that movement at all — `StockLocation` rows are written once, at goods
 * receipt, and never moved again — so this is filling a gap rather than opening a
 * second door onto something core already does.
 *
 * That also bounds what a replenishment may touch: two `stock_locations` rows and
 * nothing else. It writes no stock ledger entry and does not go near
 * `warehouse_stocks`, because nothing has entered or left the store. A shelf fill
 * that moved the warehouse balance would double-count every item in the building.
 */
class ShelfService
{
    /**
     * The aisles, and the shelves in each, with fill state rolled up.
     *
     * Grouped by aisle rather than returned flat because that is the unit a
     * supermarket walks: a replenishment round is worked one aisle at a time, and
     * a list of ninety shelves in code order is nobody's job.
     *
     * @return list<array<string, mixed>>
     */
    public function aisles(?int $warehouseId = null): array
    {
        $shelves = WarehouseLocation::query()
            ->where('is_shelf', true)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->with('warehouse:id,name')
            ->orderBy('aisle')
            ->orderBy('bay')
            ->orderBy('code')
            ->get();

        if ($shelves->isEmpty()) {
            return [];
        }

        $placements = ShelfPlacement::query()
            ->whereIn('location_id', $shelves->pluck('id'))
            ->where('is_active', true)
            ->get()
            ->groupBy('location_id');

        $stock = $this->shelfStock($shelves->pluck('id')->all());

        $rows = $shelves->map(function (WarehouseLocation $shelf) use ($placements, $stock) {
            $lines = $placements->get($shelf->id) ?? collect();
            $gapped = $lines->filter(
                fn (ShelfPlacement $p) => $this->stockFor($stock, $p) < (float) $p->min_qty,
            );

            return [
                'id' => (int) $shelf->id,
                'code' => $shelf->code,
                'name' => $shelf->name,
                'warehouse_id' => (int) $shelf->warehouse_id,
                'warehouse_name' => $shelf->warehouse?->name,
                // The shopper-facing address. Assembled here so a shelf-edge
                // label and a picking list read the same string.
                'aisle' => $shelf->aisle,
                'bay' => $shelf->bay,
                'shelf_level' => $shelf->shelf_level,
                'address' => $this->address($shelf),
                'is_active' => (bool) $shelf->is_active,
                'lines' => $lines->count(),
                'facings' => (int) $lines->sum('facings'),
                'gapped_lines' => $gapped->count(),
                // Empty is worse than low and a round should be walked in that
                // order, so the two are counted separately.
                'empty_lines' => $lines->filter(
                    fn (ShelfPlacement $p) => $this->stockFor($stock, $p) <= 0,
                )->count(),
            ];
        });

        return $rows->groupBy(fn (array $r) => $r['aisle'] ?? '—')
            ->map(fn ($shelves, $aisle) => [
                'aisle' => $aisle,
                'shelves' => $shelves->values()->all(),
                'lines' => $shelves->sum('lines'),
                'gapped_lines' => $shelves->sum('gapped_lines'),
                'empty_lines' => $shelves->sum('empty_lines'),
            ])
            ->values()
            ->all();
    }

    /**
     * The planogram for one shelf, line by line, with what is on it.
     *
     * @return list<array<string, mixed>>
     */
    public function planogram(WarehouseLocation $shelf): array
    {
        $placements = ShelfPlacement::query()
            ->where('location_id', $shelf->id)
            ->with(['product:id,sku,name,selling_price,tax_percent,barcode', 'variant:id,sku,attributes'])
            ->orderBy('sequence')
            ->get();

        $stock = $this->shelfStock([$shelf->id]);

        return $placements->map(fn (ShelfPlacement $p) => $this->presentLine($p, $shelf, $this->stockFor($stock, $p)))->all();
    }

    /**
     * The replenishment round: every planogram line sitting below its minimum,
     * worst first.
     *
     * "Worst" is measured as a proportion of the minimum rather than as a raw
     * shortfall, because a shelf that should hold six loaves and holds none is
     * more urgent than one that should hold four hundred tins and holds three
     * hundred — and a picking round has to be walked in the order that keeps the
     * most shoppers from finding a hole.
     *
     * @return list<array<string, mixed>>
     */
    public function gaps(?int $warehouseId = null, ?int $shelfId = null): array
    {
        $shelves = WarehouseLocation::query()
            ->where('is_shelf', true)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($shelfId, fn ($q) => $q->where('id', $shelfId))
            ->get()
            ->keyBy('id');

        if ($shelves->isEmpty()) {
            return [];
        }

        $placements = ShelfPlacement::query()
            ->whereIn('location_id', $shelves->keys())
            ->where('is_active', true)
            ->with(['product:id,sku,name,selling_price,tax_percent,barcode', 'variant:id,sku,attributes'])
            ->get();

        $stock = $this->shelfStock($shelves->keys()->all());

        $gaps = [];

        foreach ($placements as $placement) {
            $onShelf = $this->stockFor($stock, $placement);
            $min = (float) $placement->min_qty;

            if ($onShelf >= $min) {
                continue;
            }

            $line = $this->presentLine($placement, $shelves[$placement->location_id], $onShelf);
            // Sorted on, so computed once here rather than inside the comparator.
            $line['urgency'] = $min > 0 ? round(1 - ($onShelf / $min), 4) : 1.0;
            $gaps[] = $line;
        }

        usort($gaps, static fn (array $a, array $b) => $b['urgency'] <=> $a['urgency']);

        return $gaps;
    }

    /**
     * Move stock from the backroom onto a shelf.
     *
     * Two `stock_locations` rows and nothing else — see the class docblock for
     * why that is the whole of it. Wrapped in a transaction with a row lock on
     * the source, because two staff working the same aisle from opposite ends is
     * the normal case in a supermarket, not the edge case.
     *
     * @return array{from: float, to: float, quantity: float}
     */
    public function replenish(
        ShelfPlacement $placement,
        float $quantity,
        ?int $fromLocationId = null,
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('Replenishment quantity must be greater than zero.');
        }

        $shelf = $placement->location;

        if (! $shelf || ! $shelf->is_shelf) {
            throw new RuntimeException('That placement is not on a shelf.');
        }

        return DB::transaction(function () use ($placement, $quantity, $fromLocationId, $shelf) {
            $sourceId = $fromLocationId ?? $this->suggestSource($placement)?->id;

            if (! $sourceId) {
                throw new RuntimeException(
                    'Nothing to pick from: no other location in this store holds '.
                    ($placement->product?->name ?? 'this product').'.',
                );
            }

            if ((int) $sourceId === (int) $shelf->id) {
                throw new RuntimeException('The pick location and the shelf are the same place.');
            }

            $source = WarehouseLocation::find($sourceId);

            if (! $source || $source->warehouse_id !== $shelf->warehouse_id) {
                // Across warehouses this is a transfer, and Inventory already
                // owns that document — sending it here would give the shop two
                // ways to move stock between stores.
                throw new RuntimeException('Pick and shelf must be in the same store. Use a stock transfer to move between stores.');
            }

            $from = StockLocation::query()
                ->where('location_id', $source->id)
                ->where('product_id', $placement->product_id)
                ->when(
                    $placement->variant_id === null,
                    static fn ($q) => $q->whereNull('variant_id'),
                    fn ($q) => $q->where('variant_id', $placement->variant_id),
                )
                ->lockForUpdate()
                ->first();

            $available = round((float) ($from->quantity ?? 0), 3);

            if ($available < $quantity) {
                throw new RuntimeException(
                    "{$source->code} holds only {$available} — cannot pick {$quantity}.",
                );
            }

            $from->quantity = round($available - $quantity, 3);
            $from->save();

            $to = StockLocation::firstOrNew([
                'location_id' => $shelf->id,
                'product_id' => $placement->product_id,
                'variant_id' => $placement->variant_id,
            ]);
            $to->quantity = round((float) ($to->quantity ?? 0) + $quantity, 3);
            $to->save();

            return [
                'from' => (float) $from->quantity,
                'to' => (float) $to->quantity,
                'quantity' => round($quantity, 3),
            ];
        });
    }

    /**
     * Where to pick from when the caller did not say.
     *
     * The non-shelf location in the same store holding the most of the line —
     * which in practice is the backroom, without making every shop configure
     * which location that is. Shelves are excluded so a round never robs one
     * gapped facing to fill another.
     */
    public function suggestSource(ShelfPlacement $placement): ?WarehouseLocation
    {
        $shelf = $placement->location;

        if (! $shelf) {
            return null;
        }

        $row = StockLocation::query()
            ->where('product_id', $placement->product_id)
            ->when(
                $placement->variant_id === null,
                static fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $placement->variant_id),
            )
            ->where('quantity', '>', 0)
            ->whereHas('location', fn ($q) => $q
                ->where('warehouse_id', $shelf->warehouse_id)
                ->where('is_shelf', false)
                ->where('is_active', true))
            ->orderByDesc('quantity')
            ->first();

        return $row?->location;
    }

    /**
     * Shelf-edge labels for one shelf: what to print, in the order the bay is
     * walked.
     *
     * Deliberately returns data rather than a rendered label — the label
     * templates are core (§21 LabelPrintingView) and a supermarket prints on the
     * same stock as everyone else. What was missing was only the *list*: which
     * price tags this bay needs, in sequence, so a re-lay does not become an
     * exercise in hunting products one at a time.
     *
     * @return list<array<string, mixed>>
     */
    public function labelRun(WarehouseLocation $shelf): array
    {
        return collect($this->planogram($shelf))
            ->filter(fn (array $line) => $line['is_active'])
            ->map(fn (array $line) => [
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'sku' => $line['sku'],
                'name' => $line['name'],
                'barcode' => $line['barcode'],
                'price' => $line['price'],
                'facings' => $line['facings'],
                'address' => $line['address'],
            ])
            ->values()
            ->all();
    }

    /**
     * What is on the shelf and expiring soonest.
     *
     * Batch and expiry are core Inventory and are not re-implemented here; this
     * only answers the shelf-shaped question core cannot, which is "what is about
     * to go out of date *on the floor*" as opposed to anywhere in the building. A
     * date-check round is walked down the aisle, not through a stock report.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(int $withinDays = 14, ?int $warehouseId = null): array
    {
        $shelves = WarehouseLocation::query()
            ->where('is_shelf', true)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->keyBy('id');

        if ($shelves->isEmpty()) {
            return [];
        }

        $onShelf = StockLocation::query()
            ->whereIn('location_id', $shelves->keys())
            ->where('quantity', '>', 0)
            ->with('product:id,sku,name')
            ->get();

        if ($onShelf->isEmpty()) {
            return [];
        }

        $batches = Batch::query()
            ->whereIn('product_id', $onShelf->pluck('product_id')->unique())
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($withinDays))
            ->orderBy('expiry_date')
            ->get()
            ->groupBy('product_id');

        $rows = [];

        foreach ($onShelf as $stock) {
            $productBatches = $batches->get($stock->product_id);

            if (! $productBatches || $productBatches->isEmpty()) {
                continue;
            }

            $batch = $productBatches->first();
            $shelf = $shelves[$stock->location_id];

            $rows[] = [
                'shelf_id' => (int) $shelf->id,
                'shelf' => $shelf->name,
                'address' => $this->address($shelf),
                'product_id' => (int) $stock->product_id,
                'variant_id' => $stock->variant_id ? (int) $stock->variant_id : null,
                'product' => $stock->product?->name,
                'sku' => $stock->product?->sku,
                'quantity' => (float) $stock->quantity,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'days_to_expiry' => $batch->expiry_date
                    ? (int) now()->startOfDay()->diffInDays($batch->expiry_date->startOfDay(), false)
                    : null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => ($a['days_to_expiry'] ?? PHP_INT_MAX) <=> ($b['days_to_expiry'] ?? PHP_INT_MAX));

        return $rows;
    }

    // --- internals -------------------------------------------------------------

    /**
     * Shelf stock for a set of locations, keyed so a placement can find its own
     * row without a query each.
     *
     * @param  list<int>  $locationIds
     * @return array<string, float>
     */
    private function shelfStock(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return StockLocation::query()
            ->whereIn('location_id', $locationIds)
            ->get()
            ->mapWithKeys(static fn (StockLocation $s) => [
                $s->location_id.':'.$s->product_id.':'.($s->variant_id ?? 0) => (float) $s->quantity,
            ])
            ->all();
    }

    /** @param  array<string, float>  $stock */
    private function stockFor(array $stock, ShelfPlacement $placement): float
    {
        $key = $placement->location_id.':'.$placement->product_id.':'.($placement->variant_id ?? 0);

        return round((float) ($stock[$key] ?? 0), 3);
    }

    /** "Aisle 7 · Bay 3 · L2", skipping whatever has not been filled in. */
    private function address(WarehouseLocation $shelf): string
    {
        $parts = array_filter([
            $shelf->aisle ? "Aisle {$shelf->aisle}" : null,
            $shelf->bay ? "Bay {$shelf->bay}" : null,
            $shelf->shelf_level ? "L{$shelf->shelf_level}" : null,
        ]);

        return $parts === [] ? $shelf->code : implode(' · ', $parts);
    }

    /**
     * One planogram line as every screen wants it.
     *
     * @return array<string, mixed>
     */
    private function presentLine(ShelfPlacement $placement, WarehouseLocation $shelf, float $onShelf): array
    {
        $min = (float) $placement->min_qty;
        $max = (float) $placement->max_qty;

        return [
            'id' => (int) $placement->id,
            'location_id' => (int) $placement->location_id,
            'shelf' => $shelf->name,
            'address' => $this->address($shelf),
            'product_id' => (int) $placement->product_id,
            'variant_id' => $placement->variant_id ? (int) $placement->variant_id : null,
            'sku' => $placement->variant?->sku ?? $placement->product?->sku,
            'name' => $placement->product?->name,
            'barcode' => $placement->product?->barcode,
            'price' => (float) ($placement->product?->selling_price ?? 0),
            'facings' => (int) $placement->facings,
            'min_qty' => $min,
            'max_qty' => $max,
            'sequence' => (int) $placement->sequence,
            'is_active' => (bool) $placement->is_active,
            'on_shelf' => $onShelf,
            // What to bring. Filling to the maximum rather than to the minimum:
            // a picker who tops a shelf up to the point at which it is no longer
            // gapped will be back within the hour.
            'suggested_qty' => $max > $onShelf ? round($max - $onShelf, 3) : 0.0,
            'status' => match (true) {
                $onShelf <= 0 => 'empty',
                $onShelf < $min => 'low',
                default => 'ok',
            },
        ];
    }
}
