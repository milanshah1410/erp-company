<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\WarehouseStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReorderController extends Controller
{
    /** Products at or below their reorder level, per warehouse, with a suggested order quantity. */
    public function suggestions(Request $request)
    {
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;

        return response()->json(['data' => $this->computeSuggestions($warehouseId)->values()]);
    }

    /**
     * Create one draft purchase order per (supplier, warehouse) group from
     * the current low-stock suggestions. Products with no preferred
     * supplier are skipped and reported back.
     */
    public function generate(Request $request)
    {
        $suggestions = $this->computeSuggestions();
        $needsSupplier = $suggestions->filter(fn ($s) => ! $s['supplier'])->values();
        $groups = $suggestions->filter(fn ($s) => $s['supplier'])->groupBy(fn ($s) => $s['supplier']['id'].'-'.$s['warehouse']['id']);

        $created = DB::transaction(function () use ($groups) {
            $orders = [];
            foreach ($groups as $group) {
                $first = $group->first();
                $po = PurchaseOrder::create([
                    'po_number' => PurchaseOrder::nextNumber(),
                    'supplier_id' => $first['supplier']['id'],
                    'warehouse_id' => $first['warehouse']['id'],
                    'status' => 'draft',
                    'order_date' => now()->toDateString(),
                    'notes' => 'Auto-generated from reorder suggestions.',
                ]);

                $subtotal = 0;
                $taxTotal = 0;
                foreach ($group as $line) {
                    $qty = $line['suggested_quantity'];
                    $cost = $line['cost_price'];
                    $taxPercent = $line['tax_percent'];
                    $lineBase = $qty * $cost;
                    $lineTax = round($lineBase * $taxPercent / 100, 2);

                    $po->items()->create([
                        'product_id' => $line['product']['id'],
                        'quantity' => $qty,
                        'received_quantity' => 0,
                        'unit_cost' => $cost,
                        'tax_percent' => $taxPercent,
                        'tax_amount' => $lineTax,
                        'line_total' => round($lineBase + $lineTax, 2),
                    ]);
                    $subtotal += $lineBase;
                    $taxTotal += $lineTax;
                }

                $po->update([
                    'subtotal' => round($subtotal, 2),
                    'tax_amount' => round($taxTotal, 2),
                    'total' => round($subtotal + $taxTotal, 2),
                ]);

                $orders[] = $po->load('items.product', 'supplier', 'warehouse');
            }

            return $orders;
        });

        return response()->json([
            'created' => $created,
            'skipped_needs_supplier' => $needsSupplier,
        ], 201);
    }

    /** @return \Illuminate\Support\Collection */
    private function computeSuggestions(?int $warehouseId = null)
    {
        return WarehouseStock::query()
            ->with(['product.preferredSupplier', 'warehouse:id,name'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->filter(fn (WarehouseStock $s) => $s->product
                && (float) $s->product->reorder_level > 0
                && (float) $s->quantity <= (float) $s->product->reorder_level)
            ->map(function (WarehouseStock $s) {
                $product = $s->product;
                $suggestedQty = (float) $product->reorder_quantity > 0
                    ? (float) $product->reorder_quantity
                    : max((float) $product->max_stock_level - (float) $s->quantity, (float) $product->reorder_level);

                return [
                    'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                    'warehouse' => ['id' => $s->warehouse->id, 'name' => $s->warehouse->name],
                    'on_hand' => $s->quantity,
                    'reorder_level' => $product->reorder_level,
                    'suggested_quantity' => round($suggestedQty, 3),
                    'cost_price' => (float) $product->cost_price,
                    'tax_percent' => (float) $product->tax_percent,
                    'supplier' => $product->preferredSupplier
                        ? ['id' => $product->preferredSupplier->id, 'name' => $product->preferredSupplier->name]
                        : null,
                ];
            });
    }
}
