<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockLedger;
use Illuminate\Http\Request;

class StockLedgerController extends Controller
{
    /** Immutable movement history, most recent first. */
    public function index(Request $request)
    {
        $query = StockLedger::query()->with(['product:id,sku,name', 'warehouse:id,name', 'user:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 20), 200);

        $rows = $query->paginate($perPage);
        $rows->getCollection()->transform(fn ($r) => [
            'id' => $r->id,
            'created_at' => $r->created_at,
            'type' => $r->type,
            'product' => $r->product ? ['id' => $r->product->id, 'sku' => $r->product->sku, 'name' => $r->product->name] : null,
            'warehouse' => $r->warehouse ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : null,
            'quantity_in' => $r->quantity_in,
            'quantity_out' => $r->quantity_out,
            'balance' => $r->balance,
            'unit_cost' => $r->unit_cost,
            'reference_number' => $r->reference_number,
            'user' => $r->user?->name,
        ]);

        return $rows;
    }
}
