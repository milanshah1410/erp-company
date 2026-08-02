<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Services;

use App\Models\ServiceContract;
use App\Models\ServiceJob;
use App\Modules\Industry\ComputerIt\Models\AssetWarranty;
use App\Modules\Industry\ComputerIt\Models\ServiceCall;
use App\Services\ServiceJobBillingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a support call into a job card.
 *
 * This is the moment the machine physically arrives and the shop takes custody,
 * and it is where three separate facts have to be settled at once: what is being
 * worked on (which machine, with what fault), who pays (is any cover still
 * live?), and what the engineer was told. Doing it in one place means the answer
 * is the same whether the desk converts from the call book or an engineer does it
 * from the machine's own page.
 *
 * The job card itself is an ordinary ServiceJob — same lifecycle, same parts
 * issue, same billing through the sales spine — so everything after this point
 * runs through the core service endpoints unchanged.
 */
class ServiceCallConversionService
{
    public function __construct(private readonly ServiceJobBillingService $billing) {}

    /**
     * @param  int|null  $assignedTo  Technician taking the machine, if not the
     *                                engineer who attended the call.
     *
     * @throws RuntimeException when the call is already spent.
     */
    public function convert(
        ServiceCall $call,
        ?int $userId = null,
        ?int $assignedTo = null,
    ): ServiceJob {
        if ($call->service_job_id) {
            throw new RuntimeException("Call {$call->call_number} already has job card #{$call->service_job_id}.");
        }
        if (! in_array($call->status, ServiceCall::CONVERTIBLE_STATUSES, true)) {
            throw new RuntimeException("A {$call->status} call cannot be opened as a job card.");
        }
        if (! $call->customer_id) {
            throw new RuntimeException('Attach a customer to the call before opening a job card — a job card has to be billable to someone.');
        }

        $call->loadMissing('asset');
        $asset = $call->asset;

        return DB::transaction(function () use ($call, $asset, $userId, $assignedTo) {
            /**
             * Cover is resolved again here rather than trusted from the call.
             * A call logged in March and converted in April may have crossed the
             * end of a warranty, and the answer that decides who pays has to be
             * the one that is true on the day the work starts.
             */
            $warranty = $this->liveWarranty($call);
            $contract = $warranty ? null : $this->liveContract($call);

            $assignee = $assignedTo ?? $call->assigned_to;

            $job = ServiceJob::create([
                'job_number' => ServiceJob::nextNumber(),
                'customer_id' => $call->customer_id,
                'it_asset_id' => $asset?->id,
                'it_service_call_id' => $call->id,
                'it_warranty_id' => $warranty?->id,
                /**
                 * The core AMC link, which is what actually suppresses the
                 * invoice and draws down the visit quota. Only set when no
                 * warranty applies: a machine still under manufacturer cover
                 * should not burn one of the customer's paid AMC visits on work
                 * the manufacturer owes them.
                 */
                'service_contract_id' => $contract?->id,
                'assigned_to' => $assignee,
                'status' => $assignee ? 'assigned' : 'received',
                'priority' => match ($call->priority) {
                    'critical' => 'urgent',
                    'high' => 'high',
                    'low' => 'low',
                    default => 'normal',
                },
                // The worksheet still carries a plain description, because the
                // core screens and the printed card read this field and should
                // not have to know what an asset tag is.
                'item_name' => $asset?->label() ?? 'Customer equipment',
                'item_serial' => $asset?->serial_no ?: $asset?->asset_tag,
                'complaint' => $call->complaint,
                /**
                 * Live cover means the customer does not pay — the same flag the
                 * core billing service already honours, so a warranty job closes
                 * without an invoice. `it_warranty_id` beside it records *which*
                 * cover was claimed, which is what makes it auditable when the
                 * manufacturer asks months later.
                 */
                'is_warranty' => $warranty !== null,
                'received_at' => now(),
                'labour_charge' => 0,
                'notes' => $call->notes,
                'created_by' => $userId,
            ]);

            // No lines yet — a machine is diagnosed before anyone knows what it
            // needs — but pricing it now means the board shows a real zero
            // rather than a null total.
            $this->billing->reprice($job);

            $warranty?->recordClaim();

            /**
             * A contract visit is spent when the job is opened, not when it is
             * finished: the visit was used the moment the shop took the machine
             * in. Mirrors ServiceJobController::store, which does the same for a
             * job opened from the ordinary Service screen — if that rule ever
             * moves, both places have to move together.
             */
            if ($contract) {
                ServiceContract::whereKey($contract->id)->increment('visits_used');
            }

            $call->forceFill([
                'status' => 'converted',
                'service_job_id' => $job->id,
                'warranty_id' => $warranty?->id ?? $call->warranty_id,
                'contract_id' => $contract?->id ?? $call->contract_id,
                'is_chargeable' => $warranty === null && $contract === null,
            ])->save();

            // Whatever the machine's paperwork said, it is in the shop now.
            $asset?->moveToStatus('in_service');

            return $job;
        });
    }

    /**
     * The cover to claim against, if any.
     *
     * A cover named on the call wins, because somebody at the desk looked it up
     * and decided; otherwise the machine's own live cover is used, manufacturer
     * first. Both paths re-check the dates — a call can name a warranty that has
     * since run out.
     */
    private function liveWarranty(ServiceCall $call): ?AssetWarranty
    {
        if ($call->warranty_id) {
            $named = AssetWarranty::find($call->warranty_id);

            if ($named?->covers()) {
                return $named;
            }
        }

        return $call->asset?->liveWarranty();
    }

    /**
     * The AMC to book the job under, if any.
     *
     * Same precedence rule: the contract named on the call wins over the one the
     * machine sits on, and either only counts if it is live today and still has
     * a visit left. coversToday() is the core model's own test, so a contract
     * that has run out of visits is refused here for exactly the same reason it
     * would be refused on the Service screen.
     */
    private function liveContract(ServiceCall $call): ?ServiceContract
    {
        $named = $call->contract_id ? ServiceContract::find($call->contract_id) : null;

        if ($named?->coversToday()) {
            return $named;
        }

        $onAsset = $call->asset?->amcContract;

        return $onAsset?->coversToday() ? $onAsset : null;
    }
}
