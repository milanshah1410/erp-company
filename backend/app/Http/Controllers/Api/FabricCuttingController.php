<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FabricCuttingRequest;
use App\Http\Resources\FabricCuttingResource;
use App\Models\FabricCutting;
use App\Models\FabricRoll;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fabric Cutting — cutting-loss + leftover-fabric management (SRS §5). A length
 * is drawn off a roll and split into productive consumption, cutting loss
 * (wastage) and a reusable leftover remnant:
 *
 *   length_used = length_consumed + cutting_loss + leftover_length
 *
 * Stock effect (only if the parent roll had posted its length): the whole
 * length_used leaves stock (fabric_cut_out), and any leftover is booked back as
 * a new leftover roll (fabric_roll_in). Net stock reduction therefore equals
 * length_consumed + cutting_loss, leaving the reusable remnant on hand.
 */
class FabricCuttingController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = FabricCutting::query()
            ->with(['fabricRoll:id,roll_number,product_id,length_unit', 'fabricRoll.product:id,name', 'warehouse:id,name', 'leftoverRoll:id,roll_number']);

        if ($search = $request->query('search')) {
            $query->where('cutting_number', 'like', "%{$search}%");
        }
        if ($request->filled('fabric_roll_id')) {
            $query->where('fabric_roll_id', $request->query('fabric_roll_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FabricCuttingResource::collection($query->paginate($perPage));
    }

    public function store(FabricCuttingRequest $request)
    {
        $data = $request->validated();

        $cutting = DB::transaction(function () use ($request, $data) {
            /** @var FabricRoll $roll */
            $roll = FabricRoll::lockForUpdate()->findOrFail($data['fabric_roll_id']);

            if (in_array($roll->status, ['quarantined', 'scrapped'], true)) {
                throw ValidationException::withMessages([
                    'fabric_roll_id' => ["Roll {$roll->roll_number} is {$roll->status} and cannot be cut."],
                ]);
            }

            $consumed = round((float) $data['length_consumed'], 3);
            $loss = round((float) ($data['cutting_loss'] ?? 0), 3);
            $leftover = round((float) ($data['leftover_length'] ?? 0), 3);
            $used = round($consumed + $loss + $leftover, 3);

            if ($used <= 0) {
                throw ValidationException::withMessages([
                    'length_consumed' => ['Enter a consumed, loss or leftover length.'],
                ]);
            }
            if ($used - (float) $roll->current_length > 0.0001) {
                throw ValidationException::withMessages([
                    'length_consumed' => ["Total used ({$used}) exceeds the roll's remaining length ({$roll->current_length})."],
                ]);
            }

            $cutting = FabricCutting::create([
                'cutting_number' => FabricCutting::nextNumber(),
                'fabric_roll_id' => $roll->id,
                'warehouse_id' => $roll->warehouse_id,
                'cut_date' => $data['cut_date'],
                'length_used' => $used,
                'length_consumed' => $consumed,
                'cutting_loss' => $loss,
                'leftover_length' => $leftover,
                'pieces_produced' => $data['pieces_produced'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Draw the whole used length off the parent roll.
            $roll->current_length = round((float) $roll->current_length - $used, 3);

            if ($roll->posted_to_stock) {
                $this->stock->outward(
                    warehouseId: $roll->warehouse_id,
                    productId: $roll->product_id,
                    variantId: $roll->variant_id,
                    quantity: $used,
                    type: 'fabric_cut_out',
                    referenceType: FabricCutting::class,
                    referenceId: $cutting->id,
                    referenceNumber: $cutting->cutting_number,
                    userId: $request->user()?->id,
                    allowNegative: true,
                );
            }

            $roll->save();
            $roll->refreshStatus();

            // Book any reusable remnant as a leftover roll.
            if ($leftover > 0) {
                $leftoverRoll = FabricRoll::create([
                    'roll_number' => FabricRoll::nextNumber(),
                    'product_id' => $roll->product_id,
                    'variant_id' => $roll->variant_id,
                    'warehouse_id' => $roll->warehouse_id,
                    'supplier_id' => $roll->supplier_id,
                    'shade_no' => $roll->shade_no,
                    'dye_lot' => $roll->dye_lot,
                    'gsm' => $roll->gsm,
                    'width' => $roll->width,
                    'width_unit' => $roll->width_unit,
                    'length_unit' => $roll->length_unit,
                    'initial_length' => $leftover,
                    'current_length' => $leftover,
                    'unit_cost' => $roll->unit_cost,
                    'grade' => $roll->grade,
                    'status' => 'available',
                    'is_leftover' => true,
                    'parent_roll_id' => $roll->id,
                    'posted_to_stock' => $roll->posted_to_stock,
                    'received_date' => $data['cut_date'],
                    'notes' => "Leftover from {$roll->roll_number} ({$cutting->cutting_number})",
                    'created_by' => $request->user()?->id,
                ]);

                if ($roll->posted_to_stock) {
                    $this->stock->inward(
                        warehouseId: $leftoverRoll->warehouse_id,
                        productId: $leftoverRoll->product_id,
                        variantId: $leftoverRoll->variant_id,
                        quantity: $leftover,
                        unitCost: (float) $leftoverRoll->unit_cost,
                        type: 'fabric_roll_in',
                        referenceType: FabricRoll::class,
                        referenceId: $leftoverRoll->id,
                        referenceNumber: $leftoverRoll->roll_number,
                        userId: $request->user()?->id,
                    );
                }

                $cutting->update(['leftover_roll_id' => $leftoverRoll->id]);
            }

            return $cutting;
        });

        return (new FabricCuttingResource($cutting->load(['fabricRoll.product', 'warehouse', 'leftoverRoll'])))
            ->response()->setStatusCode(201);
    }

    public function show(FabricCutting $fabricCutting)
    {
        return new FabricCuttingResource($fabricCutting->load(['fabricRoll.product', 'warehouse', 'leftoverRoll', 'creator']));
    }

    /**
     * Cuttings are immutable once posted (they move stock and length). Deleting
     * reverses the whole operation: restores the parent roll length, removes the
     * generated leftover roll and unwinds the stock movements.
     */
    public function destroy(FabricCutting $fabricCutting)
    {
        DB::transaction(function () use ($fabricCutting) {
            $roll = FabricRoll::lockForUpdate()->find($fabricCutting->fabric_roll_id);
            $leftoverRoll = $fabricCutting->leftover_roll_id
                ? FabricRoll::find($fabricCutting->leftover_roll_id)
                : null;

            // Unwind the leftover roll (and its intake posting) first.
            if ($leftoverRoll) {
                if ($leftoverRoll->posted_to_stock) {
                    $this->stock->outward(
                        warehouseId: $leftoverRoll->warehouse_id,
                        productId: $leftoverRoll->product_id,
                        variantId: $leftoverRoll->variant_id,
                        quantity: (float) $leftoverRoll->current_length,
                        type: 'fabric_cut_out',
                        referenceType: FabricRoll::class,
                        referenceId: $leftoverRoll->id,
                        referenceNumber: $leftoverRoll->roll_number,
                        allowNegative: true,
                    );
                }
                $leftoverRoll->delete();
            }

            if ($roll) {
                // Return the used length to the parent roll and re-post stock.
                if ($roll->posted_to_stock) {
                    $this->stock->inward(
                        warehouseId: $roll->warehouse_id,
                        productId: $roll->product_id,
                        variantId: $roll->variant_id,
                        quantity: (float) $fabricCutting->length_used,
                        unitCost: (float) $roll->unit_cost,
                        type: 'fabric_roll_in',
                        referenceType: FabricRoll::class,
                        referenceId: $roll->id,
                        referenceNumber: $roll->roll_number,
                    );
                }
                $roll->current_length = round((float) $roll->current_length + (float) $fabricCutting->length_used, 3);
                $roll->save();
                $roll->refreshStatus();
            }

            $fabricCutting->delete();
        });

        return response()->json(['message' => 'Cutting reversed and deleted.']);
    }

    /** Cutting-loss report: aggregate loss vs used per roll / product. */
    public function lossReport(Request $request)
    {
        $query = FabricCutting::query()
            ->with(['fabricRoll:id,roll_number,product_id', 'fabricRoll.product:id,name']);

        if ($request->filled('from')) {
            $query->whereDate('cut_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('cut_date', '<=', $request->query('to'));
        }

        $rows = $query->orderByDesc('cut_date')->get()->map(fn ($c) => [
            'id' => $c->id,
            'cutting_number' => $c->cutting_number,
            'cut_date' => $c->cut_date?->toDateString(),
            'roll_number' => $c->fabricRoll?->roll_number,
            'product' => $c->fabricRoll?->product?->name,
            'length_used' => $c->length_used,
            'length_consumed' => $c->length_consumed,
            'cutting_loss' => $c->cutting_loss,
            'leftover_length' => $c->leftover_length,
            'loss_pct' => $c->loss_pct,
            'pieces_produced' => $c->pieces_produced,
        ]);

        return response()->json([
            'data' => $rows,
            'totals' => [
                'length_used' => round($rows->sum(fn ($r) => (float) $r['length_used']), 3),
                'length_consumed' => round($rows->sum(fn ($r) => (float) $r['length_consumed']), 3),
                'cutting_loss' => round($rows->sum(fn ($r) => (float) $r['cutting_loss']), 3),
                'leftover_length' => round($rows->sum(fn ($r) => (float) $r['leftover_length']), 3),
            ],
        ]);
    }
}
