<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StockCostLayer;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Central authority for all stock movements.
 *
 * Every change to on-hand quantity flows through post(): it appends an
 * immutable stock_ledger row, updates the warehouse_stocks aggregate
 * (quantity + weighted-average cost) and maintains stock_cost_layers,
 * all atomically under a row lock.
 *
 * Outward movements are costed by the company's configured costing method
 * (settings.costing_method): FIFO and LIFO consume receipt-lot layers in
 * order and cost the issue from the lots consumed; weighted average costs
 * the issue at the running average. Layers are drawn down under every
 * method so the company can switch methods at any time without rebuilds.
 *
 * Callers that post several movements as one business action (e.g. a GRN
 * with many lines) should wrap the whole batch in DB::transaction() so the
 * ledger and stock stay consistent on failure.
 */
class StockService
{
    /**
     * Record one stock movement and return the created ledger row.
     *
     * @param  bool  $allowNegative  permit the resulting balance to go below zero
     */
    public function post(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        string $type,
        float $quantityIn,
        float $quantityOut,
        float $unitCost = 0,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?int $userId = null,
        bool $allowNegative = false,
    ): StockLedger {
        return DB::transaction(function () use (
            $warehouseId, $productId, $variantId, $type,
            $quantityIn, $quantityOut, $unitCost,
            $referenceType, $referenceId, $referenceNumber, $notes, $userId, $allowNegative
        ) {
            // Lock the aggregate row so concurrent movements serialize.
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = new WarehouseStock([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'average_cost' => 0,
                ]);
            }

            $currentQty = (float) $stock->quantity;
            $currentAvg = (float) $stock->average_cost;
            $newQty = $currentQty + $quantityIn - $quantityOut;

            if ($newQty < 0 && ! $allowNegative) {
                throw new RuntimeException(
                    "Insufficient stock: on-hand {$currentQty}, requested out {$quantityOut}."
                );
            }

            // The cost this movement is booked at on the ledger. Inward keeps
            // the caller's cost; outward is resolved by the costing method.
            $ledgerCost = $unitCost;
            $layerCost = 0.0;

            if ($quantityIn > 0) {
                // Inward with no cost (e.g. found stock) is valued at the
                // running average so total value stays consistent.
                $layerCost = $unitCost > 0 ? $unitCost : $currentAvg;

                // The weighted average is always maintained, regardless of
                // method: it feeds average costing, valuation reports and the
                // shortfall fallback below.
                if ($layerCost > 0) {
                    $totalValue = ($currentQty * $currentAvg) + ($quantityIn * $layerCost);
                    $stock->average_cost = $currentQty + $quantityIn > 0
                        ? round($totalValue / ($currentQty + $quantityIn), 4)
                        : $layerCost;
                }

                $ledgerCost = $ledgerCost ?: (float) $stock->average_cost;
            }

            if ($quantityOut > 0) {
                $ledgerCost = $this->consumeLayers(
                    $warehouseId, $productId, $variantId,
                    $quantityOut, (float) $stock->average_cost,
                );
            }

            $stock->quantity = $newQty;
            $stock->save();

            $ledger = StockLedger::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'type' => $type,
                'quantity_in' => $quantityIn,
                'quantity_out' => $quantityOut,
                'balance' => $newQty,
                'unit_cost' => round($ledgerCost, 4),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            if ($quantityIn > 0) {
                StockCostLayer::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'stock_ledger_id' => $ledger->id,
                    'unit_cost' => round($layerCost, 4),
                    'quantity' => $quantityIn,
                    'remaining_quantity' => $quantityIn,
                    'created_at' => now(),
                ]);
            }

            return $ledger;
        });
    }

    /**
     * Draw $quantity down from cost layers and return the unit cost of the
     * issue under the company's costing method.
     *
     * FIFO consumes oldest layers first, LIFO newest first; the issue is
     * costed from the lots actually consumed. Weighted average also drains
     * layers (oldest first, to keep them current for a later method switch)
     * but costs the issue at the running average. Any shortfall — layers out
     * of sync or negative stock allowed — is costed at the running average.
     */
    private function consumeLayers(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $quantity,
        float $averageCost,
    ): float {
        $method = Setting::costingMethod();

        $layers = StockCostLayer::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('id', $method === Setting::COSTING_LIFO ? 'desc' : 'asc')
            ->lockForUpdate()
            ->get();

        $toConsume = $quantity;
        $consumedValue = 0.0;

        foreach ($layers as $layer) {
            if ($toConsume <= 0) {
                break;
            }
            $take = min($toConsume, (float) $layer->remaining_quantity);
            $layer->remaining_quantity = round((float) $layer->remaining_quantity - $take, 3);
            $layer->save();

            $consumedValue += $take * (float) $layer->unit_cost;
            $toConsume = round($toConsume - $take, 3);
        }

        // Shortfall: no layers left to cover the issue.
        if ($toConsume > 0) {
            $consumedValue += $toConsume * $averageCost;
        }

        if ($method === Setting::COSTING_WEIGHTED_AVERAGE) {
            return $averageCost;
        }

        return $quantity > 0 ? round($consumedValue / $quantity, 4) : 0.0;
    }

    /** Convenience: inward movement (purchase, return-in, transfer-in, opening). */
    public function inward(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $quantity,
        float $unitCost,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?int $userId = null,
    ): StockLedger {
        return $this->post(
            $warehouseId, $productId, $variantId, $type,
            $quantity, 0, $unitCost,
            $referenceType, $referenceId, $referenceNumber, null, $userId,
        );
    }

    /** Convenience: outward movement (sale, damage, transfer-out, consumption). */
    public function outward(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        float $quantity,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?int $userId = null,
        bool $allowNegative = false,
    ): StockLedger {
        return $this->post(
            $warehouseId, $productId, $variantId, $type,
            0, $quantity, 0,
            $referenceType, $referenceId, $referenceNumber, null, $userId, $allowNegative,
        );
    }

    /**
     * Commit quantity against a warehouse's on-hand stock without moving it
     * (e.g. a confirmed sales order line). Not a ledger event.
     */
    public function reserve(int $warehouseId, int $productId, ?int $variantId, float $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($warehouseId, $productId, $variantId, $quantity) {
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = new WarehouseStock([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'average_cost' => 0,
                ]);
            }

            $stock->reserved_quantity = (float) $stock->reserved_quantity + $quantity;
            $stock->save();
        });
    }

    /** Release a previously reserved quantity (delivery, cancellation). Clamped at zero. */
    public function release(int $warehouseId, int $productId, ?int $variantId, float $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($warehouseId, $productId, $variantId, $quantity) {
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                return;
            }

            $stock->reserved_quantity = max(0, (float) $stock->reserved_quantity - $quantity);
            $stock->save();
        });
    }
}
