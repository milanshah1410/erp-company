<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\SerialNumber;
use App\Models\StockAdjustment;
use App\Services\InventoryTrackingService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly InventoryTrackingService $tracking,
    ) {}

    public function index(Request $request)
    {
        $query = StockAdjustment::query()->with(['warehouse:id,name'])->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('adjustment_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('reason')) {
            $query->where('reason', $request->query('reason'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        // Match the {data, meta} envelope produced by Resource collections.
        return response()->json([
            'data' => $page->getCollection()->map(fn ($a) => $this->payload($a)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $adjustment = DB::transaction(function () use ($request, $data) {
            $adjustment = StockAdjustment::create([
                'adjustment_number' => StockAdjustment::nextNumber(),
                'warehouse_id' => $data['warehouse_id'],
                'reason' => $data['reason'],
                'status' => 'draft',
                'adjustment_date' => $data['adjustment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($adjustment, $data['items']);

            return $adjustment;
        });

        return response()->json(['data' => $this->payload($adjustment->load('items.product', 'warehouse'))], 201);
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        return response()->json(['data' => $this->payload($stockAdjustment->load('items.product', 'warehouse'))]);
    }

    public function update(Request $request, StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
            return response()->json(['message' => 'Only draft adjustments can be edited.'], 422);
        }
        $data = $this->validated($request);

        DB::transaction(function () use ($stockAdjustment, $data) {
            $stockAdjustment->update([
                'warehouse_id' => $data['warehouse_id'],
                'reason' => $data['reason'],
                'adjustment_date' => $data['adjustment_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $stockAdjustment->items()->delete();
            $this->syncItems($stockAdjustment, $data['items']);
        });

        return response()->json(['data' => $this->payload($stockAdjustment->load('items.product', 'warehouse'))]);
    }

    public function destroy(StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
            return response()->json(['message' => 'Only draft adjustments can be deleted.'], 422);
        }
        $stockAdjustment->delete();

        return response()->json(['message' => 'Adjustment deleted.']);
    }

    /**
     * Approve: post every line to the immutable ledger. Positive quantities
     * go inward (with unit cost), negative go outward. All-or-nothing.
     */
    public function approve(Request $request, StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
            return response()->json(['message' => 'Only draft adjustments can be approved.'], 422);
        }

        $stockAdjustment->load('items.product');
        $ledgerType = StockAdjustment::LEDGER_TYPES[$stockAdjustment->reason];

        // Serial-tracked lines must name exactly as many serials as the
        // absolute quantity being moved, checked up front like a GRN.
        foreach ($stockAdjustment->items as $item) {
            if ($item->product?->track_serial) {
                $serials = $item->serials ?? [];
                if (count($serials) !== (int) abs((float) $item->quantity)) {
                    throw ValidationException::withMessages([
                        'items' => ["{$item->product->name} is serial-tracked: exactly ".abs((float) $item->quantity).' serial number(s) required.'],
                    ]);
                }
            }
        }

        try {
            DB::transaction(function () use ($stockAdjustment, $ledgerType, $request) {
                foreach ($stockAdjustment->items as $item) {
                    $qty = (float) $item->quantity;
                    if ($qty > 0) {
                        $this->stock->inward(
                            warehouseId: $stockAdjustment->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: $qty,
                            unitCost: (float) $item->unit_cost,
                            type: $ledgerType,
                            referenceType: StockAdjustment::class,
                            referenceId: $stockAdjustment->id,
                            referenceNumber: $stockAdjustment->adjustment_number,
                            userId: $request->user()?->id,
                        );

                        if ($item->product?->track_batch && $item->batch_number) {
                            $batch = Batch::firstOrCreate(
                                [
                                    'product_id' => $item->product_id,
                                    'variant_id' => $item->variant_id,
                                    'warehouse_id' => $stockAdjustment->warehouse_id,
                                    'batch_number' => $item->batch_number,
                                ],
                                ['expiry_date' => $item->expiry_date, 'unit_cost' => (float) $item->unit_cost],
                            );
                            $batch->increment('quantity', $qty);
                        }

                        if ($item->product?->track_serial) {
                            foreach ($item->serials ?? [] as $serial) {
                                SerialNumber::create([
                                    'product_id' => $item->product_id,
                                    'variant_id' => $item->variant_id,
                                    'warehouse_id' => $stockAdjustment->warehouse_id,
                                    'serial' => $serial,
                                    'status' => 'in_stock',
                                    'reference_type' => StockAdjustment::class,
                                    'reference_id' => $stockAdjustment->id,
                                ]);
                            }
                        }
                    } else {
                        $this->stock->outward(
                            warehouseId: $stockAdjustment->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: abs($qty),
                            type: $ledgerType,
                            referenceType: StockAdjustment::class,
                            referenceId: $stockAdjustment->id,
                            referenceNumber: $stockAdjustment->adjustment_number,
                            userId: $request->user()?->id,
                        );

                        if ($item->product?->track_batch) {
                            $item->update(['batch_allocations' => $this->tracking->consumeBatchFefo(
                                $item->product_id, $item->variant_id, $stockAdjustment->warehouse_id, abs($qty),
                            )]);
                        }

                        if ($item->product?->track_serial) {
                            $this->tracking->consumeSerials(
                                $item->product_id, $item->variant_id, $stockAdjustment->warehouse_id,
                                $item->serials ?? [], 'damaged', StockAdjustment::class, $stockAdjustment->id,
                            );
                        }
                    }
                }

                $stockAdjustment->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->payload($stockAdjustment->fresh()->load('items.product', 'warehouse'))]);
    }

    public function cancel(StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
            return response()->json(['message' => 'Only draft adjustments can be cancelled.'], 422);
        }
        $stockAdjustment->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->payload($stockAdjustment->load('items.product', 'warehouse'))]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'reason' => ['required', Rule::in(array_keys(StockAdjustment::LEDGER_TYPES))],
            'adjustment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.serials' => ['nullable', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
        ]);
    }

    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        foreach ($items as $item) {
            $adjustment->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'] ?? 0,
                'batch_number' => $item['batch_number'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'serials' => $item['serials'] ?? null,
            ]);
        }
    }

    private function payload(StockAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'adjustment_number' => $a->adjustment_number,
            'warehouse_id' => $a->warehouse_id,
            'warehouse' => $a->relationLoaded('warehouse') && $a->warehouse
                ? ['id' => $a->warehouse->id, 'name' => $a->warehouse->name] : null,
            'reason' => $a->reason,
            'status' => $a->status,
            'adjustment_date' => $a->adjustment_date?->toDateString(),
            'notes' => $a->notes,
            'items_count' => $a->items_count ?? ($a->relationLoaded('items') ? $a->items->count() : null),
            'items' => $a->relationLoaded('items') ? $a->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product
                    ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name] : null,
                'variant_id' => $i->variant_id,
                'quantity' => $i->quantity,
                'unit_cost' => $i->unit_cost,
            ]) : null,
            'approved_at' => $a->approved_at,
            'created_at' => $a->created_at,
        ];
    }
}
