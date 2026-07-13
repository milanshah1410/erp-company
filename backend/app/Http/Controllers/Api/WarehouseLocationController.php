<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockLocation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseLocationController extends Controller
{
    /** Nesting order: a child must sit strictly below its parent's level. */
    private const RANK = ['zone' => 0, 'rack' => 1, 'shelf' => 2, 'bin' => 3];

    /** Full location tree for a warehouse, with per-node stock rollup. */
    public function index(Warehouse $warehouse)
    {
        $locations = $warehouse->locations()
            ->withSum('stocks as stock_quantity', 'quantity')
            ->orderBy('code')
            ->get();

        $byParent = $locations->groupBy(fn ($l) => $l->parent_id ?? 0);

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(fn ($l) => [
                'id' => $l->id,
                'parent_id' => $l->parent_id,
                'code' => $l->code,
                'name' => $l->name,
                'type' => $l->type,
                'is_active' => (bool) $l->is_active,
                'stock_quantity' => (float) ($l->stock_quantity ?? 0),
                'children' => $build($l->id)->values(),
            ]);
        };

        return response()->json(['data' => $build(0)->values()]);
    }

    /** Flat list of active bins/shelves for put-away pickers. */
    public function flat(Warehouse $warehouse)
    {
        $locations = $warehouse->locations()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request, Warehouse $warehouse)
    {
        $data = $this->validated($request, $warehouse);

        $location = $warehouse->locations()->create($data);

        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseLocation $location)
    {
        $this->assertBelongs($warehouse, $location);
        $data = $this->validated($request, $warehouse, $location);
        $location->update($data);

        return response()->json(['data' => $location->fresh()]);
    }

    public function destroy(Warehouse $warehouse, WarehouseLocation $location)
    {
        $this->assertBelongs($warehouse, $location);

        if ($location->children()->exists()) {
            return response()->json(['message' => 'This location has sub-locations. Delete them first.'], 422);
        }
        if (StockLocation::where('location_id', $location->id)->where('quantity', '>', 0)->exists()) {
            return response()->json(['message' => 'This location still holds stock. Move it first.'], 422);
        }
        $location->delete();

        return response()->json(['message' => 'Location deleted.']);
    }

    /** Per-location stock breakdown for a warehouse. */
    public function stock(Warehouse $warehouse)
    {
        $rows = StockLocation::query()
            ->whereHas('location', fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->where('quantity', '>', 0)
            ->with(['location:id,code,name,type', 'product:id,sku,name'])
            ->orderBy('location_id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'location' => $s->location ? ['id' => $s->location->id, 'code' => $s->location->code, 'name' => $s->location->name, 'type' => $s->location->type] : null,
                'product' => $s->product ? ['id' => $s->product->id, 'sku' => $s->product->sku, 'name' => $s->product->name] : null,
                'variant_id' => $s->variant_id,
                'quantity' => $s->quantity,
            ]);

        return response()->json(['data' => $rows]);
    }

    private function assertBelongs(Warehouse $warehouse, WarehouseLocation $location): void
    {
        abort_if($location->warehouse_id !== $warehouse->id, 404, 'Location does not belong to this warehouse.');
    }

    private function validated(Request $request, Warehouse $warehouse, ?WarehouseLocation $location = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->ignore($location?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(self::RANK))],
            'parent_id' => [
                'nullable',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouse->id),
            ],
            'is_active' => ['boolean'],
        ]);

        if (! empty($data['parent_id'])) {
            if ($location && (int) $data['parent_id'] === $location->id) {
                throw ValidationException::withMessages(['parent_id' => ['A location cannot be its own parent.']]);
            }

            $parent = WarehouseLocation::find($data['parent_id']);
            if (self::RANK[$data['type']] <= self::RANK[$parent->type]) {
                throw ValidationException::withMessages([
                    'parent_id' => ["A {$data['type']} cannot be placed under a {$parent->type} (order is zone > rack > shelf > bin)."],
                ]);
            }

            // Circular guard within this warehouse's tree.
            if ($location) {
                $descendants = $this->descendantIds($location->id);
                if (in_array((int) $data['parent_id'], $descendants, true)) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['A location cannot be moved under one of its own sub-locations.'],
                    ]);
                }
            }
        }

        return $data;
    }

    private function descendantIds(int $locationId): array
    {
        $all = [];
        $frontier = [$locationId];

        while ($frontier !== []) {
            $children = WarehouseLocation::whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_diff($children, $all);
            $all = array_merge($all, $children);
            $frontier = $children;
        }

        return array_map('intval', $all);
    }
}
