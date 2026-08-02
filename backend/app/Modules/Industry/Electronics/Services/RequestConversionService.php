<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Services;

use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use App\Modules\Industry\Electronics\Models\ServiceRequest;
use App\Services\ServiceJobBillingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a service request into a job card.
 *
 * This is the moment a phone call becomes work, and it is where three separate
 * facts have to be settled at once: what is being worked on (the unit), who pays
 * (is any cover still live, and does it pay for parts, labour or both), and what
 * the technician was told (the complaint). Doing it in one place means the answer
 * is the same whether the desk converts from the request list or the workshop
 * does it from the unit's own page.
 *
 * The job card itself is an ordinary ServiceJob — same lifecycle, same parts
 * issue, same billing through the sales spine — so everything after this point
 * runs through the core service endpoints unchanged.
 */
class RequestConversionService
{
    public function __construct(private readonly ServiceJobBillingService $billing) {}

    /**
     * @param  int|null  $assignedTo  Technician taking it, overriding whoever the
     *                                desk pencilled in.
     *
     * @throws RuntimeException when the request cannot become work.
     */
    public function convert(
        ServiceRequest $request,
        ?int $userId = null,
        ?int $assignedTo = null,
    ): ServiceJob {
        if ($request->service_job_id) {
            throw new RuntimeException("Request {$request->request_number} already has job card #{$request->service_job_id}.");
        }
        if (! $request->isOpen()) {
            throw new RuntimeException("A {$request->status} request cannot be opened as a job card.");
        }
        if (! $request->needsJobCard()) {
            /**
             * A delivery or a demo has nothing to diagnose, no part to issue and
             * nothing to bill separately, so a job card for one is paperwork with
             * no work behind it. If the visit turned into a repair, the honest
             * record is that the type changed — so say that rather than quietly
             * opening a card the desk did not intend.
             */
            throw new RuntimeException(
                "A {$request->type} request is closed off the desk, not worked as a job card. ".
                'Change its type to repair if a fault was found.',
            );
        }

        $request->loadMissing('unit');
        $unit = $request->unit;

        // The unit's own owner wins over the request's: a machine that changed
        // hands between the call and the visit is billed to whoever owns it now,
        // and the register is the thing that knows.
        $customerId = $unit?->customer_id ?? $request->customer_id;

        if (! $customerId) {
            throw new RuntimeException('Attach a customer to this request before opening a job card — a job card has to be billable to someone.');
        }

        return DB::transaction(function () use ($request, $unit, $customerId, $userId, $assignedTo) {
            $warranty = $unit ? $this->liveWarranty($unit) : null;
            $assignee = $assignedTo ?? $request->assigned_to;

            /**
             * Full cover means the customer pays nothing, which is the flag core
             * billing already honours. Partial cover deliberately does *not* set
             * it: a parts-only warranty still leaves the labour billable, and
             * flagging the whole job as warranty work would give away a service
             * call the manufacturer never promised to pay for. The cover is still
             * linked either way, and the note below tells the technician what
             * they may hand over free.
             */
            $fullyCovered = $warranty !== null && $warranty->covers_parts && $warranty->covers_labour;

            $job = ServiceJob::create([
                'job_number' => ServiceJob::nextNumber(),
                'customer_id' => $customerId,
                'device_unit_id' => $unit?->id,
                'service_request_id' => $request->id,
                'device_warranty_id' => $warranty?->id,
                'warehouse_id' => $request->warehouse_id,
                'assigned_to' => $assignee,
                'status' => $assignee ? 'assigned' : 'received',
                // Both tables carry the same four-value priority enum, so the
                // desk's urgency survives onto the worksheet unchanged.
                'priority' => $request->priority,
                // The worksheet still carries a plain description, because the
                // core screens and the printed card read these fields and should
                // not have to know what an IMEI is.
                'item_name' => $unit?->label() ?? $request->product?->name ?? 'Customer item',
                'item_serial' => $unit?->identifier(),
                'complaint' => $request->complaint,
                'is_warranty' => $fullyCovered,
                'received_at' => now(),
                'promised_at' => $request->promised_at,
                'labour_charge' => 0,
                'notes' => $this->openingNote($request, $warranty),
                'created_by' => $userId,
            ]);

            // No lines yet — a machine is diagnosed before anyone knows what it
            // needs — but pricing it now means the board shows a real zero
            // rather than a null total.
            $this->billing->reprice($job);

            $warranty?->recordClaim();

            $request->forceFill([
                'status' => 'converted',
                'service_job_id' => $job->id,
                'assigned_to' => $assignee,
                // What the desk found at conversion, which may differ from what
                // it believed when the call came in.
                'warranty_id' => $warranty?->id ?? $request->warranty_id,
            ])->save();

            return $job;
        });
    }

    /**
     * The cover to claim against, if any.
     *
     * Manufacturer cover is preferred over a plan the shop sold when both apply:
     * a fault inside the maker's warranty is the maker's liability, and claiming
     * it against a plan the shop itself underwrote would put the cost on the
     * wrong party. Ties beyond that are broken by the cover that ends soonest, so
     * the one about to lapse is used while it still can be.
     *
     * `claimed` is not in the candidate set, unlike the motor register: a claimed
     * retail card means the item was replaced or refunded, so there is nothing
     * left to cover — ProductWarranty::covers() refuses it too.
     */
    private function liveWarranty(DeviceUnit $unit): ?ProductWarranty
    {
        return ProductWarranty::query()
            ->where('unit_id', $unit->id)
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN source = 'manufacturer' THEN 0 WHEN source = 'extended' THEN 1 ELSE 2 END")
            ->orderBy('end_date')
            ->get()
            ->first(fn (ProductWarranty $w) => $w->covers());
    }

    /**
     * What the technician needs to read before they start.
     *
     * The scope line is the whole point: "Parts only" on the card is what stops
     * a fitter waiving a call-out charge the shop is owed, and "Onsite" is what
     * stops the counter telling a customer to carry a 200-litre fridge in.
     */
    private function openingNote(ServiceRequest $request, ?ProductWarranty $warranty): ?string
    {
        $lines = array_filter([
            $request->notes,
            $warranty
                ? "Cover {$warranty->warranty_number} in force until {$warranty->end_date->format('d M Y')} — {$warranty->coverageLabel()}."
                : null,
            $request->is_onsite && $request->address
                ? "Onsite at: {$request->address}"
                : null,
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }
}
