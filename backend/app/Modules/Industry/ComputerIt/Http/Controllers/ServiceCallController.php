<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\ComputerIt\Http\Requests\ServiceCallRequest;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use App\Modules\Industry\ComputerIt\Models\ServiceCall;
use App\Modules\Industry\ComputerIt\Services\ServiceCallConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The support desk's call book.
 *
 * The screen this feeds is the first thing a support desk opens in the morning,
 * so the list is built around two questions rather than around the records: what
 * is still open, and what are we late on. Both are computed on read — an SLA that
 * only updates when someone touches the row is an SLA nobody is measured by.
 *
 * Most calls end here. `convert` exists for the ones that do not: when the
 * machine has to come in, the call becomes an ordinary service job and everything
 * after that runs through the core Service screens.
 */
class ServiceCallController extends Controller
{
    public function __construct(private readonly ServiceCallConversionService $conversion) {}

    public function index(Request $request)
    {
        $query = ServiceCall::query()->with([
            'customer:id,code,name,phone',
            'asset:id,asset_tag,make,model,serial_no,site',
            'engineer:id,employee_code,first_name,last_name',
            'contract:id,contract_number,title',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('call_number', 'like', "%{$search}%")
                    ->orWhere('complaint', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhereHas('asset', fn ($a) => $a->where('asset_tag', 'like', "%{$search}%")
                        ->orWhere('serial_no', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'type', 'priority', 'source', 'customer_id', 'asset_id', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The board's default view: everything still being worked.
        if ($request->boolean('open_only')) {
            $query->whereIn('status', ServiceCall::OPEN_STATUSES);
        }

        /**
         * Late, or about to be. Measured against attendance rather than closure
         * — see ServiceCall::isSlaBreached — so a call somebody has already been
         * to drops off this list even while the part is on order.
         */
        if ($request->boolean('breached')) {
            $query->whereNull('attended_at')
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', now())
                ->whereIn('status', ServiceCall::OPEN_STATUSES);
        }

        if ($request->filled('on_date')) {
            $query->whereDate('scheduled_at', $request->query('on_date'));
        }

        // Open calls first, then oldest promise first: what the desk is late on
        // rises to the top on its own rather than needing a sort.
        $query->orderByRaw('CASE WHEN status IN ("resolved", "converted", "cancelled") THEN 1 ELSE 0 END')
            ->orderByRaw('sla_due_at IS NULL')
            ->orderBy('sla_due_at')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (ServiceCall $c) => $this->row($c))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(ServiceCall $call)
    {
        $call->load([
            'customer:id,code,name,phone,email',
            'asset:id,asset_tag,make,model,serial_no,site,department,assigned_user,customer_id',
            'engineer:id,employee_code,first_name,last_name',
            'contract:id,contract_number,title,end_date,visits_allowed,visits_used,status,start_date',
            'warranty:id,warranty_number,end_date,service_level,status,start_date',
            'serviceJob:id,job_number,status,total',
        ]);

        return response()->json(['data' => $this->row($call, detailed: true)]);
    }

    public function store(ServiceCallRequest $request)
    {
        $data = $request->validated();

        $loggedAt = ! empty($data['logged_at']) ? Carbon::parse($data['logged_at']) : now();
        $priority = $data['priority'] ?? 'normal';

        $asset = ! empty($data['asset_id']) ? CustomerAsset::find($data['asset_id']) : null;

        /**
         * Cover is resolved at logging so the person on the phone can be told
         * whether this visit costs anything — which is the question they are
         * about to ask. It is resolved *again* at conversion, because cover can
         * lapse between the call and the machine arriving.
         *
         * A cover named on the request wins: somebody at the desk looked it up
         * and decided, and the machine's newest cover is not always the one the
         * customer is arguing about.
         */
        $warrantyId = $data['warranty_id'] ?? $asset?->liveWarranty()?->id;
        $amcLive = $asset?->amcCoversToday() ?? false;
        $contractId = $data['contract_id'] ?? ($amcLive ? $asset?->amc_contract_id : null);

        // Assembled explicitly rather than unioned over $data: every key below
        // also appears in the request rules, and a union would silently keep an
        // explicit null the client sent instead of the value derived here.
        $call = ServiceCall::create(array_merge($data, [
            'call_number' => ServiceCall::nextNumber(),
            'logged_at' => $loggedAt,
            'priority' => $priority,
            // Stored, not derived on read — see the column comment. A promise
            // made at normal priority stays a normal-priority promise even after
            // somebody escalates the call.
            'sla_due_at' => $data['sla_due_at'] ?? ServiceCall::slaDueFrom($priority, $loggedAt),
            'warranty_id' => $warrantyId,
            'contract_id' => $contractId,
            'is_chargeable' => $warrantyId === null && $contractId === null,
            // Site details default from the machine's own record: the address an
            // engineer needs is the one where the machine is, and re-keying it
            // per call is how half of them end up blank.
            'site_address' => $data['site_address'] ?? $asset?->site,
            'status' => ! empty($data['assigned_to'])
                ? 'assigned'
                : (! empty($data['scheduled_at']) ? 'scheduled' : 'logged'),
            'created_by' => $request->user()?->id,
        ]));

        return response()->json(
            ['data' => $this->row($call->fresh(['customer', 'asset', 'engineer', 'contract']), detailed: true)],
            201,
        );
    }

    public function update(ServiceCallRequest $request, ServiceCall $call)
    {
        if (in_array($call->status, ['converted', 'cancelled'], true)) {
            return response()->json([
                'message' => "A {$call->status} call can no longer be edited.",
            ], 422);
        }

        $call->update($request->validated());

        return response()->json(
            ['data' => $this->row($call->fresh(['customer', 'asset', 'engineer', 'contract']), detailed: true)],
        );
    }

    /**
     * Move the call along.
     *
     * One endpoint per *fact*, not one writable status field: each step stamps a
     * time that the desk is measured on, and letting a client set `status`
     * directly means those timestamps get filled in by whoever remembers to.
     */
    public function progress(Request $request, ServiceCall $call)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'assigned', 'attended', 'resolved', 'cancelled'])],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
            'resolution' => ['nullable', 'string'],
        ]);

        if (in_array($call->status, ['converted', 'cancelled'], true)) {
            return response()->json([
                'message' => "This call is already {$call->status}.",
            ], 422);
        }

        $changes = ['status' => $data['status']];

        if (isset($data['scheduled_at'])) {
            $changes['scheduled_at'] = $data['scheduled_at'];
        }
        if (isset($data['assigned_to'])) {
            $changes['assigned_to'] = $data['assigned_to'];
        }

        if ($data['status'] === 'attended') {
            // Only stamped once: the first arrival is what the response promise
            // was about, and a second visit must not make a late call look
            // punctual.
            $changes['attended_at'] = $call->attended_at ?? now();
        }

        if (in_array($data['status'], ['resolved', 'cancelled'], true)) {
            $changes['closed_at'] = now();
            // A call resolved without anyone ever going counts as attended at
            // the moment it was fixed — a phone fix is still a response.
            $changes['attended_at'] = $call->attended_at ?? ($data['status'] === 'resolved' ? now() : null);
            if (! empty($data['resolution'])) {
                $changes['resolution'] = $data['resolution'];
            }
        }

        $call->forceFill($changes)->save();

        return response()->json(
            ['data' => $this->row($call->fresh(['customer', 'asset', 'engineer', 'contract']), detailed: true)],
        );
    }

    /**
     * The machine is coming in — open a job card.
     *
     * The service does the work, including re-checking cover on the day, because
     * the same act happens from the machine's own page and both must settle who
     * pays the same way.
     */
    public function convert(Request $request, ServiceCall $call)
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:employees,id'],
        ]);

        try {
            $job = $this->conversion->convert($call, $request->user()?->id, $data['assigned_to'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($call->fresh(['customer', 'asset', 'engineer', 'contract']), detailed: true),
            'service_job' => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'is_warranty' => (bool) $job->is_warranty,
                'service_contract_id' => $job->service_contract_id,
            ],
        ], 201);
    }

    public function destroy(ServiceCall $call)
    {
        if ($call->service_job_id) {
            return response()->json([
                'message' => 'This call has a job card against it. Cancel it instead of deleting it.',
            ], 422);
        }

        $call->delete();

        return response()->json(['message' => 'Call removed from the book.']);
    }

    private function summary(): array
    {
        $open = ServiceCall::whereIn('status', ServiceCall::OPEN_STATUSES);

        return [
            'open' => (clone $open)->count(),
            'unassigned' => (clone $open)->whereNull('assigned_to')->count(),
            'today' => ServiceCall::whereDate('scheduled_at', now()->toDateString())->count(),
            'breached' => (clone $open)->whereNull('attended_at')
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', now())
                ->count(),
            'free_visits' => (clone $open)->where('is_chargeable', false)->count(),
        ];
    }

    private function row(ServiceCall $c, bool $detailed = false): array
    {
        $base = [
            'id' => $c->id,
            'call_number' => $c->call_number,
            'customer_id' => $c->customer_id,
            'customer' => $c->customer?->name,
            'customer_phone' => $c->customer?->phone,
            'asset_id' => $c->asset_id,
            'asset' => $c->asset?->label(),
            'asset_tag' => $c->asset?->asset_tag,
            'site' => $c->site_address ?: $c->asset?->site,
            'type' => $c->type,
            'source' => $c->source,
            'priority' => $c->priority,
            'status' => $c->status,
            'is_open' => $c->isOpen(),
            'complaint' => $c->complaint,
            'logged_at' => $c->logged_at?->toIso8601String(),
            'sla_due_at' => $c->sla_due_at?->toIso8601String(),
            // Both computed: a stored "is late" flag is only true until the next
            // minute passes.
            'is_breached' => $c->isSlaBreached(),
            'sla_minutes_remaining' => $c->slaMinutesRemaining(),
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'attended_at' => $c->attended_at?->toIso8601String(),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'assigned_to' => $c->assigned_to,
            'engineer' => $c->engineer?->fullName(),
            'is_chargeable' => (bool) $c->is_chargeable,
            'contract_id' => $c->contract_id,
            'contract_number' => $c->contract?->contract_number,
            'warranty_id' => $c->warranty_id,
            'service_job_id' => $c->service_job_id,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'contact_person' => $c->contact_person,
            'contact_phone' => $c->contact_phone,
            'site_address' => $c->site_address,
            'resolution' => $c->resolution,
            'notes' => $c->notes,
            'warranty' => $c->warranty ? [
                'id' => $c->warranty->id,
                'warranty_number' => $c->warranty->warranty_number,
                'end_date' => $c->warranty->end_date?->toDateString(),
                'service_level' => $c->warranty->service_level,
                'is_live' => $c->warranty->covers(),
            ] : null,
            'contract' => $c->contract ? [
                'id' => $c->contract->id,
                'contract_number' => $c->contract->contract_number,
                'title' => $c->contract->title,
                'end_date' => $c->contract->end_date?->toDateString(),
                'visits_remaining' => $c->contract->visitsRemaining(),
            ] : null,
            'service_job' => $c->serviceJob ? [
                'id' => $c->serviceJob->id,
                'job_number' => $c->serviceJob->job_number,
                'status' => $c->serviceJob->status,
                'total' => (float) $c->serviceJob->total,
            ] : null,
        ];
    }
}
