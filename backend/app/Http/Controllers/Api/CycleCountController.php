<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Notifications\CycleCountDiscrepancyNotification;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CycleCountController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = CycleCount::query()->with(['warehouse:id,name'])->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('count_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        // Match the {data, meta} envelope produced by Resource collections.
        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->payload($c)),
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

        $cycleCount = DB::transaction(function () use ($request, $data) {
            $cycleCount = CycleCount::create([
                'count_number' => CycleCount::nextNumber(),
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'count_date' => $data['count_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($cycleCount, $data['items']);

            return $cycleCount;
        });

        return response()->json(['data' => $this->payload($cycleCount->load('items.product', 'warehouse'))], 201);
    }

    public function show(CycleCount $cycleCount)
    {
        return response()->json(['data' => $this->payload($cycleCount->load('items.product', 'warehouse'))]);
    }

    public function update(Request $request, CycleCount $cycleCount)
    {
        if ($cycleCount->status !== 'draft') {
            return response()->json(['message' => 'Only draft cycle counts can be edited.'], 422);
        }
        $data = $this->validated($request);

        DB::transaction(function () use ($cycleCount, $data) {
            $cycleCount->update([
                'warehouse_id' => $data['warehouse_id'],
                'count_date' => $data['count_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $cycleCount->items()->delete();
            $this->syncItems($cycleCount, $data['items']);
        });

        return response()->json(['data' => $this->payload($cycleCount->load('items.product', 'warehouse'))]);
    }

    public function destroy(CycleCount $cycleCount)
    {
        if ($cycleCount->status !== 'draft') {
            return response()->json(['message' => 'Only draft cycle counts can be deleted.'], 422);
        }
        $cycleCount->delete();

        return response()->json(['message' => 'Cycle count deleted.']);
    }

    /**
     * Complete: finalize the physical verification. Every line's book quantity
     * is re-read from live stock (under the ledger's row lock, so movements
     * made while counting are accounted for), the variance is stored, and any
     * non-zero variance is posted to the immutable ledger so on-hand equals
     * the counted quantity. All-or-nothing.
     */
    public function complete(Request $request, CycleCount $cycleCount)
    {
        if ($cycleCount->status !== 'draft') {
            return response()->json(['message' => 'Only draft cycle counts can be completed.'], 422);
        }

        $cycleCount->load('items.product');

        $uncounted = $cycleCount->items->whereNull('counted_quantity')->count();
        if ($uncounted > 0) {
            throw ValidationException::withMessages([
                'items' => ["{$uncounted} item(s) have no counted quantity. Count every item before completing."],
            ]);
        }

        try {
            DB::transaction(function () use ($cycleCount, $request) {
                foreach ($cycleCount->items as $item) {
                    // Lock the aggregate row so the book figure can't move
                    // between reading it and posting the variance.
                    $stock = WarehouseStock::query()
                        ->where('warehouse_id', $cycleCount->warehouse_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant_id', $item->variant_id)
                        ->lockForUpdate()
                        ->first();

                    $systemQty = (float) ($stock?->quantity ?? 0);
                    $countedQty = (float) $item->counted_quantity;
                    $variance = round($countedQty - $systemQty, 3);
                    $unitCost = (float) ($stock?->average_cost ?: $item->product?->cost_price ?? 0);

                    $item->update([
                        'system_quantity' => $systemQty,
                        'variance' => $variance,
                        'unit_cost' => $unitCost,
                    ]);

                    if ($variance > 0) {
                        $this->stock->inward(
                            warehouseId: $cycleCount->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: $variance,
                            unitCost: $unitCost,
                            type: 'adjustment',
                            referenceType: CycleCount::class,
                            referenceId: $cycleCount->id,
                            referenceNumber: $cycleCount->count_number,
                            userId: $request->user()?->id,
                        );
                    } elseif ($variance < 0) {
                        $this->stock->outward(
                            warehouseId: $cycleCount->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: abs($variance),
                            type: 'adjustment',
                            referenceType: CycleCount::class,
                            referenceId: $cycleCount->id,
                            referenceNumber: $cycleCount->count_number,
                            userId: $request->user()?->id,
                        );
                    }
                }

                $cycleCount->update([
                    'status' => 'completed',
                    'completed_by' => $request->user()?->id,
                    'completed_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $itemsWithVariance = $cycleCount->items->filter(fn ($i) => $i->variance !== null && (float) $i->variance != 0.0)->count();
        if ($itemsWithVariance > 0) {
            $varianceValue = round($cycleCount->items->sum(fn ($i) => (float) $i->variance * (float) $i->unit_cost), 2);
            $recipients = User::role(['admin', 'super-admin', 'warehouse-manager'])->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new CycleCountDiscrepancyNotification($cycleCount, $itemsWithVariance, $varianceValue));
            }
        }

        return response()->json(['data' => $this->payload($cycleCount->fresh()->load('items.product', 'warehouse'))]);
    }

    public function cancel(CycleCount $cycleCount)
    {
        if ($cycleCount->status !== 'draft') {
            return response()->json(['message' => 'Only draft cycle counts can be cancelled.'], 422);
        }
        $cycleCount->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->payload($cycleCount->load('items.product', 'warehouse'))]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $keys = collect($data['items'])->map(fn ($i) => $i['product_id'].':'.($i['variant_id'] ?? ''));
        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['The count sheet lists the same product more than once.'],
            ]);
        }

        return $data;
    }

    private function syncItems(CycleCount $cycleCount, array $items): void
    {
        foreach ($items as $item) {
            // Snapshot the book quantity for the count sheet; it is refreshed
            // from live stock at completion before the variance is posted.
            $systemQty = WarehouseStock::query()
                ->where('warehouse_id', $cycleCount->warehouse_id)
                ->where('product_id', $item['product_id'])
                ->where('variant_id', $item['variant_id'] ?? null)
                ->value('quantity') ?? 0;

            $cycleCount->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'system_quantity' => $systemQty,
                'counted_quantity' => $item['counted_quantity'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function payload(CycleCount $c): array
    {
        $items = $c->relationLoaded('items') ? $c->items : null;

        return [
            'id' => $c->id,
            'count_number' => $c->count_number,
            'warehouse_id' => $c->warehouse_id,
            'warehouse' => $c->relationLoaded('warehouse') && $c->warehouse
                ? ['id' => $c->warehouse->id, 'name' => $c->warehouse->name] : null,
            'status' => $c->status,
            'count_date' => $c->count_date?->toDateString(),
            'notes' => $c->notes,
            'items_count' => $c->items_count ?? $items?->count(),
            'items' => $items?->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product
                    ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name] : null,
                'variant_id' => $i->variant_id,
                'system_quantity' => $i->system_quantity,
                'counted_quantity' => $i->counted_quantity,
                'variance' => $i->variance,
                'unit_cost' => $i->unit_cost,
                'variance_value' => $i->variance !== null
                    ? round((float) $i->variance * (float) $i->unit_cost, 2) : null,
                'notes' => $i->notes,
            ]),
            // Variance report summary (meaningful once completed).
            'summary' => $items !== null ? [
                'total_items' => $items->count(),
                'counted_items' => $items->whereNotNull('counted_quantity')->count(),
                'items_with_variance' => $items->filter(fn ($i) => (float) $i->variance != 0.0 && $i->variance !== null)->count(),
                'variance_quantity' => round($items->sum(fn ($i) => (float) $i->variance), 3),
                'variance_value' => round($items->sum(fn ($i) => (float) $i->variance * (float) $i->unit_cost), 2),
            ] : null,
            'completed_at' => $c->completed_at,
            'created_at' => $c->created_at,
        ];
    }
}
