<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\SerialNumber;
use RuntimeException;

/**
 * Keeps Batch quantities and SerialNumber statuses in sync with outward/
 * inward stock movements, alongside (not instead of) StockService.
 *
 * Only relevant for products with track_batch / track_serial enabled;
 * callers should check those flags before invoking these methods.
 */
class InventoryTrackingService
{
    /**
     * FEFO-consume $quantity from a product's batches at a warehouse.
     * Locks the candidate rows, drains oldest-expiry-first, and returns the
     * breakdown consumed for storing as an audit trail (batch_allocations).
     *
     * @return array<int, array{batch_id: int, batch_number: string, expiry_date: ?string, quantity: float}>
     */
    public function consumeBatchFefo(int $productId, ?int $variantId, int $warehouseId, float $quantity): array
    {
        $batches = Batch::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $allocations = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $batch->quantity);
            $batch->decrement('quantity', $take);
            $allocations[] = [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => $take,
            ];
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0) {
            throw new RuntimeException(
                "Insufficient batch-tracked stock for product #{$productId} at warehouse #{$warehouseId}: short by {$remaining}."
            );
        }

        return $allocations;
    }

    /**
     * Flip a set of serials to $newStatus, validating each currently sits in
     * one of $fromStatuses at the given warehouse.
     */
    public function consumeSerials(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        array $serials,
        string $newStatus,
        string $referenceType,
        int $referenceId,
        array $fromStatuses = ['in_stock'],
    ): void {
        $serials = array_values(array_unique(array_map('trim', $serials)));
        if (empty($serials)) {
            return;
        }

        $rows = SerialNumber::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('serial', $serials)
            ->whereIn('status', $fromStatuses)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial');

        $missing = array_diff($serials, $rows->keys()->all());
        if (! empty($missing)) {
            throw new RuntimeException(
                'Serial number(s) not available at this warehouse: '.implode(', ', $missing).'.'
            );
        }

        foreach ($rows as $row) {
            $row->update([
                'status' => $newStatus,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        }
    }

    /**
     * Recreate/increment destination-warehouse batch rows from a
     * batch_allocations breakdown captured at dispatch time.
     */
    public function receiveBatchAllocations(int $productId, ?int $variantId, int $warehouseId, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            $batch = Batch::firstOrCreate(
                [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'batch_number' => $allocation['batch_number'],
                ],
                [
                    'expiry_date' => $allocation['expiry_date'] ?? null,
                    'unit_cost' => 0,
                ],
            );
            $batch->increment('quantity', (float) $allocation['quantity']);
        }
    }

    /** Move previously in-transit serials to the destination warehouse. */
    public function moveSerialsToWarehouse(array $serials, int $productId, ?int $variantId, int $warehouseId, string $newStatus): void
    {
        $serials = array_values(array_unique(array_map('trim', $serials)));
        if (empty($serials)) {
            return;
        }

        SerialNumber::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->whereIn('serial', $serials)
            ->update(['warehouse_id' => $warehouseId, 'status' => $newStatus]);
    }
}
