<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductWarranty;
use App\Modules\Industry\Electronics\Http\Requests\ServiceRequestRequest;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use App\Modules\Industry\Electronics\Models\ServiceRequest;
use App\Modules\Industry\Electronics\Services\RequestConversionService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The service-request desk.
 *
 * This is the screen someone sits at all day with a phone, so the endpoints are
 * shaped around what happens on a call rather than around CRUD. Logging a
 * request looks up the cover and stamps the answer; `progress` moves it along the
 * board in one click; `convert` hands it to the workshop; `close` finishes the
 * ones that never needed a workshop at all — which is most deliveries and demos,
 * and a fair number of faults that turn out to be an unplugged appliance.
 */
class ServiceRequestController extends Controller
{
    public function __construct(private readonly RequestConversionService $conversion) {}

    public function index(Request $request)
    {
        $query = ServiceRequest::query()->with([
            'unit:id,brand,model_name,serial_no,imei_1',
            'customer:id,name,phone',
            'product:id,sku,name',
            'technician:id,name',
        ]);

        if ($search = $request->query('search')) {
            $bare = preg_replace('/[^A-Za-z0-9]/', '', (string) $search);

            $query->where(function ($q) use ($search, $bare) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('complaint', 'like', "%{$search}%")
                    ->orWhereHas('unit', fn ($u) => $u->where('serial_no', 'like', "%{$bare}%")
                        ->orWhere('imei_1', 'like', "%{$bare}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['type', 'source', 'status', 'priority', 'unit_id', 'customer_id', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The day view. Everything unscheduled comes with it: a request with no
        // slot yet is exactly what the desk is meant to be chasing, and hiding it
        // behind a date filter is how it gets forgotten.
        if ($date = $request->query('date')) {
            $query->where(fn ($q) => $q->whereDate('scheduled_at', $date)->orWhereNull('scheduled_at'));
        }

        if ($request->boolean('open')) {
            $query->whereIn('status', ServiceRequest::OPEN_STATUSES);
        }

        if ($request->boolean('overdue')) {
            $query->whereIn('status', ServiceRequest::OPEN_STATUSES)
                ->where(fn ($q) => $q->where('promised_at', '<', now())
                    ->orWhere(fn ($inner) => $inner->whereNull('promised_at')->where('scheduled_at', '<', now())));
        }

        // Unscheduled first, then by slot: an unslotted request is work that has
        // not been promised to anybody yet, and it is the thing most at risk of
        // being dropped.
        $query->orderByRaw('scheduled_at IS NULL DESC')->orderBy('scheduled_at')->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (ServiceRequest $r) => $this->row($r))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(ServiceRequest $serviceRequest)
    {
        $serviceRequest->load([
            'unit:id,brand,model_name,serial_no,imei_1,customer_id',
            'customer:id,name,phone,email',
            'product:id,sku,name',
            'technician:id,name',
            'warehouse:id,name',
            'warranty:id,warranty_number,source,end_date,covers_parts,covers_labour,is_onsite',
            'serviceJob:id,job_number,status,total',
        ]);

        return response()->json(['data' => $this->row($serviceRequest, detailed: true)]);
    }

    public function store(ServiceRequestRequest $request)
    {
        $data = $request->validated();

        $unit = ! empty($data['unit_id']) ? DeviceUnit::find($data['unit_id']) : null;

        if ($unit) {
            $data['customer_id'] ??= $unit->customer_id;
            $data['product_id'] ??= $unit->product_id;

            /**
             * Look the cover up now and record the answer, rather than deriving
             * it whenever the record is read. This is what the desk told the
             * customer on the day, and it has to survive the cover lapsing
             * afterwards — otherwise a promise made in good faith on Monday reads
             * as a mistake on Friday.
             */
            $cover = $this->liveCover($unit);
            $data['warranty_id'] = $cover?->id;
            $data['under_warranty'] = $cover !== null;
            // An onsite cover means a technician is owed; default the visit to
            // match rather than making the desk remember.
            $data['is_onsite'] = $data['is_onsite'] ?? ($cover?->is_onsite ?? true);
        }

        $serviceRequest = ServiceRequest::create($data + [
            'request_number' => ServiceRequest::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->row($serviceRequest->fresh(['unit', 'customer', 'product', 'technician', 'warranty']), detailed: true),
        ], 201);
    }

    public function update(ServiceRequestRequest $request, ServiceRequest $serviceRequest)
    {
        if (! $serviceRequest->isOpen()) {
            return response()->json([
                'message' => "This request is {$serviceRequest->status} and can no longer be edited.",
            ], 422);
        }

        $serviceRequest->update($request->validated());

        return response()->json([
            'data' => $this->row($serviceRequest->fresh(['unit', 'customer', 'product', 'technician', 'warranty']), detailed: true),
        ]);
    }

    /**
     * Move the request one step along the board.
     *
     * Its own endpoint because this is a click, not an edit: the desk scheduling
     * a visit or a supervisor assigning a technician should not have to reopen a
     * form with fifteen fields on it.
     */
    public function progress(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'status' => ['required', 'in:logged,scheduled,assigned,cancelled'],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
        ]);

        if (! $serviceRequest->isOpen()) {
            return response()->json([
                'message' => "A {$serviceRequest->status} request cannot be moved.",
            ], 422);
        }

        // Assigning without a slot is how a technician ends up with work and no
        // idea when it is due, so the two move together.
        if ($data['status'] === 'assigned' && ! ($data['assigned_to'] ?? $serviceRequest->assigned_to)) {
            return response()->json(['message' => 'Name the technician taking this visit.'], 422);
        }
        if ($data['status'] === 'scheduled' && ! ($data['scheduled_at'] ?? $serviceRequest->scheduled_at)) {
            return response()->json(['message' => 'Give the slot this visit is scheduled for.'], 422);
        }

        $serviceRequest->forceFill(array_filter([
            'status' => $data['status'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
        ], static fn ($v) => $v !== null))->save();

        return response()->json([
            'data' => $this->row($serviceRequest->fresh(['unit', 'customer', 'product', 'technician', 'warranty']), detailed: true),
        ]);
    }

    /** Hand the request to the workshop as a job card. */
    public function convert(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:employees,id'],
        ]);

        try {
            $job = $this->conversion->convert(
                $serviceRequest,
                $request->user()?->id,
                $data['assigned_to'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Job card {$job->job_number} opened.",
            'job' => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'is_warranty' => (bool) $job->is_warranty,
            ],
            'data' => $this->row($serviceRequest->fresh(['unit', 'customer', 'product', 'technician', 'warranty']), detailed: true),
        ]);
    }

    /**
     * Finish a request that never needed a workshop.
     *
     * The honest outcome for most deliveries and demos, and for every fault that
     * turned out to be a tripped socket. Closing off the desk keeps those out of
     * the job book, which is what makes the job book worth reading.
     */
    public function close(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'closure_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($serviceRequest->status === 'converted') {
            return response()->json([
                'message' => 'This request became a job card. Close the job card instead.',
            ], 422);
        }
        if (! $serviceRequest->isOpen()) {
            return response()->json(['message' => "This request is already {$serviceRequest->status}."], 422);
        }

        $serviceRequest->close($data['closure_note'] ?? null);

        /**
         * A completed installation commissions the machine, whether or not a job
         * card was ever opened — a fitter who plugged in a television and left
         * did the installation, and the unit register has to agree with reality
         * rather than with whether the paperwork route was taken.
         */
        if ($serviceRequest->type === 'installation' && $serviceRequest->unit_id) {
            DeviceUnit::find($serviceRequest->unit_id)?->markInstalled();
        }

        return response()->json([
            'data' => $this->row($serviceRequest->fresh(['unit', 'customer', 'product', 'technician', 'warranty']), detailed: true),
        ]);
    }

    public function destroy(ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->service_job_id) {
            return response()->json([
                'message' => 'A job card was opened from this request. Cancel the job card instead of deleting the request.',
            ], 422);
        }

        $serviceRequest->delete();

        return response()->json(['message' => 'Request removed.']);
    }

    /**
     * The core warranty card in force on this unit today, preferring the maker's
     * own over a plan the shop sold — same rule the conversion service applies,
     * so the desk's answer and the workshop's cannot disagree.
     */
    private function liveCover(DeviceUnit $unit): ?ProductWarranty
    {
        return ProductWarranty::query()
            ->where('unit_id', $unit->id)
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN source = 'manufacturer' THEN 0 WHEN source = 'extended' THEN 1 ELSE 2 END")
            ->orderBy('end_date')
            ->get()
            ->first(fn (ProductWarranty $w) => $w->covers());
    }

    private function summary(): array
    {
        $open = ServiceRequest::whereIn('status', ServiceRequest::OPEN_STATUSES);

        return [
            'open' => (clone $open)->count(),
            'today' => (clone $open)->whereDate('scheduled_at', now()->toDateString())->count(),
            'unscheduled' => (clone $open)->whereNull('scheduled_at')->count(),
            'overdue' => (clone $open)
                ->where(fn ($q) => $q->where('promised_at', '<', now())
                    ->orWhere(fn ($inner) => $inner->whereNull('promised_at')->where('scheduled_at', '<', now())))
                ->count(),
        ];
    }

    private function row(ServiceRequest $r, bool $detailed = false): array
    {
        $base = [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'type' => $r->type,
            'source' => $r->source,
            'status' => $r->status,
            'priority' => $r->priority,
            'unit_id' => $r->unit_id,
            'unit' => $r->unit?->label(),
            'customer_id' => $r->customer_id,
            'customer' => $r->customer?->name,
            'contact_phone' => $r->contact_phone ?: $r->customer?->phone,
            'product_id' => $r->product_id,
            'product' => $r->product?->name,
            'scheduled_at' => $r->scheduled_at?->toIso8601String(),
            'promised_at' => $r->promised_at?->toIso8601String(),
            'assigned_to' => $r->assigned_to,
            'technician' => $r->technician?->name,
            'is_onsite' => (bool) $r->is_onsite,
            'complaint' => $r->complaint,
            'estimated_cost' => (float) $r->estimated_cost,
            'under_warranty' => (bool) $r->under_warranty,
            'service_job_id' => $r->service_job_id,
            // Derived: `needs_job_card` is what decides whether the board offers
            // Convert or Close, and it must not drift from the service that
            // enforces it.
            'needs_job_card' => $r->needsJobCard(),
            'is_open' => $r->isOpen(),
            'is_overdue' => $r->isOverdue(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'warehouse_id' => $r->warehouse_id,
            'address' => $r->address,
            'warranty_id' => $r->warranty_id,
            'warranty' => $r->warranty ? [
                'warranty_number' => $r->warranty->warranty_number,
                'end_date' => $r->warranty->end_date?->toDateString(),
                'coverage' => $r->warranty->coverageLabel(),
                'is_onsite' => (bool) $r->warranty->is_onsite,
            ] : null,
            'job' => $r->serviceJob ? [
                'id' => $r->serviceJob->id,
                'job_number' => $r->serviceJob->job_number,
                'status' => $r->serviceJob->status,
                'total' => (float) $r->serviceJob->total,
            ] : null,
            'closed_at' => $r->closed_at?->toIso8601String(),
            'closure_note' => $r->closure_note,
            'notes' => $r->notes,
        ];
    }
}
