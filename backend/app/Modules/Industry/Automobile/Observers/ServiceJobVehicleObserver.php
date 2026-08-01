<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Observers;

use App\Models\ServiceJob;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;

/**
 * Keeps a vehicle's service clock in step with the job cards worked on it.
 *
 * Registered by AutomobilePlugin::boot() rather than wired into the service
 * module, so it also fires when a job is closed from the ordinary Service
 * screen — which is where a workshop running both trades will often close it.
 * Anything that only happened inside this plugin's own endpoints would leave
 * half the vehicles with a stale "last serviced" date and no reminder due.
 *
 * Only `completed` moves the clock, not `delivered`: the work is what services
 * the vehicle, and a car that sits on the forecourt for three days waiting to be
 * collected was serviced on the day the technician finished it.
 */
class ServiceJobVehicleObserver
{
    public function updated(ServiceJob $job): void
    {
        if (! $job->vehicle_id || ! $job->wasChanged('status')) {
            return;
        }

        if ($job->status !== 'completed') {
            return;
        }

        $vehicle = CustomerVehicle::find($job->vehicle_id);
        if (! $vehicle) {
            return;
        }

        // The reading taken when the work finished, falling back to the one
        // taken at the gate. Recorded first so the schedule below counts from
        // the vehicle's newest reading rather than from whatever it held before
        // this visit.
        $vehicle->recordOdometer($job->odometer_out ?? $job->odometer_in);

        $vehicle->scheduleNextService($job->completed_at ?? now(), (int) $vehicle->odometer);
    }
}
