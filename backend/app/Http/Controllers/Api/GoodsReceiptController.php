<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\SerialNumber;
use App\Models\StockLocation;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = GoodsReceipt::query()->with(['purchaseOrder', 'warehouse']);

        if ($search = $request->query('search')) {
            $query->where('grn_number', 'like', "%{$search}%");
        }
        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->query('purchase_order_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return GoodsReceiptResource::collection($query->paginate($perPage));
    }

    public function store(GoodsReceiptRequest $request)
    {
        $data = $request->validated();

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::with('items.product')->findOrFail($data['purchase_order_id']);

        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['Goods can only be received against an approved purchase order.'],
            ]);
        }

        // Validate each receipt line against its PO line before touching stock.
        $lines = [];
        foreach ($data['items'] as $line) {
            /** @var PurchaseItem|null $poItem */
            $poItem = $po->items->firstWhere('id', (int) $line['purchase_item_id']);
            if (! $poItem) {
                throw ValidationException::withMessages([
                    'items' => ["Purchase item {$line['purchase_item_id']} does not belong to this order."],
                ]);
            }
            $qty = (float) $line['quantity'];
            if ($qty > $poItem->outstandingQuantity() + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => ["Received quantity for {$poItem->product_id} exceeds the outstanding {$poItem->outstandingQuantity()}."],
                ]);
            }

            // Enforce tracking requirements declared on the product master.
            $product = $poItem->product;
            $serials = array_values(array_unique(array_map('trim', $line['serials'] ?? [])));
            if ($product?->track_batch && blank($line['batch_number'] ?? null)) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} is batch-tracked: a batch number is required."],
                ]);
            }
            if ($product?->track_expiry && ! blank($line['batch_number'] ?? null) && blank($line['expiry_date'] ?? null)) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} is expiry-tracked: an expiry date is required."],
                ]);
            }
            if ($product?->track_serial) {
                if (count($serials) !== (int) $qty) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} is serial-tracked: exactly {$qty} unique serial number(s) required, got ".count($serials).'.'],
                    ]);
                }
                $dupes = SerialNumber::where('product_id', $product->id)->whereIn('serial', $serials)->pluck('serial');
                if ($dupes->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => ['Serial number(s) already registered: '.$dupes->implode(', ').'.'],
                    ]);
                }
            }

            // Put-away target must be an active location of the receiving warehouse.
            $locationId = $line['location_id'] ?? null;
            if ($locationId) {
                $loc = WarehouseLocation::find($locationId);
                if (! $loc || $loc->warehouse_id !== $po->warehouse_id || ! $loc->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ['The put-away location does not belong to the receiving warehouse (or is inactive).'],
                    ]);
                }
            }

            $lines[] = [$poItem, $qty, $line['batch_number'] ?? null, $line['expiry_date'] ?? null, $serials, $locationId];
        }

        // Landed cost: apportion freight/duty/other charges across this receipt's lines,
        // inflating the unit cost that actually gets posted to stock.
        $charges = $data['landed_cost'] ?? [];
        $landedTotal = (float) ($charges['freight_amount'] ?? 0)
            + (float) ($charges['duty_amount'] ?? 0)
            + (float) ($charges['other_amount'] ?? 0);
        $allocationMethod = $charges['allocation_method'] ?? 'value';

        $perUnitCharge = [];
        if ($landedTotal > 0) {
            $weights = array_map(
                fn ($line) => $allocationMethod === 'quantity'
                    ? $line[1]
                    : $line[1] * (float) $line[0]->unit_cost,
                $lines,
            );
            $totalWeight = array_sum($weights);

            foreach ($lines as $i => $line) {
                $qty = $line[1];
                $allocated = $totalWeight > 0 ? $landedTotal * ($weights[$i] / $totalWeight) : 0;
                $perUnitCharge[$i] = $qty > 0 ? $allocated / $qty : 0;
            }
        }

        $grn = DB::transaction(function () use ($po, $data, $lines, $request, $perUnitCharge, $landedTotal, $charges) {
            $grn = GoodsReceipt::create([
                'grn_number' => GoodsReceipt::nextNumber(),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->warehouse_id,
                'supplier_id' => $po->supplier_id,
                'received_date' => $data['received_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($lines as $i => [$poItem, $qty, $batchNumber, $expiryDate, $serials, $locationId]) {
                $landedPerUnit = $perUnitCharge[$i] ?? 0;
                $unitCost = (float) $poItem->unit_cost + $landedPerUnit;

                $grn->items()->create([
                    'purchase_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'variant_id' => $poItem->variant_id,
                    'location_id' => $locationId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $poItem->unit_cost,
                    'landed_cost_per_unit' => $landedPerUnit,
                ]);

                // Put-away: accumulate quantity at the chosen sub-location.
                if ($locationId) {
                    $stockLoc = StockLocation::firstOrCreate(
                        [
                            'location_id' => $locationId,
                            'product_id' => $poItem->product_id,
                            'variant_id' => $poItem->variant_id,
                        ],
                        ['quantity' => 0],
                    );
                    $stockLoc->increment('quantity', $qty);
                }

                // Batch/expiry tracking: accumulate quantity into the batch.
                if ($batchNumber) {
                    $batch = Batch::firstOrCreate(
                        [
                            'product_id' => $poItem->product_id,
                            'variant_id' => $poItem->variant_id,
                            'warehouse_id' => $po->warehouse_id,
                            'batch_number' => $batchNumber,
                        ],
                        ['expiry_date' => $expiryDate, 'unit_cost' => $poItem->unit_cost],
                    );
                    $batch->increment('quantity', $qty);
                }

                // Serial tracking: register each unit.
                foreach ($serials as $serial) {
                    SerialNumber::create([
                        'product_id' => $poItem->product_id,
                        'variant_id' => $poItem->variant_id,
                        'warehouse_id' => $po->warehouse_id,
                        'serial' => $serial,
                        'status' => 'in_stock',
                        'reference_type' => GoodsReceipt::class,
                        'reference_id' => $grn->id,
                    ]);
                }

                // Post the inward movement: updates warehouse_stocks + ledger.
                $this->stock->inward(
                    warehouseId: $po->warehouse_id,
                    productId: $poItem->product_id,
                    variantId: $poItem->variant_id,
                    quantity: $qty,
                    unitCost: $unitCost,
                    type: 'purchase',
                    referenceType: GoodsReceipt::class,
                    referenceId: $grn->id,
                    referenceNumber: $grn->grn_number,
                    userId: $request->user()?->id,
                );

                $poItem->increment('received_quantity', $qty);
            }

            if ($landedTotal > 0) {
                LandedCost::create([
                    'goods_receipt_id' => $grn->id,
                    'freight_amount' => $charges['freight_amount'] ?? 0,
                    'duty_amount' => $charges['duty_amount'] ?? 0,
                    'other_amount' => $charges['other_amount'] ?? 0,
                    'allocation_method' => $charges['allocation_method'] ?? 'value',
                    'total_amount' => $landedTotal,
                ]);
            }

            $po->load('items');
            $po->refreshReceiptStatus();

            return $grn;
        });

        return (new GoodsReceiptResource($grn->load(['purchaseOrder', 'warehouse', 'items.product', 'landedCost'])))
            ->response()->setStatusCode(201);
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        return new GoodsReceiptResource(
            $goodsReceipt->load(['purchaseOrder', 'warehouse', 'supplier', 'items.product', 'landedCost'])
        );
    }
}
