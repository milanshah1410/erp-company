<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManufacturingOrderRequest;
use App\Http\Resources\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderStage;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Manufacturing orders: produce a finished product from consumed raw
 * materials, tracked through a chain of production stages.
 *
 * Lifecycle: draft -> in_progress -> completed (or cancelled). Raw materials
 * are drawn from stock on start(); the finished good is received into stock
 * on complete(). Editing is confined to drafts.
 */
class ManufacturingOrderController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = ManufacturingOrder::query()->with(['product', 'warehouse']);

        if ($search = $request->query('search')) {
            $query->where('mo_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ManufacturingOrderResource::collection($query->paginate($perPage));
    }

    public function store(ManufacturingOrderRequest $request)
    {
        $order = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $order = ManufacturingOrder::create([
                'mo_number' => ManufacturingOrder::nextNumber(),
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'quantity_to_produce' => $data['quantity_to_produce'],
                'status' => 'draft',
                'order_date' => $data['order_date'],
                'expected_completion_date' => $data['expected_completion_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncMaterials($order, $data['materials'] ?? []);
            $this->seedStages($order, $data['stages']);
            $order->recalcCosts();

            return $order;
        });

        return (new ManufacturingOrderResource($order->load(['product', 'warehouse', 'materials.product', 'stages.employee'])))
            ->response()->setStatusCode(201);
    }

    public function show(ManufacturingOrder $manufacturingOrder)
    {
        return new ManufacturingOrderResource(
            $manufacturingOrder->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    public function update(ManufacturingOrderRequest $request, ManufacturingOrder $manufacturingOrder)
    {
        if ($manufacturingOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $manufacturingOrder) {
            $data = $request->validated();
            $manufacturingOrder->update([
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'quantity_to_produce' => $data['quantity_to_produce'],
                'order_date' => $data['order_date'],
                'expected_completion_date' => $data['expected_completion_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $manufacturingOrder->materials()->delete();
            $this->syncMaterials($manufacturingOrder, $data['materials'] ?? []);
            $this->syncStages($manufacturingOrder, $data['stages']);
            $manufacturingOrder->recalcCosts();
        });

        return new ManufacturingOrderResource(
            $manufacturingOrder->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    public function destroy(ManufacturingOrder $manufacturingOrder)
    {
        if ($manufacturingOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted.'], 422);
        }
        $manufacturingOrder->delete();

        return response()->json(['message' => 'Manufacturing order deleted.']);
    }

    /**
     * Start production: draft -> in_progress. Consumes each raw-material line
     * out of the warehouse. Refuses if any line would drive stock negative.
     */
    public function start(Request $request, ManufacturingOrder $manufacturingOrder)
    {
        if ($manufacturingOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be started.'], 422);
        }

        $manufacturingOrder->load('materials');

        try {
            DB::transaction(function () use ($manufacturingOrder, $request) {
                foreach ($manufacturingOrder->materials as $material) {
                    $this->stock->outward(
                        warehouseId: $manufacturingOrder->warehouse_id,
                        productId: $material->product_id,
                        variantId: $material->variant_id,
                        quantity: (float) $material->quantity,
                        type: 'consumption',
                        referenceType: ManufacturingOrder::class,
                        referenceId: $manufacturingOrder->id,
                        referenceNumber: $manufacturingOrder->mo_number,
                        userId: $request->user()?->id,
                    );
                    $material->update(['consumed_quantity' => (float) $material->quantity]);
                }

                $manufacturingOrder->update([
                    'status' => 'in_progress',
                    'materials_consumed' => true,
                    'started_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            // StockService refuses to drive stock negative.
            throw ValidationException::withMessages(['materials' => [$e->getMessage()]]);
        }

        return new ManufacturingOrderResource(
            $manufacturingOrder->fresh()->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    /**
     * Complete production: in_progress -> completed. Receives the produced
     * quantity of the finished good into stock at its computed unit cost
     * (total order cost / quantity produced).
     */
    public function complete(Request $request, ManufacturingOrder $manufacturingOrder)
    {
        if ($manufacturingOrder->status !== 'in_progress') {
            return response()->json(['message' => 'Only orders in progress can be completed.'], 422);
        }

        $data = $request->validate([
            'quantity_produced' => ['nullable', 'numeric', 'min:0.001'],
        ]);
        $producedQty = (float) ($data['quantity_produced'] ?? $manufacturingOrder->quantity_to_produce);

        DB::transaction(function () use ($manufacturingOrder, $producedQty, $request) {
            // Stamp produced qty first so the roll-up and unit cost reflect it.
            $manufacturingOrder->update(['quantity_produced' => $producedQty]);
            $manufacturingOrder->recalcCosts();
            $manufacturingOrder->refresh();

            $this->stock->inward(
                warehouseId: $manufacturingOrder->warehouse_id,
                productId: $manufacturingOrder->product_id,
                variantId: $manufacturingOrder->variant_id,
                quantity: $producedQty,
                unitCost: $manufacturingOrder->unitProductionCost(),
                type: 'production',
                referenceType: ManufacturingOrder::class,
                referenceId: $manufacturingOrder->id,
                referenceNumber: $manufacturingOrder->mo_number,
                userId: $request->user()?->id,
            );

            $manufacturingOrder->update([
                'status' => 'completed',
                'finished_received' => true,
                'completed_at' => now(),
            ]);
        });

        return new ManufacturingOrderResource(
            $manufacturingOrder->fresh()->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    /**
     * Cancel an order. Drafts cancel freely; an in-progress order returns any
     * already-consumed materials to stock so the ledger stays balanced. A
     * completed order cannot be cancelled.
     */
    public function cancel(Request $request, ManufacturingOrder $manufacturingOrder)
    {
        if (! in_array($manufacturingOrder->status, ['draft', 'in_progress'], true)) {
            return response()->json(['message' => 'Completed or cancelled orders cannot be cancelled.'], 422);
        }

        DB::transaction(function () use ($manufacturingOrder, $request) {
            if ($manufacturingOrder->materials_consumed) {
                $manufacturingOrder->load('materials');
                foreach ($manufacturingOrder->materials as $material) {
                    $qty = (float) $material->consumed_quantity;
                    if ($qty > 0) {
                        $this->stock->inward(
                            warehouseId: $manufacturingOrder->warehouse_id,
                            productId: $material->product_id,
                            variantId: $material->variant_id,
                            quantity: $qty,
                            unitCost: (float) $material->unit_cost,
                            type: 'adjustment',
                            referenceType: ManufacturingOrder::class,
                            referenceId: $manufacturingOrder->id,
                            referenceNumber: $manufacturingOrder->mo_number,
                            userId: $request->user()?->id,
                        );
                        $material->update(['consumed_quantity' => 0]);
                    }
                }
            }

            $manufacturingOrder->update(['status' => 'cancelled', 'materials_consumed' => false]);
        });

        return new ManufacturingOrderResource(
            $manufacturingOrder->fresh()->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    /** Update a single production stage (assignment, dates, status, cost, remarks). */
    public function updateStage(Request $request, ManufacturingOrder $manufacturingOrder, ManufacturingOrderStage $stage)
    {
        $this->assertStageBelongs($manufacturingOrder, $stage);

        if ($manufacturingOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cannot update stages of a cancelled order.'], 422);
        }

        $data = $request->validate([
            'assigned_employee_id' => ['nullable', 'exists:employees,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'skipped'])],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $stage->update([
            'assigned_employee_id' => $data['assigned_employee_id'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => $data['status'],
            'cost' => $data['cost'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $manufacturingOrder->recalcCosts();

        return new ManufacturingOrderResource(
            $manufacturingOrder->fresh()->load(['product', 'warehouse', 'materials.product', 'stages.employee'])
        );
    }

    /** Upload (or replace) a stage's reference image. Stored on the public disk. */
    public function uploadStageImage(Request $request, ManufacturingOrder $manufacturingOrder, ManufacturingOrderStage $stage)
    {
        $this->assertStageBelongs($manufacturingOrder, $stage);

        $request->validate([
            'image' => ['required', 'image', 'max:2048'], // 2 MB
        ]);

        if ($stage->image && Storage::disk('public')->exists($stage->image)) {
            Storage::disk('public')->delete($stage->image);
        }

        $path = $request->file('image')->store('manufacturing-stages', 'public');
        $stage->update(['image' => $path]);

        return new ManufacturingOrderStageResource($stage->fresh()->load('employee'));
    }

    private function assertStageBelongs(ManufacturingOrder $order, ManufacturingOrderStage $stage): void
    {
        if ($stage->manufacturing_order_id !== $order->id) {
            abort(404);
        }
    }

    /** Create material lines; defaults unit cost to the product's cost price. */
    private function syncMaterials(ManufacturingOrder $order, array $materials): void
    {
        foreach ($materials as $line) {
            $unitCost = isset($line['unit_cost'])
                ? (float) $line['unit_cost']
                : (float) (Product::find($line['product_id'])?->cost_price ?? 0);
            $quantity = (float) $line['quantity'];

            $order->materials()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 2),
                'remarks' => $line['remarks'] ?? null,
            ]);
        }
    }

    /** Seed the chosen stages in pipeline order, each pending. */
    private function seedStages(ManufacturingOrder $order, array $stageKeys): void
    {
        $sequence = 1;
        foreach (ManufacturingOrder::STAGES as $key => $name) {
            if (in_array($key, $stageKeys, true)) {
                $order->stages()->create([
                    'stage_key' => $key,
                    'name' => $name,
                    'sequence' => $sequence++,
                    'status' => 'pending',
                ]);
            }
        }
    }

    /**
     * Reconcile the order's stage set with the chosen keys, preserving any
     * progress already captured on stages that remain selected.
     */
    private function syncStages(ManufacturingOrder $order, array $stageKeys): void
    {
        $order->load('stages');
        $existing = $order->stages->keyBy('stage_key');

        // Drop stages no longer selected.
        $order->stages()->whereNotIn('stage_key', $stageKeys)->delete();

        $sequence = 1;
        foreach (ManufacturingOrder::STAGES as $key => $name) {
            if (! in_array($key, $stageKeys, true)) {
                continue;
            }
            if ($stage = $existing->get($key)) {
                $stage->update(['sequence' => $sequence++]);
            } else {
                $order->stages()->create([
                    'stage_key' => $key,
                    'name' => $name,
                    'sequence' => $sequence++,
                    'status' => 'pending',
                ]);
            }
        }
    }
}
