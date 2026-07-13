<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    /** Batch/lot browser: on-hand quantity per batch, with expiry. */
    public function index(Request $request)
    {
        $query = Batch::query()->with(['product:id,sku,name', 'warehouse:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->boolean('in_stock_only', true)) {
            $query->where('quantity', '>', 0);
        }
        if ($request->filled('expiring_before')) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '<=', $request->query('expiring_before'));
        }

        $query->orderBy('expiry_date');
        $perPage = min((int) $request->query('per_page', 20), 200);

        $rows = $query->paginate($perPage);
        $rows->getCollection()->transform(fn (Batch $b) => [
            'id' => $b->id,
            'product' => $b->product ? ['id' => $b->product->id, 'sku' => $b->product->sku, 'name' => $b->product->name] : null,
            'warehouse' => $b->warehouse ? ['id' => $b->warehouse->id, 'name' => $b->warehouse->name] : null,
            'batch_number' => $b->batch_number,
            'expiry_date' => $b->expiry_date?->toDateString(),
            'quantity' => $b->quantity,
            'unit_cost' => $b->unit_cost,
            'is_expired' => $b->expiry_date && $b->expiry_date->isPast(),
            'days_to_expiry' => $b->expiry_date ? now()->startOfDay()->diffInDays($b->expiry_date, false) : null,
        ]);

        return $rows;
    }
}
