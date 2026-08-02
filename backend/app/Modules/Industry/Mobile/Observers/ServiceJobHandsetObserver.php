<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Observers;

use App\Models\ServiceJob;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Models\InsurancePolicy;

/**
 * Keeps the handset register and the insurance book in step with the job book.
 *
 * An observer rather than endpoints on this plugin, because a repair is opened and
 * closed from the ordinary Service screen at least as often as from anywhere else —
 * a shop with a counter and a bench will work most of them there. A claim counter
 * that only moves when the plugin's own screens were used is a claim counter nobody
 * can trust.
 *
 * Two facts are maintained, and each fires exactly once:
 *
 *  - A claim is stamped on the transition from "no policy named" to "this policy
 *    named". A technician saving a diagnosis three times has not used up three
 *    screen replacements.
 *  - The handset's service clock moves on `completed`, not `delivered` — the work is
 *    what serviced it, and a phone can sit on the shelf for a week waiting to be
 *    collected.
 */
class ServiceJobHandsetObserver
{
    public function created(ServiceJob $job): void
    {
        $this->claim($job->insurance_policy_id);

        if ($job->status === 'completed') {
            $this->touchHandset($job);
        }
    }

    public function updated(ServiceJob $job): void
    {
        // wasChanged rather than isDirty: this runs after the save, and it is the
        // form that survives a save() which touched several attributes at once.
        if ($job->wasChanged('insurance_policy_id')) {
            $this->claim($job->insurance_policy_id);
        }

        if ($job->wasChanged('status') && $job->status === 'completed') {
            $this->touchHandset($job);
        }
    }

    private function claim(?int $policyId): void
    {
        if (! $policyId) {
            return;
        }

        InsurancePolicy::find($policyId)?->recordClaim();
    }

    private function touchHandset(ServiceJob $job): void
    {
        if (! $job->handset_id) {
            return;
        }

        Handset::whereKey($job->handset_id)->update([
            'last_service_at' => $job->completed_at ?? now(),
        ]);
    }
}
