<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use Illuminate\Http\Request;

class StockController extends Controller
{
    /** Current on-hand stock per warehouse per product. */
    public function index(Request $request)
    {
        $query = WarehouseStock::query()->with(['product:id,sku,name,reorder_level', 'warehouse:id,name,code']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($search = $request->query('search')) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }
        // Low-stock filter: on-hand at or below the product's reorder level.
        if ($request->boolean('low_stock')) {
            $query->whereHas('product', function ($q) {
                $q->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')
                    ->where('reorder_level', '>', 0);
            });
        }

        $query->orderByDesc('quantity');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $stocks = $query->paginate($perPage);
        $stocks->getCollection()->transform(fn ($s) => [
            'id' => $s->id,
            'warehouse_id' => $s->warehouse_id,
            'warehouse' => $s->warehouse ? ['id' => $s->warehouse->id, 'name' => $s->warehouse->name, 'code' => $s->warehouse->code] : null,
            'product_id' => $s->product_id,
            'product' => $s->product ? ['id' => $s->product->id, 'sku' => $s->product->sku, 'name' => $s->product->name] : null,
            'variant_id' => $s->variant_id,
            'quantity' => $s->quantity,
            'reserved_quantity' => $s->reserved_quantity,
            'available_quantity' => $s->availableQuantity(),
            'average_cost' => $s->average_cost,
            'stock_value' => round((float) $s->quantity * (float) $s->average_cost, 2),
            'is_low' => $s->product && (float) $s->product->reorder_level > 0
                && (float) $s->quantity <= (float) $s->product->reorder_level,
        ]);

        return $stocks;
    }
}
