<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Batch;
use App\Models\StockLocation;
use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;

/**
 * An ice-cream parlour's view of its own stock: which flavour, in which freezer,
 * and how long it has left.
 *
 * Nothing here is new data. A flavour is a product variant, a freezer is a
 * warehouse location, and shelf life is a batch — all three already existed, which
 * is exactly why this is a projection and not a module. What was missing was the
 * shape: a parlour does not ask "what is the on-hand quantity of SKU ICE-042", it
 * asks "how many tubs of butterscotch are in the front freezer and which one
 * expires first".
 *
 * Read-only by design. Moving a tub between freezers is a stock transfer, and
 * writing a second path for it here would give a parlour two ways to move stock
 * that reconcile differently.
 */
class ParlourStockService
{
    /**
     * The freezers, each with what is in it.
     *
     * @return list<array{
     *     id: int, code: string, name: string, warehouse: string,
     *     target_temp_c: float|null, flavours: int, quantity: float
     * }>
     */
    public function freezers(): array
    {
        $freezers = WarehouseLocation::query()
            ->where('is_freezer', true)
            ->where('is_active', true)
            ->with('warehouse:id,name')
            ->orderBy('code')
            ->get();

        if ($freezers->isEmpty()) {
            return [];
        }

        $stock = StockLocation::query()
            ->whereIn('location_id', $freezers->pluck('id'))
            ->where('quantity', '>', 0)
            ->get()
            ->groupBy('location_id');

        return $freezers->map(function (WarehouseLocation $freezer) use ($stock) {
            $rows = $stock->get($freezer->id) ?? collect();

            return [
                'id' => (int) $freezer->id,
                'code' => (string) $freezer->code,
                'name' => (string) $freezer->name,
                'warehouse' => (string) ($freezer->warehouse?->name ?? '—'),
                'target_temp_c' => $freezer->target_temp_c === null ? null : (float) $freezer->target_temp_c,
                /**
                 * Distinct flavours, not distinct rows: the same flavour can sit in
                 * a freezer as two variants (a tub and a cone pack) and a parlour
                 * counting its range wants one.
                 */
                'flavours' => $rows->unique(
                    fn (StockLocation $r) => $r->product_id.':'.($r->variant_id ?? 0),
                )->count(),
                'quantity' => round((float) $rows->sum('quantity'), 3),
            ];
        })->all();
    }

    /**
     * Flavour-wise stock, optionally within one freezer.
     *
     * Expiry comes from the batch table, which is keyed by warehouse rather than by
     * location — so the date reported is the earliest batch of that flavour in the
     * freezer's warehouse. That is the honest answer available: a parlour that
     * needs per-freezer expiry has to batch its tubs per freezer, and reporting a
     * warehouse date as a freezer date is better than reporting nothing at all
     * about the ice cream that is about to go off.
     *
     * @return list<array{
     *     product_id: int, product: string, variant_id: int|null, flavour: string|null,
     *     freezer_id: int, freezer: string, quantity: float,
     *     nearest_expiry: string|null, days_to_expiry: int|null, batches: int
     * }>
     */
    public function flavourStock(?int $freezerId = null): array
    {
        $freezers = WarehouseLocation::query()
            ->where('is_freezer', true)
            ->when($freezerId, fn ($q) => $q->where('id', $freezerId))
            ->with('warehouse:id,name')
            ->get()
            ->keyBy('id');

        if ($freezers->isEmpty()) {
            return [];
        }

        $rows = StockLocation::query()
            ->whereIn('location_id', $freezers->keys())
            ->where('quantity', '>', 0)
            ->with(['product:id,name', 'variant:id,name,sku'])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $expiry = $this->earliestExpiry(
            $rows->pluck('product_id')->unique()->all(),
            $freezers->pluck('warehouse_id')->unique()->all(),
        );

        return $rows->map(function (StockLocation $row) use ($freezers, $expiry) {
            $freezer = $freezers->get($row->location_id);
            $key = $row->product_id.':'.($row->variant_id ?? 0).':'.$freezer?->warehouse_id;
            $found = $expiry[$key] ?? null;

            return [
                'product_id' => (int) $row->product_id,
                'product' => (string) ($row->product?->name ?? '—'),
                'variant_id' => $row->variant_id ? (int) $row->variant_id : null,
                // The flavour *is* the variant's name — that is what a parlour
                // called it when it created the variant.
                'flavour' => $row->variant?->name,
                'freezer_id' => (int) $row->location_id,
                'freezer' => (string) ($freezer?->name ?? '—'),
                'quantity' => round((float) $row->quantity, 3),
                'nearest_expiry' => $found['date'] ?? null,
                'days_to_expiry' => $found['days'] ?? null,
                'batches' => $found['batches'] ?? 0,
            ];
        })
            // Soonest to expire first: it is the only ordering a parlour manager
            // actually wants, because it is the list of what to sell today.
            ->sortBy(fn (array $r) => $r['days_to_expiry'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * Tubs already past their date, or within `$days` of it.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringSoon(int $days = 14): array
    {
        return array_values(array_filter(
            $this->flavourStock(),
            fn (array $row) => $row['days_to_expiry'] !== null && $row['days_to_expiry'] <= $days,
        ));
    }

    /**
     * Earliest expiry per product/variant/warehouse, with a batch count.
     *
     * @param  list<int>  $productIds
     * @param  list<int>  $warehouseIds
     * @return array<string, array{date: string, days: int, batches: int}>
     */
    private function earliestExpiry(array $productIds, array $warehouseIds): array
    {
        if ($productIds === [] || $warehouseIds === []) {
            return [];
        }

        $batches = Batch::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNotNull('expiry_date')
            ->where('quantity', '>', 0)
            ->get(['product_id', 'variant_id', 'warehouse_id', 'expiry_date']);

        $out = [];

        foreach ($batches as $batch) {
            $key = $batch->product_id.':'.($batch->variant_id ?? 0).':'.$batch->warehouse_id;
            $date = $batch->expiry_date instanceof \DateTimeInterface
                ? $batch->expiry_date->format('Y-m-d')
                : (string) $batch->expiry_date;

            if (! isset($out[$key])) {
                $out[$key] = ['date' => $date, 'batches' => 0];
            }

            $out[$key]['batches']++;
            $out[$key]['date'] = min($out[$key]['date'], $date);
        }

        foreach ($out as $key => $row) {
            $out[$key]['days'] = (int) now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($row['date'])->startOfDay(),
                false,
            );
        }

        /** @var array<string, array{date: string, days: int, batches: int}> $out */
        return $out;
    }

    /**
     * A flat list of freezers for a picker.
     *
     * @return Collection<int, WarehouseLocation>
     */
    public function freezerOptions(): Collection
    {
        return WarehouseLocation::query()
            ->where('is_freezer', true)
            ->where('is_active', true)
            ->with('warehouse:id,name')
            ->orderBy('code')
            ->get(['id', 'warehouse_id', 'code', 'name', 'target_temp_c']);
    }
}
