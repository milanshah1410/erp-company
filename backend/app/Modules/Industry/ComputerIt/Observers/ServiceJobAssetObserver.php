<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Observers;

use App\Models\ServiceJob;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;

/**
 * Keeps a customer's machine in step with the job cards worked on it.
 *
 * Registered by ComputerItPlugin::boot() rather than wired into the service
 * module, so it also fires when a job is closed from the ordinary Service
 * screen — which is where a shop running both the counter and the support desk
 * will often close it. Anything that only happened inside this plugin's own
 * endpoints would leave half the install base with a stale "last serviced" date
 * and no preventive visit due.
 *
 * Two different moments, deliberately:
 *
 *  - `completed` moves the service clock. The work is what services the machine;
 *    a laptop sitting on the shelf for three days waiting to be collected was
 *    serviced on the day the technician finished it.
 *  - `delivered` puts it back on the customer's desk. That one really is about
 *    custody, and it is the only honest point at which a machine stops being in
 *    the shop.
 */
class ServiceJobAssetObserver
{
    public function updated(ServiceJob $job): void
    {
        if (! $job->it_asset_id || ! $job->wasChanged('status')) {
            return;
        }

        if (! in_array($job->status, ['in_progress', 'completed', 'delivered'], true)) {
            return;
        }

        $asset = CustomerAsset::find($job->it_asset_id);
        if (! $asset) {
            return;
        }

        if ($job->status === 'in_progress') {
            // The machine is on the bench. Recorded so an engineer sent to the
            // customer's site does not go looking for a laptop that is here.
            $asset->moveToStatus('in_service');

            return;
        }

        if ($job->status === 'completed') {
            $asset->recordService($job->completed_at ?? now());

            return;
        }

        $asset->moveToStatus('deployed');
    }
}
