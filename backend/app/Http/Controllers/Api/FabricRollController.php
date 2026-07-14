<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FabricRollRequest;
use App\Http\Resources\FabricRollResource;
use App\Models\FabricRoll;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fabric Roll ("than") management — SRS §5. A roll is a physical unit of a
 * fabric product carrying textile attributes (GSM, width, shade, dye lot) and a
 * running remaining length. Receiving a roll can optionally post its length
 * into inventory (fabric_roll_in) so roll length and product stock stay aligned.
 */
class FabricRollController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = FabricRoll::query()
            ->with(['product:id,sku,name', 'variant:id,name', 'warehouse:id,name', 'supplier:id,name'])
            ->withCount(['inspections', 'cuttings']);

        // Leftover rolls are hidden from the main list unless explicitly asked for.
        if ($request->boolean('leftover')) {
            $query->where('is_leftover', true);
        } elseif (! $request->boolean('all')) {
            $query->where('is_leftover', false);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('roll_number', 'like', "%{$search}%")
                ->orWhere('shade_no', 'like', "%{$search}%")
                ->orWhere('dye_lot', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('dye_lot')) {
            $query->where('dye_lot', $request->query('dye_lot'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FabricRollResource::collection($query->paginate($perPage));
    }

    public function store(FabricRollRequest $request)
    {
        $data = $request->validated();
        $postToStock = $request->boolean('post_to_stock', true);

        $roll = DB::transaction(function () use ($request, $data, $postToStock) {
            $roll = FabricRoll::create([
                'roll_number' => FabricRoll::nextNumber(),
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'shade_no' => $data['shade_no'] ?? null,
                'dye_lot' => $data['dye_lot'] ?? null,
                'gsm' => $data['gsm'] ?? null,
                'width' => $data['width'] ?? null,
                'width_unit' => $data['width_unit'] ?? 'inch',
                'length_unit' => $data['length_unit'] ?? 'meter',
                'initial_length' => $data['initial_length'],
                'current_length' => $data['initial_length'],
                'unit_cost' => $data['unit_cost'] ?? 0,
                'status' => 'available',
                'posted_to_stock' => false,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            if ($postToStock) {
                $this->stock->inward(
                    warehouseId: $roll->warehouse_id,
                    productId: $roll->product_id,
                    variantId: $roll->variant_id,
                    quantity: (float) $roll->initial_length,
                    unitCost: (float) $roll->unit_cost,
                    type: 'fabric_roll_in',
                    referenceType: FabricRoll::class,
                    referenceId: $roll->id,
                    referenceNumber: $roll->roll_number,
                    userId: $request->user()?->id,
                );
                $roll->update(['posted_to_stock' => true]);
            }

            return $roll;
        });

        return (new FabricRollResource($roll->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(FabricRoll $fabricRoll)
    {
        $fabricRoll->load([
            ...$this->relations(),
            'inspections' => fn ($q) => $q->orderByDesc('id'),
            'cuttings' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return new FabricRollResource($fabricRoll);
    }

    /**
     * Edit a roll's descriptive attributes. Length and stock posting are locked
     * once any cutting has occurred so the ledger stays consistent — attribute
     * corrections (shade, dye lot, notes) remain allowed at all times.
     */
    public function update(FabricRollRequest $request, FabricRoll $fabricRoll)
    {
        $data = $request->validated();
        $lengthLocked = $fabricRoll->cuttings()->exists() || $fabricRoll->posted_to_stock;

        DB::transaction(function () use ($fabricRoll, $data, $lengthLocked) {
            $payload = [
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'shade_no' => $data['shade_no'] ?? null,
                'dye_lot' => $data['dye_lot'] ?? null,
                'gsm' => $data['gsm'] ?? null,
                'width' => $data['width'] ?? null,
                'width_unit' => $data['width_unit'] ?? 'inch',
                'length_unit' => $data['length_unit'] ?? 'meter',
                'unit_cost' => $data['unit_cost'] ?? 0,
                'received_date' => $data['received_date'] ?? $fabricRoll->received_date?->toDateString(),
                'notes' => $data['notes'] ?? null,
            ];

            // Only re-baseline the length when it has never been posted or cut.
            if (! $lengthLocked) {
                $payload['initial_length'] = $data['initial_length'];
                $payload['current_length'] = $data['initial_length'];
            }

            $fabricRoll->update($payload);
            $fabricRoll->refreshStatus();
        });

        return new FabricRollResource($fabricRoll->load($this->relations()));
    }

    public function destroy(FabricRoll $fabricRoll)
    {
        if ($fabricRoll->cuttings()->exists()) {
            return response()->json(['message' => 'Cannot delete a roll that has cutting history.'], 422);
        }

        DB::transaction(function () use ($fabricRoll) {
            // Reverse the intake posting if the roll had added length to stock.
            if ($fabricRoll->posted_to_stock) {
                $this->stock->outward(
                    warehouseId: $fabricRoll->warehouse_id,
                    productId: $fabricRoll->product_id,
                    variantId: $fabricRoll->variant_id,
                    quantity: (float) $fabricRoll->current_length,
                    type: 'fabric_cut_out',
                    referenceType: FabricRoll::class,
                    referenceId: $fabricRoll->id,
                    referenceNumber: $fabricRoll->roll_number,
                    allowNegative: true,
                );
            }
            $fabricRoll->delete();
        });

        return response()->json(['message' => 'Fabric roll deleted.']);
    }

    /** Change roll status (quarantine failed fabric, scrap it, or reinstate). */
    public function setStatus(Request $request, FabricRoll $fabricRoll)
    {
        $data = $request->validate([
            'status' => ['required', 'in:available,partial,consumed,quarantined,scrapped'],
        ]);

        // Reinstating to a length-driven state hands control back to the roll.
        if (in_array($data['status'], ['available', 'partial', 'consumed'], true)) {
            $fabricRoll->status = 'available';
            $fabricRoll->save();
            $fabricRoll->refreshStatus();
        } else {
            $fabricRoll->update(['status' => $data['status']]);
        }

        return new FabricRollResource($fabricRoll->load($this->relations()));
    }

    /** Stock summary of live rolls grouped by fabric product / dye lot. */
    public function stockSummary(Request $request)
    {
        $rows = FabricRoll::query()
            ->with('product:id,sku,name')
            ->whereNotIn('status', ['scrapped'])
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'product_id' => $first->product_id,
                    'product' => $first->product ? ['sku' => $first->product->sku, 'name' => $first->product->name] : null,
                    'roll_count' => $group->count(),
                    'total_length' => round($group->sum(fn ($r) => (float) $r->current_length), 3),
                    'available_rolls' => $group->where('status', 'available')->count(),
                    'partial_rolls' => $group->where('status', 'partial')->count(),
                    'quarantined_rolls' => $group->where('status', 'quarantined')->count(),
                    'leftover_length' => round($group->where('is_leftover', true)->sum(fn ($r) => (float) $r->current_length), 3),
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    private function relations(): array
    {
        return ['product', 'variant', 'warehouse', 'supplier', 'parentRoll', 'creator'];
    }
}
