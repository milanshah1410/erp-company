<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Services;

use App\Models\ServiceJob;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleBooking;
use App\Modules\Industry\Automobile\Models\VehicleWarranty;
use App\Services\ServiceJobBillingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a booking into a job card.
 *
 * This is the moment the vehicle physically arrives and the shop takes custody,
 * and it is where three separate facts have to be settled at once: what is being
 * worked on (the vehicle, its reading at the gate), who pays (is any warranty
 * still live?), and what the technician was told (the complaint). Doing it in
 * one place means the answer is the same whether the advisor converts from the
 * appointment book or the gate keeper does it from the vehicle's own page.
 *
 * The job card itself is an ordinary ServiceJob — same lifecycle, same parts
 * issue, same billing through the sales spine — so everything after this point
 * runs through the core service endpoints unchanged.
 */
class BookingConversionService
{
    public function __construct(private readonly ServiceJobBillingService $billing) {}

    /** Statuses a booking may still be converted from. */
    private const CONVERTIBLE = ['booked', 'confirmed', 'arrived'];

    /**
     * @param  int|null  $odometer  Reading taken at the gate, which beats the
     *                              one declared over the phone.
     *
     * @throws RuntimeException when the booking is already spent.
     */
    public function convert(
        VehicleBooking $booking,
        ?int $odometer = null,
        ?int $userId = null,
        ?int $assignedTo = null,
    ): ServiceJob {
        if ($booking->service_job_id) {
            throw new RuntimeException("Booking {$booking->booking_number} already has job card #{$booking->service_job_id}.");
        }
        if (! in_array($booking->status, self::CONVERTIBLE, true)) {
            throw new RuntimeException("A {$booking->status} booking cannot be opened as a job card.");
        }

        $booking->loadMissing('vehicle');
        $vehicle = $booking->vehicle;

        if (! $vehicle) {
            throw new RuntimeException('This booking has no vehicle on it.');
        }

        // The vehicle's own owner wins over the booking's: a car that changed
        // hands between the phone call and the visit is billed to whoever owns
        // it now, and the register is the thing that knows.
        $customerId = $vehicle->customer_id ?? $booking->customer_id;

        if (! $customerId) {
            throw new RuntimeException('Attach an owner to the vehicle before opening a job card — a job card has to be billable to someone.');
        }

        $reading = $odometer ?? $booking->odometer;

        return DB::transaction(function () use ($booking, $vehicle, $customerId, $reading, $userId, $assignedTo) {
            $vehicle->recordOdometer($reading);

            $warranty = $this->liveWarranty($vehicle, $reading);
            $assignee = $assignedTo ?? $booking->assigned_to;

            $job = ServiceJob::create([
                'job_number' => ServiceJob::nextNumber(),
                'customer_id' => $customerId,
                'vehicle_id' => $vehicle->id,
                'booking_id' => $booking->id,
                'warranty_id' => $warranty?->id,
                'warehouse_id' => $booking->warehouse_id,
                'assigned_to' => $assignee,
                'status' => $assignee ? 'assigned' : 'received',
                'priority' => $booking->service_type === 'accident' ? 'high' : 'normal',
                // The worksheet still carries a plain description, because the
                // core screens and the printed card read this field and should
                // not have to know what a VIN is.
                'item_name' => $vehicle->label(),
                'item_serial' => $vehicle->vin ?: $vehicle->registration_no,
                'odometer_in' => $reading,
                'complaint' => $booking->complaint,
                /**
                 * Live cover means the customer does not pay — the same flag the
                 * core billing service already honours, so a warranty job simply
                 * closes without an invoice. `warranty_id` beside it records
                 * *which* cover was claimed, which is what makes it auditable
                 * when the manufacturer asks months later.
                 */
                'is_warranty' => $warranty !== null,
                'received_at' => now(),
                'promised_at' => $booking->estimated_ready_at,
                'labour_charge' => 0,
                'notes' => $booking->notes,
                'created_by' => $userId,
            ]);

            // No lines yet — a vehicle is diagnosed before anyone knows what it
            // needs — but pricing it now means the board shows a real zero
            // rather than a null total.
            $this->billing->reprice($job);

            $warranty?->recordClaim();

            $booking->forceFill([
                'status' => 'converted',
                'service_job_id' => $job->id,
                'odometer' => $reading ?? $booking->odometer,
            ])->save();

            return $job;
        });
    }

    /**
     * The cover to claim against, if any.
     *
     * Vehicle cover is preferred over part cover when both apply: a fault under
     * manufacturer warranty is the manufacturer's, and claiming it against the
     * shop's own part warranty instead would put the cost on the wrong party.
     * Ties beyond that are broken by the cover that ends soonest, so the one
     * about to lapse is used while it still can be.
     */
    private function liveWarranty(CustomerVehicle $vehicle, ?int $odometer): ?VehicleWarranty
    {
        return VehicleWarranty::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', ['active', 'claimed'])
            ->orderByRaw("CASE WHEN type = 'vehicle' THEN 0 ELSE 1 END")
            ->orderBy('end_date')
            ->get()
            ->first(fn (VehicleWarranty $w) => $w->covers(null, $odometer ?? (int) $vehicle->odometer));
    }
}
