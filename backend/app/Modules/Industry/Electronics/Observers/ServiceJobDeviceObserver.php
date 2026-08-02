<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Observers;

use App\Models\ServiceJob;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use App\Modules\Industry\Electronics\Models\ServiceRequest;

/**
 * Keeps a unit's aftersales record in step with the job cards worked on it.
 *
 * Registered by ElectronicsPlugin::boot() rather than wired into the service
 * module, so it also fires when a job is closed from the ordinary Service
 * screen — which is where a shop running both will usually close it. Anything
 * that only happened inside this plugin's own endpoints would leave half the
 * register with a stale "last serviced" date and a wall of appliances that the
 * work list still thinks are waiting to be installed.
 *
 * Only `completed` counts, not `delivered`: the work is what services the
 * machine, and a television that sits on the shelf for three days waiting to be
 * collected was repaired on the day the technician finished it.
 */
class ServiceJobDeviceObserver
{
    public function updated(ServiceJob $job): void
    {
        if (! $job->device_unit_id || ! $job->wasChanged('status')) {
            return;
        }

        if ($job->status !== 'completed') {
            return;
        }

        $unit = DeviceUnit::find($job->device_unit_id);
        if (! $unit) {
            return;
        }

        $completedAt = $job->completed_at ?? now();

        $unit->recordService($completedAt);

        /**
         * A finished installation is what actually commissions the machine, so
         * the register's install date comes from the job rather than from
         * somebody remembering to tick a box on the unit. Only an installation
         * request does this — a repair on an appliance that was installed two
         * years ago must not restamp the date its warranty is counted from, and
         * markInstalled() refuses a second stamp for the same reason.
         */
        if ($job->service_request_id) {
            $type = ServiceRequest::whereKey($job->service_request_id)->value('type');

            if ($type === 'installation') {
                $unit->markInstalled($completedAt->toDateString());
            }
        }
    }
}
