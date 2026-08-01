<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Automobile\Http\Requests\VehicleWarrantyRequest;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleWarranty;
use Illuminate\Http\Request;

/**
 * The warranty register.
 *
 * Its whole purpose is to answer one question at the counter, in front of a
 * customer who believes the answer is yes: is this still covered? So `check` is
 * a first-class endpoint rather than something the caller assembles from a list
 * — it takes a vehicle and a reading and returns what is live, with the reason
 * anything else is not.
 */
class VehicleWarrantyController extends Controller
{
    public function index(Request $request)
    {
        $query = VehicleWarranty::query()->with([
            'vehicle:id,registration_no,vin,make,model,odometer',
            'customer:id,name,phone',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $search));

            $query->where(function ($q) use ($search, $plate) {
                $q->where('warranty_number', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('registration_no', 'like', "%{$plate}%")
                        ->orWhere('vin', 'like', "%{$plate}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['type', 'provider', 'status', 'vehicle_id', 'customer_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Cover about to run out — the list a dealer works to sell extended
        // warranty from, and the one that tells a shop what to finish now.
        if ($request->boolean('expiring')) {
            $query->where('status', 'active')
                ->whereBetween('end_date', [
                    now()->toDateString(),
                    now()->addDays((int) $request->query('within_days', 30))->toDateString(),
                ]);
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (VehicleWarranty $w) => $this->row($w))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(VehicleWarranty $warranty)
    {
        $warranty->load([
            'vehicle:id,registration_no,vin,make,model,odometer',
            'customer:id,name,phone', 'product:id,sku,name',
            'invoice:id,invoice_number,invoice_date',
            'claims:id,warranty_id,job_number,status,received_at,total',
        ]);

        return response()->json(['data' => $this->row($warranty, detailed: true)]);
    }

    /**
     * What covers this vehicle right now.
     *
     * Returns every registration on the vehicle, not only the live ones, with
     * `is_live` and a reason — because "your warranty ran out 400 km ago" is a
     * far better thing to be able to say than "no cover found".
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'exists:automobile_vehicles,id'],
            'odometer' => ['nullable', 'integer', 'min:0'],
        ]);

        $vehicle = CustomerVehicle::findOrFail($data['vehicle_id']);
        $odometer = $data['odometer'] ?? (int) $vehicle->odometer;

        $warranties = VehicleWarranty::where('vehicle_id', $vehicle->id)
            ->with('product:id,sku,name')
            ->orderByDesc('end_date')
            ->get();

        $rows = $warranties->map(function (VehicleWarranty $w) use ($odometer) {
            $live = $w->covers(null, $odometer);

            return $this->row($w) + [
                'is_live' => $live,
                'reason' => $live ? null : $this->whyNot($w, $odometer),
                'km_remaining' => $w->kmRemaining($odometer),
                'days_remaining' => $w->end_date?->isFuture()
                    ? now()->startOfDay()->diffInDays($w->end_date)
                    : 0,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'vehicle' => [
                'id' => $vehicle->id,
                'label' => $vehicle->label(),
                'odometer' => $odometer,
            ],
            'is_covered' => $rows->contains('is_live', true),
        ]);
    }

    public function store(VehicleWarrantyRequest $request)
    {
        $data = $request->validated();

        // Default the owner and the starting reading from the vehicle: both are
        // already known, and asking the counter to re-key them is how a
        // kilometre limit ends up counted from zero.
        if (! empty($data['vehicle_id'])) {
            $vehicle = CustomerVehicle::find($data['vehicle_id']);
            $data['customer_id'] ??= $vehicle?->customer_id;
            $data['start_odometer'] ??= (int) ($vehicle?->odometer ?? 0);
        }

        $warranty = VehicleWarranty::create($data + [
            'warranty_number' => VehicleWarranty::nextNumber(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($warranty->fresh(['vehicle', 'customer', 'product']), detailed: true)], 201);
    }

    public function update(VehicleWarrantyRequest $request, VehicleWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            // Editing the terms of a cover that has already paid out would
            // rewrite the basis on which past work was given away free. Voiding
            // it and registering a corrected one keeps both facts on the record.
            return response()->json([
                'message' => 'This cover has claims against it and can no longer be edited. Void it and register a corrected one.',
            ], 422);
        }

        $warranty->update($request->validated());

        return response()->json(['data' => $this->row($warranty->fresh(['vehicle', 'customer', 'product']), detailed: true)]);
    }

    /** Cancel cover — mis-keyed, or withdrawn by the manufacturer. */
    public function void(Request $request, VehicleWarranty $warranty)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $warranty->forceFill([
            'status' => 'void',
            'notes' => trim((string) $warranty->notes."\nVoided: ".($data['reason'] ?? '—')),
        ])->save();

        return response()->json(['data' => $this->row($warranty->fresh(['vehicle', 'customer', 'product']), detailed: true)]);
    }

    public function destroy(VehicleWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            return response()->json([
                'message' => 'Work has been done under this cover. Void it instead of deleting it.',
            ], 422);
        }

        $warranty->delete();

        return response()->json(['message' => 'Warranty removed from the register.']);
    }

    /** Why a cover did not apply, in the words the counter would use. */
    private function whyNot(VehicleWarranty $w, int $odometer): string
    {
        if ($w->status === 'void') {
            return 'This cover was voided.';
        }
        if ($w->end_date && $w->end_date->isPast()) {
            return "Expired on {$w->end_date->format('d M Y')}.";
        }
        if ($w->start_date && $w->start_date->isFuture()) {
            return "Does not start until {$w->start_date->format('d M Y')}.";
        }
        if ($w->coverage_km) {
            $covered = (int) ($w->start_odometer ?? 0) + (int) $w->coverage_km;
            if ($odometer > $covered) {
                return 'Kilometre limit passed at '.number_format($covered).' km.';
            }
        }

        return 'Not currently in force.';
    }

    private function summary(): array
    {
        return [
            'registered' => VehicleWarranty::count(),
            'active' => VehicleWarranty::where('status', 'active')
                ->where('end_date', '>=', now()->toDateString())
                ->count(),
            'expiring_30d' => VehicleWarranty::where('status', 'active')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count(),
            'claims' => (int) VehicleWarranty::sum('claims_count'),
        ];
    }

    private function row(VehicleWarranty $w, bool $detailed = false): array
    {
        $base = [
            'id' => $w->id,
            'warranty_number' => $w->warranty_number,
            'type' => $w->type,
            'provider' => $w->provider,
            'vehicle_id' => $w->vehicle_id,
            'vehicle' => $w->vehicle?->label(),
            'customer_id' => $w->customer_id,
            'customer' => $w->customer?->name,
            'product_id' => $w->product_id,
            'part' => $w->product?->name,
            'serial_no' => $w->serial_no,
            'start_date' => $w->start_date?->toDateString(),
            'end_date' => $w->end_date?->toDateString(),
            'coverage_months' => $w->coverage_months,
            'coverage_km' => $w->coverage_km,
            'start_odometer' => $w->start_odometer,
            'km_remaining' => $w->kmRemaining(),
            'status' => $w->status,
            'claims_count' => (int) $w->claims_count,
            'last_claim_at' => $w->last_claim_at?->toIso8601String(),
            // Computed rather than read off `status`: a row can sit at 'active'
            // while its end date has quietly passed, and the register should say
            // so without waiting for someone to run a sweep.
            'is_live' => $w->covers(null, (int) ($w->vehicle?->odometer ?? 0)),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'invoice_id' => $w->invoice_id,
            'invoice_number' => $w->invoice?->invoice_number,
            'service_job_id' => $w->service_job_id,
            'terms' => $w->terms,
            'notes' => $w->notes,
            'claims' => $w->relationLoaded('claims')
                ? $w->claims->map(fn ($job) => [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'status' => $job->status,
                    'received_at' => $job->received_at?->toIso8601String(),
                    'total' => (float) $job->total,
                ])->values()
                : [],
        ];
    }
}
