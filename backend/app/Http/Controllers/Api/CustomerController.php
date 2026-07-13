<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();

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

        return CustomerResource::collection($query->paginate($perPage));
    }

    public function store(CustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->salesOrders()->exists()) {
            return response()->json([
                'message' => 'This customer has sales orders and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    /**
     * Purchase history for a customer: paginated sales orders plus a
     * summary block including the live outstanding balance.
     */
    public function purchaseHistory(Request $request, Customer $customer)
    {
        $base = SalesOrder::query()->where('customer_id', $customer->id);

        $summary = [
            'total_orders' => (clone $base)->count(),
            'total_value' => (float) (clone $base)->whereNot('status', 'cancelled')->sum('total'),
            'open_orders' => (clone $base)->whereIn('status', ['draft', 'confirmed'])->count(),
            'open_value' => (float) (clone $base)->whereIn('status', ['draft', 'confirmed'])->sum('total'),
            'last_order_date' => (clone $base)->max('order_date'),
            'credit_limit' => (float) $customer->credit_limit,
            'outstanding_balance' => $customer->outstandingBalance(),
        ];

        $query = $base->with(['warehouse:id,name', 'invoice']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesOrderResource::collection($query->paginate($perPage))
            ->additional(['summary' => $summary]);
    }
}
