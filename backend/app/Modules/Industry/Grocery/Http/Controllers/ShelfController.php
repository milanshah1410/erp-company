<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WarehouseLocation;
use App\Modules\Industry\Grocery\Http\Requests\ShelfPlacementRequest;
use App\Modules\Industry\Grocery\Models\ShelfPlacement;
use App\Modules\Industry\Grocery\Services\ShelfService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The shop floor: aisles, shelves, what belongs on each, and the round that
 * fills them.
 *
 * Shelves themselves are *not* created here — they are warehouse locations and
 * are created on the Warehouses screen like every other location. What this
 * controller adds is the layer above: marking a location as a shelf, giving it
 * the shopper-facing address, and hanging a planogram off it. Two ways to create
 * a location would give a supermarket two location trees.
 */
class ShelfController extends Controller
{
    public function __construct(private readonly ShelfService $shelves) {}

    /** The floor, grouped by aisle, with gap counts rolled up. */
    public function index(Request $request)
    {
        $rows = $this->shelves->aisles(
            $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null,
        );

        return response()->json([
            'data' => $rows,
            'summary' => [
                'aisles' => count($rows),
                'shelves' => array_sum(array_map(static fn (array $a) => count($a['shelves']), $rows)),
                'lines' => array_sum(array_column($rows, 'lines')),
                'gapped_lines' => array_sum(array_column($rows, 'gapped_lines')),
                'empty_lines' => array_sum(array_column($rows, 'empty_lines')),
            ],
        ]);
    }

    /**
     * Turn a location into a shelf, or edit its address.
     *
     * A PUT on the location rather than a create, because the location already
     * exists — this only says "customers pick from this one, and here is how they
     * would describe where it is".
     */
    public function markShelf(Request $request, WarehouseLocation $location)
    {
        $data = $request->validate([
            'is_shelf' => ['required', 'boolean'],
            'aisle' => ['nullable', 'string', 'max:30'],
            'bay' => ['nullable', 'string', 'max:30'],
            'shelf_level' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        // Un-marking a shelf that still has a planogram on it would leave those
        // lines pointing at a location the replenishment round no longer walks —
        // present but invisible, which is worse than absent.
        if ($data['is_shelf'] === false && ShelfPlacement::where('location_id', $location->id)->exists()) {
            return response()->json([
                'message' => 'This shelf still has products placed on it. Clear the planogram first.',
            ], 422);
        }

        $location->update($data);

        return response()->json(['data' => $location->fresh()]);
    }

    /** The planogram for one shelf, in bay order, with what is on it. */
    public function planogram(WarehouseLocation $location)
    {
        if (! $location->is_shelf) {
            return response()->json(['message' => 'That location is not a shelf.'], 422);
        }

        return response()->json(['data' => $this->shelves->planogram($location)]);
    }

    /** Shelf-edge label run for one bay, in the order it is walked. */
    public function labels(WarehouseLocation $location)
    {
        if (! $location->is_shelf) {
            return response()->json(['message' => 'That location is not a shelf.'], 422);
        }

        return response()->json(['data' => $this->shelves->labelRun($location)]);
    }

    public function store(ShelfPlacementRequest $request)
    {
        $shelf = WarehouseLocation::findOrFail($request->integer('location_id'));

        if (! $shelf->is_shelf) {
            throw ValidationException::withMessages([
                'location_id' => ['That location is not marked as a shelf.'],
            ]);
        }

        $placement = ShelfPlacement::create($request->validated());

        return response()->json(['data' => $placement->fresh(['product', 'variant'])], 201);
    }

    public function update(ShelfPlacementRequest $request, ShelfPlacement $shelfPlacement)
    {
        $shelfPlacement->update($request->validated());

        return response()->json(['data' => $shelfPlacement->fresh(['product', 'variant'])]);
    }

    public function destroy(ShelfPlacement $shelfPlacement)
    {
        $shelfPlacement->delete();

        return response()->json(['message' => 'Placement removed.']);
    }

    /**
     * The replenishment round: everything below its minimum, worst first.
     *
     * Each line carries the quantity to bring and where to pick it from, so the
     * round is walkable straight off the screen without a second lookup per item.
     */
    public function gaps(Request $request)
    {
        $rows = $this->shelves->gaps(
            $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null,
            $request->filled('shelf_id') ? $request->integer('shelf_id') : null,
        );

        return response()->json([
            'data' => $rows,
            'summary' => [
                'lines' => count($rows),
                'empty' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'empty')),
                'low' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'low')),
                'units_to_pick' => round(array_sum(array_column($rows, 'suggested_qty')), 3),
            ],
        ]);
    }

    /**
     * Fill a facing from the backroom.
     *
     * On `stock.edit` rather than a shelf permission: this moves stock, and the
     * authority to move stock is the authority to move stock wherever the screen
     * that does it happens to live.
     */
    public function replenish(Request $request, ShelfPlacement $shelfPlacement)
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'from_location_id' => ['nullable', 'exists:warehouse_locations,id'],
        ]);

        try {
            $result = $this->shelves->replenish(
                $shelfPlacement,
                (float) $data['quantity'],
                isset($data['from_location_id']) ? (int) $data['from_location_id'] : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['quantity' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Shelf filled.',
            'data' => $result,
        ]);
    }

    /**
     * The date-check round: what is on the floor and about to go out of date.
     *
     * Batch and expiry are core Inventory and are not re-implemented — this only
     * narrows them to the shelf, because a date check is walked down an aisle
     * rather than read off a stock report.
     */
    public function expiring(Request $request)
    {
        $data = $request->validate([
            'within_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
        ]);

        $rows = $this->shelves->expiring(
            (int) ($data['within_days'] ?? 14),
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        );

        return response()->json([
            'data' => $rows,
            'summary' => [
                'lines' => count($rows),
                'expired' => count(array_filter(
                    $rows,
                    static fn (array $r) => $r['days_to_expiry'] !== null && $r['days_to_expiry'] < 0,
                )),
            ],
        ]);
    }
}
