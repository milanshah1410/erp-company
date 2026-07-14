<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SalesItem;
use App\Models\SalesOrder;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

/**
 * Shared confirmation logic for sales orders: enforce the customer's credit
 * limit, then reserve whatever stock is available per line (flagging the
 * remainder as backorders). Used by both the internal Sales Order "confirm"
 * action and the B2B "approve" action so the two behave identically.
 */
class SalesOrderFulfillmentService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Human-readable error if confirming this order would breach the customer's
     * credit limit, or null when it is within limit (0 limit = unlimited).
     */
    public function creditLimitError(SalesOrder $salesOrder): ?string
    {
        $customer = $salesOrder->customer;
        $limit = (float) $customer->credit_limit;
        if ($limit <= 0) {
            return null;
        }

        $exposure = $customer->outstandingBalance() + (float) $salesOrder->total;
        if ($exposure <= $limit) {
            return null;
        }

        return sprintf(
            'Credit limit exceeded: outstanding ₹%s + this order ₹%s is over the ₹%s limit.',
            number_format($customer->outstandingBalance(), 2),
            number_format((float) $salesOrder->total, 2),
            number_format($limit, 2),
        );
    }

    /**
     * Reserve available stock for every line, stamping reserved/backorder
     * quantities, and set the order to 'confirmed' (or 'backordered' if any
     * line could not be fully covered). Runs in a single transaction.
     */
    public function reserveAndConfirm(SalesOrder $salesOrder): SalesOrder
    {
        $salesOrder->load('items.product');

        DB::transaction(function () use ($salesOrder) {
            $hasBackorder = false;

            foreach ($salesOrder->items as $item) {
                $reserveQty = $this->reserveAvailable($salesOrder->warehouse_id, $item, (float) $item->quantity);
                $item->update([
                    'reserved_quantity' => $reserveQty,
                    'backorder_quantity' => round((float) $item->quantity - $reserveQty, 3),
                ]);
                if ($item->backorder_quantity > 0) {
                    $hasBackorder = true;
                }
            }

            $salesOrder->update(['status' => $hasBackorder ? 'backordered' : 'confirmed']);
        });

        return $salesOrder;
    }

    /** Reserve up to $quantity of a line against available warehouse stock; returns the amount actually reserved. */
    public function reserveAvailable(int $warehouseId, SalesItem $item, float $quantity): float
    {
        $available = (float) WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $item->product_id)
            ->where('variant_id', $item->variant_id)
            ->selectRaw('COALESCE(SUM(quantity - reserved_quantity), 0) as available')
            ->value('available');

        $reserveQty = round(min(max($available, 0), $quantity), 3);
        if ($reserveQty > 0) {
            $this->stock->reserve($warehouseId, $item->product_id, $item->variant_id, $reserveQty);
        }

        return $reserveQty;
    }
}
