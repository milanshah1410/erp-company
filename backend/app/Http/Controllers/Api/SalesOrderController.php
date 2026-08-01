<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RendersDocumentPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalesOrderRequest;
use App\Http\Resources\SalesOrderResource;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\BrokerCommissionService;
use App\Services\DocumentPricingService;
use App\Services\InventoryTrackingService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SalesOrderController extends Controller
{
    use RendersDocumentPdf;

    public function __construct(
        private readonly StockService $stock,
        private readonly InventoryTrackingService $tracking,
        private readonly BrokerCommissionService $brokerCommissions,
        private readonly DocumentPricingService $pricing,
        private readonly SalesOrderFulfillmentService $fulfillment,
    ) {}

    public function index(Request $request)
    {
        $query = SalesOrder::query()->with(['customer', 'warehouse', 'invoice']);

        if ($search = $request->query('search')) {
            $query->where('so_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesOrderResource::collection($query->paginate($perPage));
    }

    public function store(SalesOrderRequest $request)
    {
        $so = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $so = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $data['customer_id'],
                'broker_id' => $this->primaryBrokerId($data),
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'order_date' => $data['order_date'],
                'tcs_section' => $data['tcs_section'] ?? null,
                'tcs_rate' => $data['tcs_rate'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($so, $data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
            $this->syncBrokers($so, $data['brokers'] ?? []);

            return $so;
        });

        return (new SalesOrderResource($so->load(['customer', 'warehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(SalesOrder $salesOrder)
    {
        return new SalesOrderResource(
            $salesOrder->load(['customer', 'warehouse', 'items.product', 'invoice'])
        );
    }

    /** Stream the sales order as a printable Delivery Challan (quantities only, no pricing). */
    public function pdf(Request $request, SalesOrder $salesOrder)
    {
        $salesOrder->load([
            'customer', 'warehouse:id,name',
            'items.product:id,name,sku',
            'items.variant:id,name,sku',
        ]);

        return $this->renderDocument(
            $request,
            'documents.delivery-challan',
            ['salesOrder' => $salesOrder],
            "delivery-challan-{$salesOrder->so_number}.pdf",
        );
    }

    public function update(SalesOrderRequest $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $salesOrder) {
            $data = $request->validated();
            $salesOrder->update([
                'customer_id' => $data['customer_id'],
                'broker_id' => $this->primaryBrokerId($data),
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'tcs_section' => $data['tcs_section'] ?? null,
                'tcs_rate' => $data['tcs_rate'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);
            $salesOrder->items()->delete();
            $this->syncItems($salesOrder, $data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
            $this->syncBrokers($salesOrder, $data['brokers'] ?? []);
        });

        return new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product']));
    }

    public function destroy(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted.'], 422);
        }
        $salesOrder->delete();

        return response()->json(['message' => 'Sales order deleted.']);
    }

    /**
     * Confirm a draft order. Enforces the customer's credit limit, then
     * reserves whatever stock is available per line. Lines that can't be
     * fully covered are flagged as backorders instead of blocking the order.
     */
    public function confirm(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be confirmed.'], 422);
        }

        if ($error = $this->fulfillment->creditLimitError($salesOrder)) {
            return response()->json(['message' => $error], 422);
        }

        $this->fulfillment->reserveAndConfirm($salesOrder);

        return new SalesOrderResource($salesOrder->fresh()->load(['customer', 'warehouse', 'items.product']));
    }

    /**
     * Re-attempt reservation for a backordered order's outstanding lines
     * (e.g. after new stock has arrived). Flips to confirmed once every
     * line is fully covered.
     */
    public function recheck(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'backordered') {
            return response()->json(['message' => 'Only backordered orders can be rechecked.'], 422);
        }

        $salesOrder->load('items.product');

        DB::transaction(function () use ($salesOrder) {
            $hasBackorder = false;

            foreach ($salesOrder->items as $item) {
                $outstanding = (float) $item->backorder_quantity;
                if ($outstanding > 0) {
                    $newlyReserved = $this->fulfillment->reserveAvailable($salesOrder->warehouse_id, $item, $outstanding);
                    $item->update([
                        'reserved_quantity' => round((float) $item->reserved_quantity + $newlyReserved, 3),
                        'backorder_quantity' => round($outstanding - $newlyReserved, 3),
                    ]);
                }
                if ($item->backorder_quantity > 0) {
                    $hasBackorder = true;
                }
            }

            $salesOrder->update(['status' => $hasBackorder ? 'backordered' : 'confirmed']);
        });

        return new SalesOrderResource($salesOrder->fresh()->load(['customer', 'warehouse', 'items.product']));
    }

    /** Pick: confirmed order, fulfillment not yet started -> picked. */
    public function pick(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'confirmed' || $salesOrder->fulfillment_stage !== 'pending') {
            return response()->json(['message' => 'Only confirmed orders pending pick-up can be picked.'], 422);
        }

        $salesOrder->update([
            'fulfillment_stage' => 'picked',
            'picked_at' => now(),
            'picked_by' => $request->user()?->id,
        ]);

        return new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product']));
    }

    /** Pack: picked -> packed. */
    public function pack(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->fulfillment_stage !== 'picked') {
            return response()->json(['message' => 'Only picked orders can be packed.'], 422);
        }

        $salesOrder->update([
            'fulfillment_stage' => 'packed',
            'packed_at' => now(),
            'packed_by' => $request->user()?->id,
        ]);

        return new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product']));
    }

    /** Ship: packed -> shipped. Required before delivery can be confirmed (except POS, which delivers instantly). */
    public function ship(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->fulfillment_stage !== 'packed') {
            return response()->json(['message' => 'Only packed orders can be shipped.'], 422);
        }

        /**
         * The credit gate again, at the door. An order confirmed on Monday can
         * ship on Thursday, by which time the party may have crossed their limit
         * on other bills, aged past the workspace's overdue cut-off, or been put
         * on supply hold outright. Once the lorry has gone, none of that can be
         * undone — so this is the last honest place to ask.
         *
         * Nothing is added to the exposure: this order is already counted (it is
         * confirmed, so it sits in the pipeline), and adding its value again would
         * refuse a party for the very goods being loaded.
         */
        if ($error = $this->fulfillment->creditBlockReason($salesOrder->customer)) {
            return response()->json(['message' => $error], 422);
        }

        $salesOrder->update([
            'fulfillment_stage' => 'shipped',
            'shipped_at' => now(),
            'shipped_by' => $request->user()?->id,
        ]);

        return new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product']));
    }

    /**
     * Deliver a confirmed order: release its reservation, post outward
     * stock movements and raise the invoice, all in one transaction.
     */
    public function deliver(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed orders can be delivered.'], 422);
        }
        if ($salesOrder->channel !== 'pos' && $salesOrder->fulfillment_stage !== 'shipped') {
            return response()->json(['message' => 'Order must be shipped before delivery can be confirmed.'], 422);
        }

        // Optional per-line serial picks for serial-tracked products.
        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*.item_id' => ['required_with:items', 'integer'],
            'items.*.serials' => ['sometimes', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
        ]);
        $serialsByItem = collect($data['items'] ?? [])->keyBy('item_id');

        $salesOrder->load('items.product');

        foreach ($salesOrder->items as $item) {
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
            $invoice = DB::transaction(function () use ($salesOrder, $request, $serialsByItem) {
                foreach ($salesOrder->items as $item) {
                    $this->stock->release($salesOrder->warehouse_id, $item->product_id, $item->variant_id, (float) $item->reserved_quantity);

                    $this->stock->outward(
                        warehouseId: $salesOrder->warehouse_id,
                        productId: $item->product_id,
                        variantId: $item->variant_id,
                        quantity: (float) $item->quantity,
                        type: 'sale',
                        referenceType: SalesOrder::class,
                        referenceId: $salesOrder->id,
                        referenceNumber: $salesOrder->so_number,
                        userId: $request->user()?->id,
                    );

                    $updates = ['reserved_quantity' => 0];

                    if ($item->product?->track_batch) {
                        $updates['batch_allocations'] = $this->tracking->consumeBatchFefo(
                            $item->product_id, $item->variant_id, $salesOrder->warehouse_id, (float) $item->quantity,
                        );
                    }

                    if ($item->product?->track_serial) {
                        $serials = $serialsByItem->get($item->id)['serials'] ?? [];
                        $this->tracking->consumeSerials(
                            $item->product_id, $item->variant_id, $salesOrder->warehouse_id,
                            $serials, 'sold', SalesOrder::class, $salesOrder->id,
                        );
                        $updates['serials'] = $serials;
                    }

                    $item->update($updates);
                }

                $salesOrder->update(['status' => 'delivered', 'delivered_at' => now()]);

                $invoice = Invoice::create([
                    'invoice_number' => Invoice::nextNumber(),
                    'sales_order_id' => $salesOrder->id,
                    'customer_id' => $salesOrder->customer_id,
                    'broker_id' => $salesOrder->broker_id,
                    'invoice_date' => now()->toDateString(),
                    'total' => $salesOrder->total,
                    'paid_amount' => 0,
                    'status' => 'unpaid',
                ]);

                $this->brokerCommissions->copyBrokersToInvoice($salesOrder, $invoice);
                $this->brokerCommissions->accrueForInvoice($invoice);

                return $invoice;
            });
        } catch (RuntimeException $e) {
            // StockService refuses to drive stock negative.
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return (new SalesOrderResource(
            $salesOrder->fresh()->load(['customer', 'warehouse', 'items.product', 'invoice'])
        ))->additional(['invoice_number' => $invoice->invoice_number]);
    }

    public function cancel(SalesOrder $salesOrder)
    {
        if (! in_array($salesOrder->status, ['draft', 'confirmed', 'backordered'], true)) {
            return response()->json(['message' => 'Delivered orders cannot be cancelled.'], 422);
        }

        DB::transaction(function () use ($salesOrder) {
            if (in_array($salesOrder->status, ['confirmed', 'backordered'], true)) {
                $salesOrder->load('items');
                foreach ($salesOrder->items as $item) {
                    if ((float) $item->reserved_quantity > 0) {
                        $this->stock->release($salesOrder->warehouse_id, $item->product_id, $item->variant_id, (float) $item->reserved_quantity);
                    }
                    $item->update(['reserved_quantity' => 0, 'backorder_quantity' => 0]);
                }
            }

            $salesOrder->update(['status' => 'cancelled']);
        });

        return new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product']));
    }

    /** Create line items and recompute order totals (discounts + round-off). */
    /**
     * The single broker_id to stamp on the order for back-compat/display: the
     * first of the multi-broker list when given, else the explicit broker_id.
     */
    private function primaryBrokerId(array $data): ?int
    {
        if (! empty($data['brokers'])) {
            return (int) $data['brokers'][0]['broker_id'];
        }

        return isset($data['broker_id']) ? (int) $data['broker_id'] : null;
    }

    /** Replace the order's multi-broker rows (empty array clears them). */
    private function syncBrokers(SalesOrder $so, array $brokers): void
    {
        $so->brokers()->delete();

        foreach ($brokers as $b) {
            $so->brokers()->create([
                'broker_id' => $b['broker_id'],
                'commission_percent' => $b['commission_percent'] ?? null,
                'fixed_commission' => $b['fixed_commission'] ?? null,
                'share_percent' => $b['share_percent'] ?? 100,
            ]);
        }
    }

    private function syncItems(SalesOrder $so, array $items, ?string $discountType = null, float $discountValue = 0): void
    {
        $priced = $this->pricing->price($items, $discountType, $discountValue);

        foreach ($priced['lines'] as $line) {
            $so->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }

        $h = $priced['header'];
        // TCS is collected over and above the invoice value; stored separately
        // and does not alter the booked order total.
        $tcsAmount = round((float) $h['total'] * (float) $so->tcs_rate / 100, 2);
        $so->update([
            'subtotal' => $h['subtotal'],
            'item_discount_total' => $h['item_discount_total'],
            'discount_type' => $h['discount_type'],
            'discount_value' => $h['discount_value'],
            'discount_amount' => $h['discount_amount'],
            'tax_amount' => $h['tax_amount'],
            'tcs_amount' => $tcsAmount,
            'round_off' => $h['round_off'],
            'total' => $h['total'],
        ]);
    }
}
