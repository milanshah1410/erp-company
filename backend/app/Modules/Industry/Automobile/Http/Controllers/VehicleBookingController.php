<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Automobile\Http\Requests\VehicleBookingRequest;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleBooking;
use App\Modules\Industry\Automobile\Services\BookingConversionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The appointment book.
 *
 * Status changes are separate endpoints rather than a writable field, for the
 * same reason they are on service jobs: each one means something different at
 * the counter. Confirming is a phone call the day before; arriving is the
 * vehicle physically on the premises; converting is the shop taking custody and
 * opening a job card. Collapsing them into one PATCH would lose the distinction
 * a service manager runs the day on.
 */
class VehicleBookingController extends Controller
{
    public function __construct(private readonly BookingConversionService $conversion) {}

    public function index(Request $request)
    {
        $query = VehicleBooking::query()
            ->with(['vehicle:id,registration_no,vin,make,model,variant,odometer', 'customer:id,name,phone', 'advisor:id,employee_code,first_name,last_name']);

        if ($search = $request->query('search')) {
            $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $search));

            $query->where(function ($q) use ($search, $plate) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('registration_no', 'like', "%{$plate}%")
                        ->orWhere('vin', 'like', "%{$plate}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'service_type', 'vehicle_id', 'assigned_to', 'warehouse_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The day view — the shape the appointment book is actually read in.
        if ($date = $request->query('date')) {
            $query->whereDate('scheduled_at', $date);
        }
        if ($from = $request->query('from')) {
            $query->where('scheduled_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('scheduled_at', '<=', $to);
        }
        if ($request->boolean('open_only')) {
            $query->whereNotIn('status', VehicleBooking::CLOSED_STATUSES);
        }

        // Ascending: the book is read forwards, from the next slot onwards.
        $query->orderBy('scheduled_at');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (VehicleBooking $b) => $this->row($b))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(VehicleBooking $booking)
    {
        $booking->load([
            'vehicle:id,registration_no,vin,make,model,variant,odometer,customer_id',
            'customer:id,name,phone', 'advisor:id,employee_code,first_name,last_name',
            'warehouse:id,name', 'serviceJob:id,job_number,status',
        ]);

        return response()->json(['data' => $this->row($booking, detailed: true)]);
    }

    public function store(VehicleBookingRequest $request)
    {
        $data = $request->validated();

        // Default the caller from the vehicle rather than making the counter
        // re-key an owner the register already knows.
        $data['customer_id'] ??= CustomerVehicle::whereKey($data['vehicle_id'])->value('customer_id');

        $booking = VehicleBooking::create($data + [
            'booking_number' => VehicleBooking::nextNumber(),
            'status' => 'booked',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($booking->fresh(['vehicle', 'customer', 'advisor']), detailed: true)], 201);
    }

    public function update(VehicleBookingRequest $request, VehicleBooking $booking)
    {
        if ($booking->isClosed()) {
            return response()->json([
                'message' => "A {$booking->status} booking can no longer be changed.",
            ], 422);
        }

        $booking->update($request->validated());

        return response()->json(['data' => $this->row($booking->fresh(['vehicle', 'customer', 'advisor']), detailed: true)]);
    }

    public function destroy(VehicleBooking $booking)
    {
        if ($booking->service_job_id) {
            return response()->json([
                'message' => 'This booking is already a job card. Cancel the job card instead.',
            ], 422);
        }

        $booking->delete();

        return response()->json(['message' => 'Booking deleted.']);
    }

    /**
     * Move the slot along: confirmed (the reminder call was answered) or
     * arrived (the vehicle is on the premises).
     */
    public function progress(Request $request, VehicleBooking $booking)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'arrived'])],
            'odometer' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($booking->isClosed()) {
            return response()->json(['message' => "A {$booking->status} booking cannot be reopened."], 422);
        }

        $booking->forceFill(array_filter([
            'status' => $data['status'],
            'odometer' => $data['odometer'] ?? $booking->odometer,
            'reminder_sent_at' => $data['status'] === 'confirmed' ? now() : $booking->reminder_sent_at,
        ], static fn ($v) => $v !== null))->save();

        return response()->json(['data' => $this->row($booking->fresh(['vehicle', 'customer', 'advisor']), detailed: true)]);
    }

    /**
     * Open the job card. The vehicle is here and the shop has taken custody —
     * from this point everything runs through the ordinary service endpoints.
     */
    public function convert(Request $request, VehicleBooking $booking)
    {
        $data = $request->validate([
            // The reading at the gate, which beats whatever was declared on the
            // phone. Optional, because plenty of shops do not take one.
            'odometer' => ['nullable', 'integer', 'min:0'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
        ]);

        try {
            $job = $this->conversion->convert(
                $booking,
                $data['odometer'] ?? null,
                $request->user()?->id,
                $data['assigned_to'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['booking' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($booking->fresh(['vehicle', 'customer', 'advisor', 'serviceJob']), detailed: true),
            'job_card' => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'is_warranty' => (bool) $job->is_warranty,
                'warranty_id' => $job->warranty_id,
            ],
        ], 201);
    }

    /**
     * Close the slot without work. `no_show` and `cancelled` are kept apart on
     * purpose: one is the customer not turning up, the other is the shop or the
     * customer calling it off in advance, and a workshop chasing its no-show
     * rate needs to tell them apart.
     */
    public function close(Request $request, VehicleBooking $booking)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['cancelled', 'no_show'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($booking->isClosed()) {
            return response()->json(['message' => "This booking is already {$booking->status}."], 422);
        }

        $booking->forceFill([
            'status' => $data['status'],
            'notes' => trim((string) $booking->notes."\n".ucfirst($data['status']).': '.($data['reason'] ?? '—')),
        ])->save();

        return response()->json(['data' => $this->row($booking->fresh(['vehicle', 'customer', 'advisor']), detailed: true)]);
    }

    private function summary(): array
    {
        $byStatus = VehicleBooking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'by_status' => $byStatus,
            'today' => VehicleBooking::whereDate('scheduled_at', now()->toDateString())
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->count(),
            'open' => (int) $byStatus->except(VehicleBooking::CLOSED_STATUSES)->sum(),
            // Slots whose time has passed with the vehicle still not booked in.
            'overdue' => VehicleBooking::where('scheduled_at', '<', now())
                ->whereNotIn('status', VehicleBooking::CLOSED_STATUSES)
                ->count(),
        ];
    }

    private function row(VehicleBooking $b, bool $detailed = false): array
    {
        $base = [
            'id' => $b->id,
            'booking_number' => $b->booking_number,
            'vehicle_id' => $b->vehicle_id,
            'vehicle' => $b->vehicle?->label(),
            'registration_no' => $b->vehicle?->registration_no,
            'customer_id' => $b->customer_id,
            'customer' => $b->customer?->name,
            'customer_phone' => $b->customer?->phone,
            'service_type' => $b->service_type,
            'source' => $b->source,
            'status' => $b->status,
            'scheduled_at' => $b->scheduled_at?->toIso8601String(),
            'estimated_ready_at' => $b->estimated_ready_at?->toIso8601String(),
            'assigned_to' => $b->assigned_to,
            'advisor' => $b->advisor?->fullName(),
            'odometer' => $b->odometer,
            'estimated_cost' => (float) $b->estimated_cost,
            'pickup_required' => (bool) $b->pickup_required,
            'service_job_id' => $b->service_job_id,
            'is_overdue' => $b->isOverdue(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'warehouse_id' => $b->warehouse_id,
            'warehouse' => $b->warehouse?->name,
            'complaint' => $b->complaint,
            'pickup_address' => $b->pickup_address,
            'reminder_sent_at' => $b->reminder_sent_at?->toIso8601String(),
            'notes' => $b->notes,
            'job_card' => $b->serviceJob ? [
                'id' => $b->serviceJob->id,
                'job_number' => $b->serviceJob->job_number,
                'status' => $b->serviceJob->status,
            ] : null,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
