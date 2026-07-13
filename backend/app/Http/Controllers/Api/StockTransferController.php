<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Models\User;
use App\Notifications\TransferReceivedNotification;
use App\Services\InventoryTrackingService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Warehouse → warehouse transfers with in-transit tracking.
 *
 * Lifecycle: draft → in_transit (dispatch) → received (receive).
 * Between dispatch and receipt the quantity is on the transfer document
 * only — deducted from the source, not yet added to the destination.
 */
class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly InventoryTrackingService $tracking,
    ) {}

    public function index(Request $request)
    {
        $query = StockTransfer::query()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('transfer_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return StockTransferResource::collection($query->paginate($perPage));
    }

    public function store(StockTransferRequest $request)
    {
        $data = $request->validated();

        $transfer = DB::transaction(function () use ($request, $data) {
            $transfer = StockTransfer::create([
                'transfer_number' => StockTransfer::nextNumber(),
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'status' => 'draft',
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($transfer, $data['items']);

            return $transfer;
        });

        return (new StockTransferResource($transfer->load(['fromWarehouse', 'toWarehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(StockTransfer $stockTransfer)
    {
        return new StockTransferResource(
            $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.product'])
        );
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->status !== 'draft') {
            return response()->json(['message' => 'Only draft transfers can be edited.'], 422);
        }
        $data = $request->validated();

        DB::transaction(function () use ($stockTransfer, $data) {
            $stockTransfer->update([
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $stockTransfer->items()->delete();
            $this->syncItems($stockTransfer, $data['items']);
        });

        return new StockTransferResource($stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function destroy(StockTransfer $stockTransfer)
    {
        if ($stockTransfer->status !== 'draft') {
            return response()->json(['message' => 'Only draft transfers can be deleted.'], 422);
        }
        $stockTransfer->delete();

        return response()->json(['message' => 'Transfer deleted.']);
    }

    /**
     * Dispatch: stock leaves the source warehouse (transfer_out) and the
     * transfer goes in-transit. The method-resolved issue cost is
     * snapshotted per line so the destination inherits it on receipt.
     */
    public function dispatch(Request $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->status !== 'draft') {
            return response()->json(['message' => 'Only draft transfers can be dispatched.'], 422);
        }

        // Optional per-line serial picks for serial-tracked products.
        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*.item_id' => ['required_with:items', 'integer'],
            'items.*.serials' => ['sometimes', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
        ]);
        $serialsByItem = collect($data['items'] ?? [])->keyBy('item_id');

        $stockTransfer->load('items.product');

        foreach ($stockTransfer->items as $item) {
            if ($item->product?->track_serial) {
                $serials = $serialsByItem->get($item->id)['serials'] ?? [];
                if (count($serials) !== (int) $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["{$item->product->name} is serial-tracked: exactly {$item->quantity} serial number(s) required."],
                    ]);
                }
            }
        }

        try {
            DB::transaction(function () use ($stockTransfer, $request, $serialsByItem) {
                foreach ($stockTransfer->items as $item) {
                    $ledger = $this->stock->outward(
                        warehouseId: $stockTransfer->from_warehouse_id,
                        productId: $item->product_id,
                        variantId: $item->variant_id,
                        quantity: (float) $item->quantity,
                        type: 'transfer_out',
                        referenceType: StockTransfer::class,
                        referenceId: $stockTransfer->id,
                        referenceNumber: $stockTransfer->transfer_number,
                        userId: $request->user()?->id,
                    );

                    // Snapshot the method-resolved issue cost (FIFO/LIFO layer
                    // cost or running average) so the destination inherits the
                    // same valuation on receipt.
                    $updates = ['unit_cost' => (float) $ledger->unit_cost];

                    if ($item->product?->track_batch) {
                        $updates['batch_allocations'] = $this->tracking->consumeBatchFefo(
                            $item->product_id, $item->variant_id, $stockTransfer->from_warehouse_id, (float) $item->quantity,
                        );
                    }

                    if ($item->product?->track_serial) {
                        $serials = $serialsByItem->get($item->id)['serials'] ?? [];
                        $this->tracking->consumeSerials(
                            $item->product_id, $item->variant_id, $stockTransfer->from_warehouse_id,
                            $serials, 'in_transit', StockTransfer::class, $stockTransfer->id,
                        );
                        $updates['serials'] = $serials;
                    }

                    $item->update($updates);
                }

                $stockTransfer->update([
                    'status' => 'in_transit',
                    'dispatched_by' => $request->user()?->id,
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return new StockTransferResource(
            $stockTransfer->fresh()->load(['fromWarehouse', 'toWarehouse', 'items.product'])
        );
    }

    /** Receive: stock arrives at the destination (transfer_in). */
    public function receive(Request $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->status !== 'in_transit') {
            return response()->json(['message' => 'Only in-transit transfers can be received.'], 422);
        }

        $stockTransfer->load('items.product');

        DB::transaction(function () use ($stockTransfer, $request) {
            foreach ($stockTransfer->items as $item) {
                $this->stock->inward(
                    warehouseId: $stockTransfer->to_warehouse_id,
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    quantity: (float) $item->quantity,
                    unitCost: (float) $item->unit_cost,
                    type: 'transfer_in',
                    referenceType: StockTransfer::class,
                    referenceId: $stockTransfer->id,
                    referenceNumber: $stockTransfer->transfer_number,
                    userId: $request->user()?->id,
                );

                if ($item->product?->track_batch && $item->batch_allocations) {
                    $this->tracking->receiveBatchAllocations(
                        $item->product_id, $item->variant_id, $stockTransfer->to_warehouse_id, $item->batch_allocations,
                    );
                }

                if ($item->product?->track_serial && $item->serials) {
                    $this->tracking->moveSerialsToWarehouse(
                        $item->serials, $item->product_id, $item->variant_id, $stockTransfer->to_warehouse_id, 'in_stock',
                    );
                }
            }

            $stockTransfer->update([
                'status' => 'received',
                'received_date' => now()->toDateString(),
                'received_by' => $request->user()?->id,
            ]);
        });

        $recipients = User::query()
            ->whereIn('id', array_filter([$stockTransfer->created_by, $stockTransfer->dispatched_by]))
            ->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TransferReceivedNotification($stockTransfer));
        }

        return new StockTransferResource(
            $stockTransfer->fresh()->load(['fromWarehouse', 'toWarehouse', 'items.product'])
        );
    }

    public function cancel(StockTransfer $stockTransfer)
    {
        if ($stockTransfer->status !== 'draft') {
            return response()->json(['message' => 'Only draft transfers can be cancelled (in-transit stock must be received).'], 422);
        }
        $stockTransfer->update(['status' => 'cancelled']);

        return new StockTransferResource($stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    private function syncItems(StockTransfer $transfer, array $items): void
    {
        foreach ($items as $item) {
            $transfer->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_cost' => 0, // snapshotted at dispatch
            ]);
        }
    }
}
