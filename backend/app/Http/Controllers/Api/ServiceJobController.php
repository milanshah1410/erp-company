<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceJobRequest;
use App\Http\Resources\ServiceJobResource;
use App\Models\ServiceContract;
use App\Models\ServiceJob;
use App\Services\ServiceJobBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Service jobs — repairs and installations on a customer's item.
 *
 * Lifecycle: received → assigned → in_progress → completed → delivered, plus
 * on_hold and cancelled. Each transition is its own endpoint rather than a
 * writable `status` field, because every one of them has a side effect the
 * caller should not have to remember: assigning stamps a technician, completing
 * prices and bills the job, delivering closes it.
 *
 * Parts are issued from stock when the technician actually fits them, which is
 * why `issue` is separate from both editing the lines and completing the job.
 */
class ServiceJobController extends Controller
{
    public function __construct(private readonly ServiceJobBillingService $billing) {}

    public function index(Request $request)
    {
        $query = ServiceJob::query()
            ->with(['customer:id,name,phone', 'technician:id,employee_code,first_name,last_name', 'warehouse:id,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('job_number', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('item_serial', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'priority', 'customer_id', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        // The board's default view: everything still being worked.
        if ($request->boolean('open_only')) {
            $query->whereNotIn('status', ['delivered', 'cancelled']);
        }
        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('promised_at')
                ->where('promised_at', '<', now())
                ->whereNotIn('status', ['completed', 'delivered', 'cancelled']);
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ServiceJobResource::collection($query->paginate($perPage))
            ->additional(['summary' => $this->summary()]);
    }

    public function store(ServiceJobRequest $request)
    {
        $data = $request->validated();
        $this->assertContractUsable($data['service_contract_id'] ?? null);

        $job = DB::transaction(function () use ($request, $data) {
            $job = ServiceJob::create([
                'job_number' => ServiceJob::nextNumber(),
                'customer_id' => $data['customer_id'],
                'service_contract_id' => $data['service_contract_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'status' => ! empty($data['assigned_to']) ? 'assigned' : 'received',
                'priority' => $data['priority'] ?? 'normal',
                'item_name' => $data['item_name'],
                'item_serial' => $data['item_serial'] ?? null,
                // Industry-plugin fields, absent for every trade but the ones
                // that put a vehicle on the ramp.
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'odometer_in' => $data['odometer_in'] ?? null,
                // …and for the ones that put a customer's machine on the bench.
                'it_asset_id' => $data['it_asset_id'] ?? null,
                'complaint' => $data['complaint'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'is_warranty' => $data['is_warranty'] ?? false,
                'received_at' => $data['received_at'] ?? now(),
                'promised_at' => $data['promised_at'] ?? null,
                'labour_charge' => $data['labour_charge'] ?? 0,
                'labour_tax_percent' => $data['labour_tax_percent'] ?? 0,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($job, $data['items'] ?? []);
            $this->billing->reprice($job);

            // A contract visit is spent when the job is booked, not when it is
            // finished: the visit was used the moment the technician was sent.
            if ($job->service_contract_id) {
                ServiceContract::whereKey($job->service_contract_id)->increment('visits_used');
            }

            return $job;
        });

        return (new ServiceJobResource($job->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(ServiceJob $serviceJob)
    {
        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    /**
     * Edit the worksheet. Refused once the job is closed, and refused for the
     * lines once it has been billed — the invoice is already out.
     */
    public function update(ServiceJobRequest $request, ServiceJob $serviceJob)
    {
        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'A delivered or cancelled job can no longer be edited.'], 422);
        }

        $data = $request->validated();

        if ($serviceJob->invoice_id && array_key_exists('items', $data)) {
            return response()->json([
                'message' => 'This job is already invoiced; its parts and charges can no longer change.',
            ], 422);
        }

        // `items` is the complete line set, so an issued line has to come back
        // carrying its id — otherwise the server cannot tell it apart from a
        // genuinely new line of the same part, and would bill it twice. Refuse
        // rather than guess: a duplicated spare is money, and silently charging
        // for it is far worse than making the caller resend an id.
        if (array_key_exists('items', $data)) {
            $issuedIds = $serviceJob->items()->where('is_issued', true)->pluck('id')->all();
            $sentIds = array_filter(array_column($data['items'] ?? [], 'id'));
            $missing = array_diff($issuedIds, $sentIds);

            if ($missing !== []) {
                return response()->json([
                    'message' => 'Parts already issued to this job must be sent back with their line id; they cannot be replaced or removed.',
                    'errors' => ['items' => ['Missing issued line id(s): '.implode(', ', $missing).'.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($serviceJob, $data) {
            $serviceJob->update([
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'priority' => $data['priority'] ?? $serviceJob->priority,
                'item_name' => $data['item_name'],
                'item_serial' => $data['item_serial'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? $serviceJob->vehicle_id,
                'odometer_in' => $data['odometer_in'] ?? $serviceJob->odometer_in,
                // Taken when the work is handed back, so it arrives on an edit
                // rather than at intake.
                'odometer_out' => $data['odometer_out'] ?? $serviceJob->odometer_out,
                'complaint' => $data['complaint'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'is_warranty' => $data['is_warranty'] ?? $serviceJob->is_warranty,
                'promised_at' => $data['promised_at'] ?? null,
                'labour_charge' => $data['labour_charge'] ?? 0,
                'labour_tax_percent' => $data['labour_tax_percent'] ?? 0,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            if (array_key_exists('items', $data)) {
                // Issued lines have already moved stock; dropping them would
                // leave the ledger holding a movement with no line behind it,
                // and re-creating them from the payload would bill the same
                // part twice. So they are left exactly as they are, and any
                // payload entry that names one is discarded — the edit form
                // resends every line it displays, issued ones included.
                $issuedIds = $serviceJob->items()->where('is_issued', true)->pluck('id')->all();

                $serviceJob->items()->where('is_issued', false)->delete();

                $this->syncItems($serviceJob, array_filter(
                    $data['items'] ?? [],
                    static fn (array $item) => ! in_array($item['id'] ?? null, $issuedIds, true),
                ));
            }

            $this->billing->reprice($serviceJob->refresh());
        });

        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    public function destroy(ServiceJob $serviceJob)
    {
        if ($serviceJob->invoice_id) {
            return response()->json(['message' => 'An invoiced job cannot be deleted. Cancel it instead.'], 422);
        }
        if ($serviceJob->items()->where('is_issued', true)->exists()) {
            return response()->json([
                'message' => 'Parts have already been issued against this job. Cancel it instead.',
            ], 422);
        }

        $serviceJob->delete();

        return response()->json(['message' => 'Service job deleted.']);
    }

    /** Hand the job to a technician (or move it to another one). */
    public function assign(Request $request, ServiceJob $serviceJob)
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'exists:employees,id'],
        ]);

        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'A delivered or cancelled job cannot be reassigned.'], 422);
        }

        $serviceJob->forceFill([
            'assigned_to' => $data['assigned_to'],
            'status' => $serviceJob->status === 'received' ? 'assigned' : $serviceJob->status,
        ])->save();

        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    /** Move the job between the working states (start, hold, resume). */
    public function progress(Request $request, ServiceJob $serviceJob)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'on_hold'])],
            'diagnosis' => ['nullable', 'string'],
        ]);

        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'A delivered or cancelled job cannot be reopened.'], 422);
        }

        $serviceJob->forceFill(array_filter([
            'status' => $data['status'],
            'diagnosis' => $data['diagnosis'] ?? $serviceJob->diagnosis,
            'started_at' => $serviceJob->started_at ?? ($data['status'] === 'in_progress' ? now() : null),
        ], fn ($v) => $v !== null))->save();

        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    /**
     * Issue the parts the technician has fitted. Safe to call repeatedly — only
     * lines not yet issued move.
     */
    public function issue(Request $request, ServiceJob $serviceJob)
    {
        $data = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);

        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'A delivered or cancelled job cannot issue parts.'], 422);
        }

        try {
            $issued = DB::transaction(fn () => $this->billing->issueParts(
                $serviceJob,
                $data['item_ids'] ?? null,
                $request->user()?->id,
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return (new ServiceJobResource($serviceJob->load($this->relations())))
            ->additional(['issued_lines' => $issued]);
    }

    /**
     * Finish the work: issue anything still outstanding, price the job and
     * raise its invoice. Warranty and contract jobs complete without one.
     */
    public function complete(Request $request, ServiceJob $serviceJob)
    {
        $data = $request->validate([
            'resolution' => ['nullable', 'string'],
            // Defaults to true: a job is finished when the parts are in the
            // machine, so leaving them un-issued is almost always an oversight.
            'issue_pending_parts' => ['nullable', 'boolean'],
        ]);

        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'This job is already closed.'], 422);
        }
        if ($serviceJob->invoice_id) {
            return response()->json(['message' => 'This job has already been billed.'], 422);
        }

        try {
            $invoice = DB::transaction(function () use ($request, $serviceJob, $data) {
                if ($data['issue_pending_parts'] ?? true) {
                    $this->billing->issueParts($serviceJob, null, $request->user()?->id);
                }

                $serviceJob->forceFill([
                    'resolution' => $data['resolution'] ?? $serviceJob->resolution,
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                $this->billing->reprice($serviceJob->refresh());

                return $this->billing->bill($serviceJob, $request->user()?->id);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return (new ServiceJobResource($serviceJob->load($this->relations())))
            ->additional(['invoice_raised' => (bool) $invoice]);
    }

    /** Hand the item back to the customer. Terminal. */
    public function deliver(ServiceJob $serviceJob)
    {
        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'This job is already closed.'], 422);
        }
        if ($serviceJob->status !== 'completed') {
            return response()->json(['message' => 'Complete the job before delivering it.'], 422);
        }

        $serviceJob->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    /**
     * Abandon the job. Any contract visit it consumed is handed back, since no
     * work was done — but issued parts are deliberately *not* restocked: they
     * left the shelf, and a stock return is the honest way to bring them back.
     */
    public function cancel(Request $request, ServiceJob $serviceJob)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($serviceJob->isClosed()) {
            return response()->json(['message' => 'This job is already closed.'], 422);
        }
        if ($serviceJob->invoice_id) {
            return response()->json([
                'message' => 'This job is invoiced. Raise a credit note against the invoice instead.',
            ], 422);
        }

        DB::transaction(function () use ($serviceJob, $data) {
            if ($serviceJob->service_contract_id) {
                ServiceContract::whereKey($serviceJob->service_contract_id)
                    ->where('visits_used', '>', 0)
                    ->decrement('visits_used');
            }

            $serviceJob->forceFill([
                'status' => 'cancelled',
                'notes' => trim((string) $serviceJob->notes."\nCancelled: ".($data['reason'] ?? '—')),
            ])->save();
        });

        return new ServiceJobResource($serviceJob->load($this->relations()));
    }

    /** Counts per status plus the open/overdue headline for the board. */
    private function summary(): array
    {
        $byStatus = ServiceJob::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'by_status' => $byStatus,
            'open' => (int) $byStatus->except(['delivered', 'cancelled'])->sum(),
            'overdue' => ServiceJob::whereNotNull('promised_at')
                ->where('promised_at', '<', now())
                ->whereNotIn('status', ['completed', 'delivered', 'cancelled'])
                ->count(),
            'unbilled_value' => (float) ServiceJob::whereNull('invoice_id')
                ->where('is_warranty', false)
                ->whereNull('service_contract_id')
                ->whereNotIn('status', ['cancelled'])
                ->sum('total'),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function syncItems(ServiceJob $job, array $items): void
    {
        foreach ($items as $item) {
            $job->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_type' => $item['discount_type'] ?? null,
                'discount_value' => $item['discount_value'] ?? 0,
                'tax_percent' => $item['tax_percent'] ?? 0,
            ]);
        }
    }

    /**
     * A contract that has lapsed or run out of visits must not silently make a
     * job non-billable — that is how work gets done for free by accident.
     */
    private function assertContractUsable(?int $contractId): void
    {
        if (! $contractId) {
            return;
        }

        $contract = ServiceContract::find($contractId);

        if ($contract && ! $contract->coversToday()) {
            throw ValidationException::withMessages([
                'service_contract_id' => [
                    "Contract {$contract->contract_number} is not active, is outside its term, or has no visits left.",
                ],
            ]);
        }
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'customer:id,name,phone', 'contract', 'warehouse:id,name',
            'technician:id,employee_code,first_name,last_name', 'items.product:id,sku,name,is_service',
            'invoice:id,invoice_number,total,status', 'creator:id,name',
            // Whatever an industry vertical has hung off the job — a vehicle,
            // for a workshop. Empty when no such plugin is registered, so the
            // relation is never asked for unless something defined it.
            ...array_values(ServiceJob::$pluginRelations),
        ];
    }
}
