<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DispatchItemResource;
use App\Models\DispatchItem;
use App\Models\DispatchPlan;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Proof of Delivery — records the outcome of each consignment (dispatch item):
 * who received it, when, an optional signed/photo capture, and remarks. Works
 * across every dispatched plan; once all consignments on a plan are resolved
 * the plan is auto-marked delivered.
 */
class ProofOfDeliveryController extends Controller
{
    /** Consignments awaiting or with recorded proof of delivery. */
    public function index(Request $request)
    {
        $query = DispatchItem::query()
            ->with(['dispatchPlan:id,dispatch_number,dispatch_date,status', 'customer:id,name', 'salesOrder:id,so_number'])
            ->whereHas('dispatchPlan', fn ($q) => $q->whereIn('status', ['dispatched', 'delivered']));

        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->query('delivery_status'));
        }
        if ($request->filled('dispatch_plan_id')) {
            $query->where('dispatch_plan_id', $request->query('dispatch_plan_id'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('received_by', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('dispatchPlan', fn ($d) => $d->where('dispatch_number', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('delivery_status')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return DispatchItemResource::collection($query->paginate($perPage));
    }

    /** Record (or update) proof of delivery for a single consignment. */
    public function record(Request $request, DispatchItem $dispatchItem)
    {
        $data = $request->validate([
            'delivery_status' => ['required', Rule::in(['pending', 'delivered', 'failed'])],
            'received_by' => ['nullable', 'string', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'delivered_at' => ['nullable', 'date'],
            'pod_remarks' => ['nullable', 'string'],
        ]);

        $delivered = $data['delivery_status'] === 'delivered';
        $dispatchItem->update([
            'delivery_status' => $data['delivery_status'],
            'received_by' => $data['received_by'] ?? $dispatchItem->received_by,
            'receiver_phone' => $data['receiver_phone'] ?? $dispatchItem->receiver_phone,
            'delivered_at' => $delivered ? ($data['delivered_at'] ?? now()) : null,
            'pod_remarks' => $data['pod_remarks'] ?? $dispatchItem->pod_remarks,
        ]);

        $this->settlePlan($dispatchItem->dispatch_plan_id);

        return new DispatchItemResource(
            $dispatchItem->load(['dispatchPlan:id,dispatch_number,dispatch_date,status', 'customer:id,name', 'salesOrder:id,so_number'])
        );
    }

    /** Upload the delivery proof photo (signed slip / doorstep capture). */
    public function uploadPhoto(Request $request, DispatchItem $dispatchItem)
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $disk = TenantStorage::disk();
        if ($dispatchItem->pod_photo_path) {
            $disk->delete($dispatchItem->pod_photo_path);
        }
        $path = $disk->putFile('dispatch-pod', $request->file('photo'));
        $dispatchItem->update(['pod_photo_path' => $path]);

        return new DispatchItemResource(
            $dispatchItem->load(['dispatchPlan:id,dispatch_number,dispatch_date,status', 'customer:id,name', 'salesOrder:id,so_number'])
        );
    }

    /**
     * Auto-advance the parent plan to "delivered" once every consignment has
     * been resolved (delivered or failed) while the plan is dispatched.
     */
    private function settlePlan(int $planId): void
    {
        $plan = DispatchPlan::find($planId);
        if (! $plan || $plan->status !== 'dispatched') {
            return;
        }
        $pending = $plan->items()->where('delivery_status', 'pending')->exists();
        if (! $pending) {
            $plan->update(['status' => 'delivered', 'delivered_at' => now()]);
        }
    }
}
