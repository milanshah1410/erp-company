<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShipmentRequest;
use App\Http\Resources\ShipmentEventResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Support\TradeCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Import & Export shipments (SRS §24) — the module's core document, serving both
 * the Active Import Shipments and Active Export Shipments screens through the
 * `direction` filter.
 *
 * Lifecycle: draft → booked → in_transit → arrived → customs → cleared →
 * delivered → closed (Shipment::TRANSITIONS), with cancel available until the
 * goods move. Every transition appends a timeline milestone, so the Shipment
 * Timeline screen is a straight read of what the workflow already recorded.
 *
 * Customs sits on its own track (`customs_status`) and is driven from here too,
 * because filing an entry and paying duty are shipment-level acts that the
 * Pending Customs board watches.
 */
class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Shipment::query()
            ->with([
                'supplier:id,name', 'customer:id,name',
                'shippingLine:id,name', 'airline:id,name',
                'portOfLoading:id,name,code', 'portOfDischarge:id,name,code',
            ])
            ->withCount(['containers', 'documents', 'pendingDocuments', 'expenses']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('bl_awb_number', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('vessel_name', 'like', "%{$search}%");
            });
        }

        // The lane filters (port / country / carrier) are what the port-wise and
        // country-wise reports are built on.
        foreach ([
            'direction', 'status', 'customs_status', 'payment_status', 'mode', 'incoterm',
            'supplier_id', 'customer_id', 'shipping_line_id', 'airline_id',
            'freight_forwarder_id', 'cha_agent_id', 'transporter_id',
            'port_of_loading_id', 'port_of_discharge_id',
            'origin_country_id', 'destination_country_id',
        ] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The "active shipments" screens default to everything still in play;
        // pass state=all (or an explicit status) to see the closed ones too.
        if (! $request->filled('status') && $request->query('state', 'open') === 'open') {
            $query->open();
        }

        if ($request->filled('eta_from')) {
            $query->whereDate('eta', '>=', $request->query('eta_from'));
        }
        if ($request->filled('eta_to')) {
            $query->whereDate('eta', '<=', $request->query('eta_to'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipment_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShipmentResource::collection($query->paginate($perPage));
    }

    public function store(ShipmentRequest $request)
    {
        $data = $request->payload();
        $direction = $request->validated()['direction'];

        $shipment = DB::transaction(function () use ($request, $data, $direction) {
            $shipment = Shipment::create($data + [
                'shipment_number' => Shipment::nextNumber($direction),
                'direction' => $direction,
                'status' => 'draft',
                'customs_entry_type' => $direction === 'import' ? 'bill_of_entry' : 'shipping_bill',
                'created_by' => $request->user()?->id,
            ]);

            // Start the document checklist off with the mandatory set for this
            // leg, so the Pending Documents board is useful immediately.
            if ($request->boolean('seed_documents', true)) {
                foreach (TradeCatalogue::mandatoryDocumentsFor($direction) as $docType) {
                    $shipment->documents()->create([
                        'doc_type' => $docType,
                        'status' => 'pending',
                        'is_mandatory' => true,
                        'due_date' => $shipment->etd ?? $shipment->eta,
                    ]);
                }
            }

            $shipment->logEvent('other', [
                'description' => ucfirst($direction).' shipment created',
                'created_by' => $request->user()?->id,
            ]);

            return $shipment;
        });

        return (new ShipmentResource($shipment->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(Shipment $shipment)
    {
        return new ShipmentResource($shipment->load($this->relations()));
    }

    public function update(ShipmentRequest $request, Shipment $shipment)
    {
        if (in_array($shipment->status, ['closed', 'cancelled'], true)) {
            return response()->json(['message' => 'A closed or cancelled shipment can no longer be edited.'], 422);
        }

        $before = $shipment->only(['eta', 'etd']);
        $shipment->update($request->payload());

        // A moved ETA/ETD is the single most consequential edit on this screen —
        // the desk plans berthing, clearing and delivery around it, so it goes on
        // the timeline rather than only into the audit log.
        $shipment->refresh();
        if ($before['eta']?->toDateString() !== $shipment->eta?->toDateString()
            || $before['etd']?->toDateString() !== $shipment->etd?->toDateString()) {
            $shipment->logEvent('delay_reported', [
                'description' => sprintf(
                    'Schedule revised — ETD %s, ETA %s',
                    $shipment->etd?->toDateString() ?? '—',
                    $shipment->eta?->toDateString() ?? '—',
                ),
                'created_by' => $request->user()?->id,
            ]);
        }

        return new ShipmentResource($shipment->load($this->relations()));
    }

    public function destroy(Shipment $shipment)
    {
        if ($shipment->status !== 'draft') {
            return response()->json(['message' => 'Only draft shipments can be deleted.'], 422);
        }
        $shipment->delete();

        return response()->json(['message' => 'Shipment deleted.']);
    }

    /**
     * Advance (or cancel) the shipment. Only the moves declared in
     * Shipment::TRANSITIONS are accepted, and the actual departure/arrival dates
     * are stamped as the shipment passes those milestones.
     */
    public function changeStatus(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Shipment::TRANSITIONS))],
            'event_date' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);
        $target = $data['status'];

        if (! in_array($target, Shipment::TRANSITIONS[$shipment->status], true)) {
            return response()->json([
                'message' => "A {$shipment->status} shipment cannot move to {$target}.",
            ], 422);
        }

        $when = $data['event_date'] ?? now()->toDateString();
        $attributes = ['status' => $target];

        // Stamp the actuals as the shipment passes them — the ETA/ETD board
        // compares these against the estimates to compute the delay.
        if ($target === 'in_transit' && ! $shipment->atd) {
            $attributes['atd'] = $when;
        }
        if ($target === 'arrived' && ! $shipment->ata) {
            $attributes['ata'] = $when;
        }

        DB::transaction(function () use ($shipment, $attributes, $target, $data, $request, $when) {
            $shipment->update($attributes);
            $shipment->logEvent(Shipment::STATUS_MILESTONES[$target] ?? 'status_changed', [
                'event_at' => $when,
                'location' => $data['location'] ?? null,
                'description' => ($data['remarks'] ?? null) ?: 'Status changed to '.str_replace('_', ' ', $target),
                'created_by' => $request->user()?->id,
            ]);
        });

        return new ShipmentResource($shipment->load($this->relations()));
    }

    /**
     * File / assess / pay / clear customs for this shipment. One endpoint drives
     * the whole customs track because the duty breakdown is edited alongside the
     * state it belongs to, and the duty total must always be the sum of its parts.
     *
     * The entry is leg-bound: an import is cleared on a Bill of Entry, an export
     * on a Shipping Bill. Filing it also marks that document received on the
     * shipment's document set, so Pending Documents stops chasing a document the
     * desk is holding in its hand.
     */
    public function customs(Request $request, Shipment $shipment)
    {
        $entryType = $shipment->direction === 'import' ? 'bill_of_entry' : 'shipping_bill';
        $entryLabel = TradeCatalogue::documentLabel($entryType);

        $data = $request->validate([
            'customs_status' => ['required', Rule::in(['pending', 'filed', 'assessed', 'duty_paid', 'cleared', 'on_hold'])],
            'customs_entry_type' => ['nullable', Rule::in([$entryType])],
            'customs_entry_number' => ['nullable', 'string', 'max:255'],
            'customs_entry_date' => ['nullable', 'date'],
            'icegate_reference' => ['nullable', 'string', 'max:255'],
            'cha_agent_id' => ['nullable', 'exists:cha_agents,id'],
            'assessable_value' => ['nullable', 'numeric', 'min:0'],
            'duty_bcd' => ['nullable', 'numeric', 'min:0'],
            'duty_igst' => ['nullable', 'numeric', 'min:0'],
            'duty_cess' => ['nullable', 'numeric', 'min:0'],
            'duty_other' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ], [
            'customs_entry_type.in' => "An {$shipment->direction} shipment is cleared on a {$entryLabel}.",
        ]);

        // Nothing is filed without an entry number — every later state (assessed,
        // duty paid, cleared) refers back to it, and so does the ICEGATE trail.
        $entryNumber = $data['customs_entry_number'] ?? $shipment->customs_entry_number;
        if (! $entryNumber && in_array($data['customs_status'], ['filed', 'assessed', 'duty_paid', 'cleared'], true)) {
            throw ValidationException::withMessages([
                'customs_entry_number' => ["Enter the {$entryLabel} number before marking customs "
                    .str_replace('_', ' ', $data['customs_status']).'.'],
            ]);
        }

        $duty = [
            'duty_bcd' => (float) ($data['duty_bcd'] ?? $shipment->duty_bcd),
            'duty_igst' => (float) ($data['duty_igst'] ?? $shipment->duty_igst),
            'duty_cess' => (float) ($data['duty_cess'] ?? $shipment->duty_cess),
            'duty_other' => (float) ($data['duty_other'] ?? $shipment->duty_other),
        ];

        $attributes = array_filter([
            // The leg decides the entry, so it needs no keying — and a shipment
            // created before this rule cannot keep the wrong one.
            'customs_entry_type' => $entryNumber ? $entryType : ($data['customs_entry_type'] ?? null),
            'customs_entry_number' => $data['customs_entry_number'] ?? null,
            'customs_entry_date' => $data['customs_entry_date'] ?? null,
            'icegate_reference' => $data['icegate_reference'] ?? null,
            'cha_agent_id' => $data['cha_agent_id'] ?? null,
            'assessable_value' => $data['assessable_value'] ?? null,
        ], fn ($v) => $v !== null) + $duty + [
            'customs_status' => $data['customs_status'],
            'duty_total' => round(array_sum($duty), 2),
        ];

        if ($data['customs_status'] === 'duty_paid' && ! $shipment->duty_paid_at) {
            $attributes['duty_paid_at'] = now();
        }
        if ($data['customs_status'] === 'cleared') {
            $attributes['customs_cleared_at'] = $shipment->customs_cleared_at ?? now();
            $attributes['duty_paid_at'] = $shipment->duty_paid_at ?? now();
        }

        DB::transaction(function () use ($shipment, $attributes, $data, $request, $entryType, $entryNumber) {
            $shipment->update($attributes);

            // The entry and the document are the same piece of paper. Filing it
            // fills in the checklist row seeded with the shipment (or adds it,
            // for one seeded without documents) so the two never disagree.
            if ($entryNumber && $data['customs_status'] !== 'pending') {
                $document = $shipment->documents()->firstOrNew(['doc_type' => $entryType]);
                $document->fill([
                    'doc_number' => $entryNumber,
                    'doc_date' => $shipment->customs_entry_date ?? $document->doc_date,
                    // 'submitted' until customs signs it off; 'verified' once cleared.
                    'status' => $data['customs_status'] === 'cleared' ? 'verified' : 'submitted',
                    'is_mandatory' => $document->exists ? $document->is_mandatory : true,
                ])->save();
            }

            $shipment->logEvent(match ($data['customs_status']) {
                'filed' => 'customs_filed',
                'duty_paid' => 'duty_paid',
                'cleared' => 'customs_cleared',
                default => 'status_changed',
            }, [
                'description' => ($data['remarks'] ?? null) ?: 'Customs '.str_replace('_', ' ', $data['customs_status']),
                'created_by' => $request->user()?->id,
            ]);
        });

        return new ShipmentResource($shipment->load($this->relations()));
    }

    /** The shipment's milestone thread (Shipment Timeline screen). */
    public function timeline(Shipment $shipment)
    {
        $events = $shipment->events()
            ->with('creator:id,name')
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'shipment' => new ShipmentResource(
                $shipment->load(['supplier:id,name', 'customer:id,name'])
                    ->loadCount(['containers', 'documents', 'pendingDocuments']),
            ),
            'data' => ShipmentEventResource::collection($events),
        ]);
    }

    /** Add a manual milestone to the timeline. */
    public function addEvent(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'event_type' => ['required', Rule::in(array_keys(TradeCatalogue::MILESTONES))],
            'event_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $event = $shipment->logEvent($data['event_type'], [
            'event_at' => $data['event_at'],
            'location' => $data['location'] ?? null,
            'description' => ($data['description'] ?? null) ?: TradeCatalogue::milestoneLabel($data['event_type']),
            'source' => 'manual',
            'created_by' => $request->user()?->id,
        ]);

        return (new ShipmentEventResource($event->load('creator:id,name')))
            ->response()->setStatusCode(201);
    }

    /** Remove a manually added milestone (system entries are the audit trail). */
    public function destroyEvent(Shipment $shipment, int $event)
    {
        $row = $shipment->events()->findOrFail($event);
        if ($row->source !== 'manual') {
            return response()->json(['message' => 'System milestones cannot be deleted.'], 422);
        }
        $row->delete();

        return response()->json(['message' => 'Milestone removed.']);
    }

    /**
     * Document types, cost heads and milestones the UI offers. Served from
     * App\Support\TradeCatalogue so the pickers never hard-code the vocabulary.
     */
    public function catalogue()
    {
        return response()->json(TradeCatalogue::options() + [
            'statuses' => array_keys(Shipment::TRANSITIONS),
            'transitions' => Shipment::TRANSITIONS,
        ]);
    }

    private function relations(): array
    {
        return [
            'supplier:id,name', 'customer:id,name', 'purchaseOrder:id,po_number',
            'salesOrder:id,so_number', 'warehouse:id,name', 'creator:id,name',
            // The ERP documents the leg produced — the chain panels name them,
            // and eager-loading here keeps Shipment::importChain() off lazy loads.
            'purchaseInvoice:id,invoice_number', 'goodsReceipt:id,grn_number',
            'invoice:id,invoice_number', 'dispatchPlan:id,dispatch_number',
            'shippingLine:id,name', 'airline:id,name', 'freightForwarder:id,name',
            'chaAgent:id,name', 'transporter:id,name',
            'originCountry:id,name', 'destinationCountry:id,name',
            'portOfLoading:id,name,code', 'portOfDischarge:id,name,code',
            'containers', 'documents.uploader:id,name', 'expenses',
            'payments.bankAccount:id,name',
            // The packing list travels with the shipment on the detail screen,
            // and its presence is what makes the export chain's step readable.
            'packingItems.container:id,container_number', 'packingItems.product:id,name,sku',
        ];
    }
}
