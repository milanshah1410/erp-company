<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DispatchPlanRequest;
use App\Http\Resources\DispatchPlanResource;
use App\Models\DispatchPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch planning — schedules a vehicle + driver + route to deliver a set of
 * consignments (dispatch items) to customers.
 *
 * Lifecycle: draft → dispatched → delivered, with cancel available while draft.
 * Per-consignment Proof of Delivery is recorded via ProofOfDeliveryController.
 */
class DispatchPlanController extends Controller
{
    public function index(Request $request)
    {
        $query = DispatchPlan::query()
            ->with(['vehicle:id,registration_no,name', 'driver:id,name', 'route:id,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('dispatch_number', 'like', "%{$search}%");
        }
        foreach (['status', 'vehicle_id', 'driver_id', 'route_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('dispatch_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('dispatch_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return DispatchPlanResource::collection($query->paginate($perPage));
    }

    public function store(DispatchPlanRequest $request)
    {
        $data = $request->validated();

        $plan = DB::transaction(function () use ($request, $data) {
            $plan = DispatchPlan::create([
                'dispatch_number' => DispatchPlan::nextNumber(),
                'dispatch_date' => $data['dispatch_date'],
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'route_id' => $data['route_id'] ?? null,
                'status' => 'draft',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($plan, $data['items']);

            return $plan;
        });

        return (new DispatchPlanResource($plan->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(DispatchPlan $dispatchPlan)
    {
        return new DispatchPlanResource($dispatchPlan->load($this->relations()));
    }

    public function update(DispatchPlanRequest $request, DispatchPlan $dispatchPlan)
    {
        if ($dispatchPlan->status !== 'draft') {
            return response()->json(['message' => 'Only draft dispatch plans can be edited.'], 422);
        }
        $data = $request->validated();

        DB::transaction(function () use ($dispatchPlan, $data) {
            $dispatchPlan->update([
                'dispatch_date' => $data['dispatch_date'],
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'route_id' => $data['route_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $dispatchPlan->items()->delete();
            $this->syncItems($dispatchPlan, $data['items']);
        });

        return new DispatchPlanResource($dispatchPlan->load($this->relations()));
    }

    public function destroy(DispatchPlan $dispatchPlan)
    {
        if ($dispatchPlan->status !== 'draft') {
            return response()->json(['message' => 'Only draft dispatch plans can be deleted.'], 422);
        }
        $dispatchPlan->delete();

        return response()->json(['message' => 'Dispatch plan deleted.']);
    }

    /** Dispatch: the vehicle leaves — plan moves draft → dispatched. */
    public function dispatchPlan(Request $request, DispatchPlan $dispatchPlan)
    {
        if ($dispatchPlan->status !== 'draft') {
            return response()->json(['message' => 'Only draft dispatch plans can be dispatched.'], 422);
        }
        $dispatchPlan->update([
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);

        return new DispatchPlanResource($dispatchPlan->load($this->relations()));
    }

    /** Mark the whole run delivered — pending consignments are closed off. */
    public function deliver(Request $request, DispatchPlan $dispatchPlan)
    {
        if ($dispatchPlan->status !== 'dispatched') {
            return response()->json(['message' => 'Only dispatched plans can be marked delivered.'], 422);
        }

        DB::transaction(function () use ($dispatchPlan) {
            $dispatchPlan->items()
                ->where('delivery_status', 'pending')
                ->update(['delivery_status' => 'delivered', 'delivered_at' => now()]);
            $dispatchPlan->update(['status' => 'delivered', 'delivered_at' => now()]);
        });

        return new DispatchPlanResource($dispatchPlan->load($this->relations()));
    }

    public function cancel(DispatchPlan $dispatchPlan)
    {
        if ($dispatchPlan->status !== 'draft') {
            return response()->json(['message' => 'Only draft dispatch plans can be cancelled.'], 422);
        }
        $dispatchPlan->update(['status' => 'cancelled']);

        return new DispatchPlanResource($dispatchPlan->load($this->relations()));
    }

    private function syncItems(DispatchPlan $plan, array $items): void
    {
        foreach (array_values($items) as $i => $item) {
            $plan->items()->create([
                'customer_id' => $item['customer_id'] ?? null,
                'sales_order_id' => $item['sales_order_id'] ?? null,
                'delivery_address' => $item['delivery_address'] ?? null,
                'packages' => $item['packages'] ?? 1,
                'weight_kg' => $item['weight_kg'] ?? null,
                'cod_amount' => $item['cod_amount'] ?? 0,
                'sequence' => $i + 1,
            ]);
        }
    }

    private function relations(): array
    {
        return ['vehicle', 'driver', 'route', 'creator', 'items.customer', 'items.salesOrder'];
    }
}
