<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SerialNumber;
use Illuminate\Http\Request;

class SerialNumberController extends Controller
{
    /** Serial-number browser: current status/location of each tracked unit. */
    public function index(Request $request)
    {
        $query = SerialNumber::query()->with(['product:id,sku,name', 'warehouse:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($search = $request->query('search')) {
            $query->where('serial', 'like', "%{$search}%");
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 20), 200);

        $rows = $query->paginate($perPage);
        $rows->getCollection()->transform(fn (SerialNumber $s) => [
            'id' => $s->id,
            'product' => $s->product ? ['id' => $s->product->id, 'sku' => $s->product->sku, 'name' => $s->product->name] : null,
            'warehouse' => $s->warehouse ? ['id' => $s->warehouse->id, 'name' => $s->warehouse->name] : null,
            'serial' => $s->serial,
            'status' => $s->status,
            'reference_type' => $s->reference_type,
            'reference_id' => $s->reference_id,
            'updated_at' => $s->updated_at,
        ]);

        return $rows;
    }
}
