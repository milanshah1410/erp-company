<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentContainerResource;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Container / ULD tracking across every shipment — the Container Status board
 * (SRS §24). The list is deliberately cross-shipment: the desk works the boxes,
 * not the paperwork, and needs to see which are still at the port, which are
 * running out of free days, and which are yet to come back empty.
 *
 * A status move (`move`) stamps the box, its location and the moment, and appends
 * the matching milestone to the parent shipment's timeline.
 */
class ShipmentContainerController extends Controller
{
    public function index(Request $request)
    {
        $query = ShipmentContainer::query()
            ->with(['shipment:id,shipment_number,direction,status,eta']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('container_number', 'like', "%{$search}%")
                    ->orWhere('seal_number', 'like', "%{$search}%")
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }
        foreach (['shipment_id', 'status', 'container_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->whereHas('shipment', fn ($s) => $s->where('direction', $request->query('direction')));
        }
        // Boxes past their free days and not yet returned — the money leak the
        // board exists to surface.
        if ($request->boolean('detention_only')) {
            $query->whereNotNull('detention_free_until')
                ->whereDate('detention_free_until', '<', now()->toDateString())
                ->where('status', '!=', 'empty_returned');
        }
        if ($request->boolean('open_only')) {
            $query->where('status', '!=', 'empty_returned');
        }
        // Boxes surveyed damaged — the claim list, and what the board's
        // "damaged" chip drills into.
        if ($request->boolean('damaged_only')) {
            $query->where('is_damaged', true);
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShipmentContainerResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $shipment = Shipment::findOrFail($data['shipment_id']);

        $data['status'] = $data['status'] ?? 'planned';

        $container = DB::transaction(function () use ($shipment, $data, $request) {
            $container = $shipment->containers()->create($data + ['status_updated_at' => now()]);
            $shipment->logEvent('other', [
                'description' => "Container {$container->container_number} added",
                'created_by' => $request->user()?->id,
            ]);

            return $container;
        });

        return (new ShipmentContainerResource($container->load('shipment:id,shipment_number,direction,status,eta')))
            ->response()->setStatusCode(201);
    }

    public function show(ShipmentContainer $shipmentContainer)
    {
        return new ShipmentContainerResource(
            $shipmentContainer->load('shipment:id,shipment_number,direction,status,eta')
        );
    }

    public function update(Request $request, ShipmentContainer $shipmentContainer)
    {
        $data = $this->validated($request, $shipmentContainer);
        unset($data['shipment_id']); // a box never moves to another shipment
        foreach (['status', 'is_damaged'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]); // blank means "leave as is" (status moves via move())
            }
        }

        $shipmentContainer->update($data);

        return new ShipmentContainerResource(
            $shipmentContainer->load('shipment:id,shipment_number,direction,status,eta')
        );
    }

    public function destroy(ShipmentContainer $shipmentContainer)
    {
        $shipmentContainer->delete();

        return response()->json(['message' => 'Container removed.']);
    }

    /**
     * Record a physical move: new status, where it happened and when. Free days
     * are counted from the gate-in, so that move also sets the detention clock
     * when the carrier's allowance is known.
     */
    public function move(Request $request, ShipmentContainer $shipmentContainer)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ShipmentContainer::STATUSES)],
            'current_location' => ['nullable', 'string', 'max:255'],
            'moved_at' => ['nullable', 'date'],
            'is_damaged' => ['nullable', 'boolean'],
            'damage_remarks' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $movedAt = $data['moved_at'] ?? now();
        $attributes = [
            'status' => $data['status'],
            'current_location' => $data['current_location'] ?? $shipmentContainer->current_location,
            'status_updated_at' => $movedAt,
        ];

        // Free days start at gate-in / discharge; set the deadline once we know
        // when the clock started and how many days the carrier allows.
        if (in_array($data['status'], ['gated_in', 'discharged'], true)
            && $shipmentContainer->free_days > 0
            && ! $shipmentContainer->detention_free_until) {
            $attributes['detention_free_until'] = Carbon::parse($movedAt)
                ->addDays($shipmentContainer->free_days)->toDateString();
        }
        // The empty return closes the box out — and stops the detention clock.
        if ($data['status'] === 'empty_returned') {
            $attributes['empty_returned_on'] = Carbon::parse($movedAt)->toDateString();
        }
        // Damage is usually found on exactly one of these moves, so it can be
        // recorded in the same action rather than as a separate edit.
        if ($request->has('is_damaged')) {
            $attributes['is_damaged'] = $request->boolean('is_damaged');
            $attributes['damage_remarks'] = $data['damage_remarks'] ?? $shipmentContainer->damage_remarks;
        }

        DB::transaction(function () use ($shipmentContainer, $attributes, $data, $request, $movedAt) {
            $shipmentContainer->update($attributes);

            $milestone = ShipmentContainer::STATUS_MILESTONES[$data['status']] ?? 'other';
            $shipmentContainer->shipment?->logEvent($milestone, [
                'event_at' => $movedAt,
                'location' => $data['current_location'] ?? null,
                'description' => ($data['remarks'] ?? null)
                    ?: "Container {$shipmentContainer->container_number}: ".str_replace('_', ' ', $data['status']),
                'created_by' => $request->user()?->id,
            ]);
        });

        return new ShipmentContainerResource(
            $shipmentContainer->load('shipment:id,shipment_number,direction,status,eta')
        );
    }

    /** Count of boxes per status, for the board's summary strip. */
    public function summary(Request $request)
    {
        $counts = ShipmentContainer::query()
            ->when($request->filled('direction'), fn ($q) => $q->whereHas(
                'shipment',
                fn ($s) => $s->where('direction', $request->query('direction')),
            ))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $detention = ShipmentContainer::query()
            ->whereNotNull('detention_free_until')
            ->whereDate('detention_free_until', '<', now()->toDateString())
            ->where('status', '!=', 'empty_returned')
            ->count();

        return response()->json([
            'by_status' => array_map(
                fn (string $status) => ['status' => $status, 'total' => (int) ($counts[$status] ?? 0)],
                ShipmentContainer::STATUSES,
            ),
            'in_detention' => $detention,
            'damaged' => ShipmentContainer::query()->where('is_damaged', true)->count(),
            'total' => (int) $counts->sum(),
        ]);
    }

    private function validated(Request $request, ?ShipmentContainer $existing = null): array
    {
        // A box number is the shipment's own key for the box — the same number
        // twice on one consignment is a keying slip, and it would make every
        // downstream reference (detention charge, damage claim, empty return)
        // ambiguous. The same box may of course reappear on a later shipment.
        $shipmentId = $existing?->shipment_id ?? $request->input('shipment_id');

        return $request->validate([
            'shipment_id' => [$existing ? 'nullable' : 'required', 'exists:shipments,id'],
            'container_number' => [
                'required', 'string', 'max:255',
                Rule::unique('shipment_containers', 'container_number')
                    ->where('shipment_id', $shipmentId)
                    ->ignore($existing?->id),
            ],
            'container_type' => ['required', Rule::in(['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'])],
            'seal_number' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(ShipmentContainer::STATUSES)],
            'current_location' => ['nullable', 'string', 'max:255'],
            'packages' => ['nullable', 'integer', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'free_days' => ['nullable', 'integer', 'min:0'],
            'detention_free_until' => ['nullable', 'date'],
            'empty_returned_on' => ['nullable', 'date'],
            'is_damaged' => ['nullable', 'boolean'],
            'damage_remarks' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ], [
            'container_number.unique' => 'Container :input is already booked on this shipment.',
        ]);
    }
}
