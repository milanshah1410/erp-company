<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Modules\Industry\Automobile\Http\Requests\CustomerVehicleRequest;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleWarranty;
use Illuminate\Http\Request;

/**
 * The vehicle register — every vehicle this workshop has ever touched.
 *
 * The list is a work list, not a filing cabinet: what a service manager opens it
 * for is "who should I be ringing today", so service-due and expiring-document
 * counts lead, and both are filterable in one click. A register that could only
 * be searched would be a slower version of asking the customer.
 */
class CustomerVehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerVehicle::query()->with('customer:id,name,phone');

        if ($search = $request->query('search')) {
            // Plates are stored stripped of spaces and dashes, so the search
            // term is too — otherwise "GJ 01 AB 1234" finds nothing.
            $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $search));

            $query->where(function ($q) use ($search, $plate) {
                $q->where('registration_no', 'like', "%{$plate}%")
                    ->orWhere('vin', 'like', "%{$plate}%")
                    ->orWhere('engine_no', 'like', "%{$plate}%")
                    ->orWhere('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['customer_id', 'make', 'fuel_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // The two work lists. `service_due` honours both limbs of the schedule
        // — a taxi reaches the distance long before the date — which is why it
        // is an OR rather than a single date comparison.
        if ($request->boolean('service_due')) {
            $withinDays = (int) $request->query('within_days', 0);
            $query->where(function ($q) use ($withinDays) {
                $q->where('next_service_due_at', '<=', now()->addDays($withinDays)->toDateString())
                    ->orWhereColumn('odometer', '>=', 'next_service_odometer');
            });
        }
        if ($request->boolean('documents_expiring')) {
            $limit = now()->addDays((int) $request->query('within_days', 30))->toDateString();
            $query->where(function ($q) use ($limit) {
                $q->where('insurance_expiry', '<=', $limit)
                    ->orWhere('puc_expiry', '<=', $limit)
                    ->orWhere('fitness_expiry', '<=', $limit);
            });
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (CustomerVehicle $v) => $this->row($v))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(CustomerVehicle $vehicle)
    {
        $vehicle->load(['customer:id,name,phone,email', 'saleInvoice:id,invoice_number,invoice_date']);

        return response()->json(['data' => $this->row($vehicle, detailed: true)]);
    }

    /**
     * The service history: every job card ever raised on this vehicle, newest
     * first, with what it cost.
     *
     * This is the screen an advisor reads out at the counter to justify the
     * estimate they are about to give, so it carries the parts and the reading
     * rather than only dates and totals.
     */
    public function history(CustomerVehicle $vehicle)
    {
        $jobs = ServiceJob::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['technician:id,employee_code,first_name,last_name', 'items.product:id,sku,name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $jobs->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'is_warranty' => (bool) $job->is_warranty,
                'odometer_in' => $job->odometer_in,
                'odometer_out' => $job->odometer_out,
                'complaint' => $job->complaint,
                'resolution' => $job->resolution,
                'technician' => $job->technician?->fullName(),
                'received_at' => $job->received_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'total' => (float) $job->total,
                'invoice_id' => $job->invoice_id,
                'parts' => $job->items->map(fn ($i) => [
                    'name' => $i->product?->name,
                    'sku' => $i->product?->sku,
                    'quantity' => (float) $i->quantity,
                    'line_total' => (float) $i->line_total,
                ])->values(),
            ])->values(),
            'summary' => [
                'visits' => $jobs->count(),
                // Warranty and contract work is excluded from lifetime spend on
                // purpose: it is work the customer did not pay for, and counting
                // it would overstate what this vehicle is worth to the shop.
                'lifetime_spend' => round((float) $jobs->where('is_warranty', false)->sum('total'), 2),
                'last_visit_at' => $jobs->first()?->received_at?->toIso8601String(),
            ],
        ]);
    }

    public function store(CustomerVehicleRequest $request)
    {
        $vehicle = CustomerVehicle::create($request->validated() + [
            'created_by' => $request->user()?->id,
        ]);

        if ($request->filled('odometer')) {
            $vehicle->forceFill(['odometer_updated_at' => now()])->save();
        }

        return response()->json(['data' => $this->row($vehicle->fresh('customer'), detailed: true)], 201);
    }

    public function update(CustomerVehicleRequest $request, CustomerVehicle $vehicle)
    {
        $data = $request->validated();

        // The odometer is the one field an edit must not move backwards: the
        // service schedule and every distance-limited warranty count from it.
        // recordOdometer refuses a lower reading, so it is handled apart from
        // the rest of the payload rather than mass-assigned with it.
        $reading = $data['odometer'] ?? null;
        unset($data['odometer']);

        $vehicle->update($data);
        $vehicle->recordOdometer($reading === null ? null : (int) $reading);

        return response()->json(['data' => $this->row($vehicle->fresh('customer'), detailed: true)]);
    }

    /**
     * Vehicles with history are deactivated rather than deleted: the job cards
     * and invoices behind them are the shop's books, and a register that can be
     * emptied is a register nobody can rely on.
     */
    public function destroy(CustomerVehicle $vehicle)
    {
        if (ServiceJob::where('vehicle_id', $vehicle->id)->exists()) {
            return response()->json([
                'message' => 'This vehicle has job cards against it. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle removed from the register.']);
    }

    private function summary(): array
    {
        $today = now()->toDateString();
        $inThirty = now()->addDays(30)->toDateString();

        return [
            'vehicles' => CustomerVehicle::count(),
            'active' => CustomerVehicle::where('is_active', true)->count(),
            'service_due' => CustomerVehicle::where(function ($q) use ($today) {
                $q->where('next_service_due_at', '<=', $today)
                    ->orWhereColumn('odometer', '>=', 'next_service_odometer');
            })->count(),
            'documents_expiring' => CustomerVehicle::where(function ($q) use ($inThirty) {
                $q->where('insurance_expiry', '<=', $inThirty)
                    ->orWhere('puc_expiry', '<=', $inThirty)
                    ->orWhere('fitness_expiry', '<=', $inThirty);
            })->count(),
        ];
    }

    private function row(CustomerVehicle $v, bool $detailed = false): array
    {
        $base = [
            'id' => $v->id,
            'label' => $v->label(),
            'registration_no' => $v->registration_no,
            'vin' => $v->vin,
            'engine_no' => $v->engine_no,
            'make' => $v->make,
            'model' => $v->model,
            'variant' => $v->variant,
            'manufacture_year' => $v->manufacture_year,
            'fuel_type' => $v->fuel_type,
            'transmission' => $v->transmission,
            'colour' => $v->colour,
            'customer_id' => $v->customer_id,
            'customer' => $v->customer?->name,
            'customer_phone' => $v->customer?->phone,
            'odometer' => (int) $v->odometer,
            'odometer_updated_at' => $v->odometer_updated_at?->toIso8601String(),
            'last_service_at' => $v->last_service_at?->toIso8601String(),
            'next_service_due_at' => $v->next_service_due_at?->toDateString(),
            'next_service_odometer' => $v->next_service_odometer,
            'is_service_due' => $v->isServiceDue(),
            // Which papers are running out, so the list can say *what* is
            // expiring rather than only that something is.
            'expiring_documents' => array_map(
                static fn ($date) => $date->toDateString(),
                $v->expiringDocuments(),
            ),
            'is_active' => (bool) $v->is_active,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'registration_date' => $v->registration_date?->toDateString(),
            'delivery_date' => $v->delivery_date?->toDateString(),
            'sale_invoice_id' => $v->sale_invoice_id,
            'sale_invoice_number' => $v->saleInvoice?->invoice_number,
            'insurance_provider' => $v->insurance_provider,
            'insurance_policy_no' => $v->insurance_policy_no,
            'insurance_expiry' => $v->insurance_expiry?->toDateString(),
            'puc_expiry' => $v->puc_expiry?->toDateString(),
            'fitness_expiry' => $v->fitness_expiry?->toDateString(),
            'service_interval_days' => $v->service_interval_days,
            'service_interval_km' => $v->service_interval_km,
            'notes' => $v->notes,
            // Live cover, so the counter can answer "is this still under
            // warranty?" from the vehicle's own page.
            'warranties' => VehicleWarranty::where('vehicle_id', $v->id)
                ->orderByDesc('end_date')
                ->get()
                ->map(fn (VehicleWarranty $w) => [
                    'id' => $w->id,
                    'warranty_number' => $w->warranty_number,
                    'type' => $w->type,
                    'end_date' => $w->end_date?->toDateString(),
                    'km_remaining' => $w->kmRemaining((int) $v->odometer),
                    'status' => $w->status,
                    'is_live' => $w->covers(null, (int) $v->odometer),
                ])->values(),
        ];
    }
}
