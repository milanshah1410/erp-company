<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentPackingItemResource;
use App\Models\Shipment;
use App\Models\ShipmentPackingItem;
use App\Support\TradeCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The packing list of one shipment (SRS §24, export chain) — the cargo package
 * by package, which the shipping bill, the carrier's B/L and the buyer's customs
 * broker all read the consignment from.
 *
 * Lines are always addressed through their shipment: a packing list has no
 * meaning apart from the consignment it describes, so there is no cross-shipment
 * list here the way there is for containers or expenses.
 *
 * Every write rolls the header's packages / weight / CBM up from the lines
 * (Shipment::syncCargoTotals), so the figures on the shipment, on the booking
 * and on the packing list can never disagree.
 */
class ShipmentPackingItemController extends Controller
{
    /** The list, its totals and the box-wise split the stuffing plan is read from. */
    public function index(Shipment $shipment)
    {
        $items = $shipment->packingItems()->with(['container:id,container_number', 'product:id,name,sku'])->get();

        return ShipmentPackingItemResource::collection($items)->additional([
            'meta' => [
                'shipment_id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'direction' => $shipment->direction,
                'totals' => [
                    'lines' => $items->count(),
                    'packages' => (int) $items->sum('package_count'),
                    'quantity' => round((float) $items->sum('quantity'), 3),
                    'net_weight_kg' => round((float) $items->sum('net_weight_kg'), 3),
                    'gross_weight_kg' => round((float) $items->sum('gross_weight_kg'), 3),
                    'cbm' => round((float) $items->sum('cbm'), 3),
                ],
                // Unstuffed cargo sits under a null container, so the desk can
                // see at a glance what still has to be assigned to a box.
                'by_container' => $items->groupBy('shipment_container_id')->map(fn ($group, $id) => [
                    'shipment_container_id' => $id === '' ? null : (int) $id,
                    'container_number' => $group->first()->container?->container_number,
                    'lines' => $group->count(),
                    'packages' => (int) $group->sum('package_count'),
                    'gross_weight_kg' => round((float) $group->sum('gross_weight_kg'), 3),
                    'cbm' => round((float) $group->sum('cbm'), 3),
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request, Shipment $shipment)
    {
        $this->assertOpen($shipment);
        $data = $this->validated($request, $shipment);

        $item = DB::transaction(function () use ($shipment, $data) {
            $item = $shipment->packingItems()->create($data + [
                'sort_order' => (int) $shipment->packingItems()->max('sort_order') + 1,
            ]);
            $shipment->syncCargoTotals();

            return $item;
        });

        return (new ShipmentPackingItemResource($item->load(['container:id,container_number', 'product:id,name,sku'])))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, ShipmentPackingItem $shipmentPackingItem)
    {
        $shipment = $shipmentPackingItem->shipment;
        $this->assertOpen($shipment);
        $data = $this->validated($request, $shipment);

        DB::transaction(function () use ($shipmentPackingItem, $data, $shipment) {
            $shipmentPackingItem->update($data);
            $shipment->syncCargoTotals();
        });

        return new ShipmentPackingItemResource(
            $shipmentPackingItem->fresh()->load(['container:id,container_number', 'product:id,name,sku'])
        );
    }

    public function destroy(ShipmentPackingItem $shipmentPackingItem)
    {
        $shipment = $shipmentPackingItem->shipment;
        $this->assertOpen($shipment);

        DB::transaction(function () use ($shipmentPackingItem, $shipment) {
            $shipmentPackingItem->delete();
            $shipment->syncCargoTotals();
        });

        return response()->json(['message' => 'Packing line removed.']);
    }

    /**
     * Stuff lines into a container in one go — the move the desk actually makes,
     * rather than editing each line to point at the same box.
     */
    public function assignContainer(Request $request, Shipment $shipment)
    {
        $this->assertOpen($shipment);

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            // Null un-stuffs the lines again.
            'shipment_container_id' => ['present', 'nullable', 'integer'],
        ]);

        if ($data['shipment_container_id'] !== null
            && ! $shipment->containers()->whereKey($data['shipment_container_id'])->exists()) {
            throw ValidationException::withMessages([
                'shipment_container_id' => ['That container is not on this shipment.'],
            ]);
        }

        $updated = $shipment->packingItems()
            ->whereIn('id', $data['item_ids'])
            ->update(['shipment_container_id' => $data['shipment_container_id']]);

        return response()->json([
            'message' => $data['shipment_container_id'] === null
                ? "{$updated} line(s) unstuffed."
                : "{$updated} line(s) stuffed.",
            'updated' => $updated,
        ]);
    }

    /** A packing list is cargo detail; it is frozen once the shipment is. */
    private function assertOpen(?Shipment $shipment): void
    {
        if (! $shipment || in_array($shipment->status, ['closed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'shipment' => ['The packing list of a closed or cancelled shipment can no longer be edited.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Shipment $shipment): array
    {
        $data = $request->validate([
            'shipment_container_id' => ['nullable', 'integer'],
            'sales_item_id' => ['nullable', 'exists:sales_items,id'],
            'purchase_item_id' => ['nullable', 'exists:purchase_items,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'description' => ['required', 'string', 'max:255'],
            'marks_numbers' => ['nullable', 'string', 'max:255'],
            'package_type' => ['required', Rule::in(array_keys(TradeCatalogue::PACKAGE_TYPES))],
            'package_count' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'uom' => ['nullable', 'string', 'max:20'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // A box belongs to its shipment; pointing a line at someone else's
        // container would silently mis-state two consignments at once.
        if (! empty($data['shipment_container_id'])
            && ! $shipment->containers()->whereKey($data['shipment_container_id'])->exists()) {
            throw ValidationException::withMessages([
                'shipment_container_id' => ['That container is not on this shipment.'],
            ]);
        }

        if (($data['gross_weight_kg'] ?? 0) > 0 && ($data['net_weight_kg'] ?? 0) > (float) $data['gross_weight_kg']) {
            throw ValidationException::withMessages([
                'net_weight_kg' => ['Net weight cannot exceed gross weight.'],
            ]);
        }

        return $data;
    }
}
