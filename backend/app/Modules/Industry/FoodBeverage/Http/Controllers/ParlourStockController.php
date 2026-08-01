<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WarehouseLocation;
use App\Modules\Industry\FoodBeverage\Services\ParlourStockService;
use Illuminate\Http\Request;

/**
 * An ice-cream parlour's freezers, and the flavours in them.
 *
 * Read-only apart from one thing: marking a location as a freezer and giving it a
 * target temperature. Moving stock between freezers is a stock transfer and stays
 * in Inventory — a second way to move stock that reconciled differently would be
 * worse than a click more work.
 */
class ParlourStockController extends Controller
{
    public function __construct(private readonly ParlourStockService $parlour) {}

    /** The freezers, with what is in each. */
    public function freezers()
    {
        return response()->json(['data' => $this->parlour->freezers()]);
    }

    /**
     * Flavour-wise stock, soonest to expire first.
     *
     * Optionally scoped to one freezer, which is what the freezer cards on the
     * screen drill into.
     */
    public function flavours(Request $request)
    {
        $data = $request->validate([
            'freezer_id' => ['nullable', 'exists:warehouse_locations,id'],
            'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $rows = isset($data['expiring_within_days'])
            ? $this->parlour->expiringSoon((int) $data['expiring_within_days'])
            : $this->parlour->flavourStock(
                isset($data['freezer_id']) ? (int) $data['freezer_id'] : null,
            );

        return response()->json([
            'data' => $rows,
            'summary' => [
                'lines' => count($rows),
                'quantity' => round(array_sum(array_column($rows, 'quantity')), 3),
                'flavours' => count(array_unique(array_map(
                    fn (array $r) => $r['product_id'].':'.($r['variant_id'] ?? 0),
                    $rows,
                ))),
                'expiring_14d' => count(array_filter(
                    $rows,
                    fn (array $r) => $r['days_to_expiry'] !== null && $r['days_to_expiry'] <= 14,
                )),
                'expired' => count(array_filter(
                    $rows,
                    fn (array $r) => $r['days_to_expiry'] !== null && $r['days_to_expiry'] < 0,
                )),
            ],
        ]);
    }

    /**
     * Turn a warehouse location into a freezer, or set its target temperature.
     *
     * The location itself is created and named in Inventory → Warehouse Locations.
     * This only says "that rack is a freezer, and it should be holding −18°C",
     * because those are the two facts a parlour knows and an inventory screen has
     * no reason to ask for.
     */
    public function setFreezer(Request $request, WarehouseLocation $warehouseLocation)
    {
        $data = $request->validate([
            'is_freezer' => ['required', 'boolean'],
            // Negative for a freezer, and nullable so a location that stops being
            // one does not keep a target nobody is holding it to.
            'target_temp_c' => ['nullable', 'numeric', 'min:-60', 'max:30'],
        ]);

        $warehouseLocation->update([
            'is_freezer' => $data['is_freezer'],
            'target_temp_c' => $data['is_freezer'] ? ($data['target_temp_c'] ?? null) : null,
        ]);

        return response()->json([
            'data' => [
                'id' => $warehouseLocation->id,
                'code' => $warehouseLocation->code,
                'name' => $warehouseLocation->name,
                'is_freezer' => (bool) $warehouseLocation->is_freezer,
                'target_temp_c' => $warehouseLocation->target_temp_c === null
                    ? null
                    : (float) $warehouseLocation->target_temp_c,
            ],
        ]);
    }

    /**
     * Locations that could be marked as freezers — the picker behind the button
     * above.
     */
    public function locations(Request $request)
    {
        $locations = WarehouseLocation::query()
            ->where('is_active', true)
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->query('warehouse_id')))
            ->with('warehouse:id,name')
            ->orderBy('warehouse_id')
            ->orderBy('code')
            ->get(['id', 'warehouse_id', 'code', 'name', 'type', 'is_freezer', 'target_temp_c']);

        return response()->json([
            'data' => $locations->map(fn (WarehouseLocation $l) => [
                'id' => $l->id,
                'warehouse' => $l->warehouse?->name,
                'code' => $l->code,
                'name' => $l->name,
                'type' => $l->type,
                'is_freezer' => (bool) $l->is_freezer,
                'target_temp_c' => $l->target_temp_c === null ? null : (float) $l->target_temp_c,
            ])->values(),
        ]);
    }
}
