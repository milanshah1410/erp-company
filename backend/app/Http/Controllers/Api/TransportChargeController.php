<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportChargeRequest;
use App\Http\Resources\TransportChargeResource;
use App\Models\TransportCharge;
use Illuminate\Http\Request;

/**
 * Transport charges — freight / loading / toll / detention costs, optionally
 * linked to a dispatch plan. `charge_number` is auto-assigned on create.
 */
class TransportChargeController extends Controller
{
    public function index(Request $request)
    {
        $query = TransportCharge::query()->with([
            'dispatchPlan:id,dispatch_number', 'vehicle:id,registration_no,name', 'customer:id,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('charge_number', 'like', "%{$search}%")
                    ->orWhere('transporter_name', 'like', "%{$search}%")
                    ->orWhere('lr_number', 'like', "%{$search}%");
            });
        }
        foreach (['charge_type', 'payment_mode', 'payer', 'dispatch_plan_id', 'vehicle_id', 'customer_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('charge_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('charge_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return TransportChargeResource::collection($query->paginate($perPage));
    }

    public function store(TransportChargeRequest $request)
    {
        $charge = TransportCharge::create([
            ...$request->validated(),
            'charge_number' => TransportCharge::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return (new TransportChargeResource($charge->load(['dispatchPlan', 'vehicle', 'customer'])))
            ->response()->setStatusCode(201);
    }

    public function show(TransportCharge $transportCharge)
    {
        return new TransportChargeResource($transportCharge->load(['dispatchPlan', 'vehicle', 'customer']));
    }

    public function update(TransportChargeRequest $request, TransportCharge $transportCharge)
    {
        $transportCharge->update($request->validated());

        return new TransportChargeResource($transportCharge->load(['dispatchPlan', 'vehicle', 'customer']));
    }

    public function destroy(TransportCharge $transportCharge)
    {
        $transportCharge->delete();

        return response()->json(['message' => 'Transport charge deleted.']);
    }
}
