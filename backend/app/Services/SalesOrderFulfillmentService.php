<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesItem;
use App\Models\SalesOrder;
use App\Models\WarehouseStock;
use App\Modules\Industry\Distribution\Services\CreditControlService;
use Illuminate\Support\Facades\DB;

/**
 * Shared confirmation logic for sales orders: enforce the customer's credit
 * limit, then reserve whatever stock is available per line (flagging the
 * remainder as backorders). Used by both the internal Sales Order "confirm"
 * action and the B2B "approve" action so the two behave identically.
 */
class SalesOrderFulfillmentService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CreditControlService $credit,
    ) {}

    /**
     * Human-readable reason this order must not be confirmed, or null when it
     * may go ahead.
     *
     * The rule itself lives in CreditControlService (Distribution & Wholesale
     * plugin) rather than here, because it is asked in four places — this
     * confirm, the B2B approve, the POS counter and the despatch door — and four
     * copies of "is this party good for it?" is how a wholesaler ends up
     * supplying a party they had stopped. The default policy reproduces exactly
     * what this method used to do; the overdue-age and supply-hold rules only
     * bite once a workspace configures them.
     */
    public function creditLimitError(SalesOrder $salesOrder): ?string
    {
        return $this->credit->blockReason($salesOrder->customer, (float) $salesOrder->total);
    }

    /**
     * The same question asked of a party rather than of a document, for the
     * despatch gate — where the order's own value is already part of the party's
     * exposure and must not be counted twice.
     */
    public function creditBlockReason(Customer $customer): ?string
    {
        return $this->credit->blockReason($customer);
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
