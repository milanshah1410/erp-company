<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\SupplierResource;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return SupplierResource::collection($query->paginate($perPage));
    }

    public function store(SupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Supplier $supplier)
    {
        return new SupplierResource($supplier);
    }

    public function update(SupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists()) {
            return response()->json([
                'message' => 'This supplier has purchase orders and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    /**
     * Purchase history for a supplier: paginated orders plus a summary
     * block (order counts, lifetime value, pending exposure).
     */
    public function purchaseHistory(Request $request, Supplier $supplier)
    {
        $base = PurchaseOrder::query()->where('supplier_id', $supplier->id);

        $summary = [
            'total_orders' => (clone $base)->count(),
            'total_value' => (float) (clone $base)->whereNot('status', 'cancelled')->sum('total'),
            'open_orders' => (clone $base)->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])->count(),
            'open_value' => (float) (clone $base)->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])->sum('total'),
            'last_order_date' => (clone $base)->max('order_date'),
            'credit_limit' => (float) $supplier->credit_limit,
            'payment_terms_days' => (int) $supplier->payment_terms_days,
        ];

        $query = $base->with(['warehouse:id,name'])->withCount('goodsReceipts');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PurchaseOrderResource::collection($query->paginate($perPage))
            ->additional(['summary' => $summary]);
    }
}
