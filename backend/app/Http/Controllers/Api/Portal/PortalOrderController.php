<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalOrderRequest;
use App\Http\Resources\SalesOrderResource;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\B2bOrderPlacedNotification;
use App\Services\DocumentPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The customer's own order surface on the B2B portal (SRS §14). Orders are
 * created as sales orders on the 'b2b' channel in 'pending_approval' state;
 * staff then approve/reject them. Everything here is scoped to the
 * authenticated customer.
 */
class PortalOrderController extends Controller
{
    public function __construct(private readonly DocumentPricingService $pricing) {}

    /** The authenticated customer's B2B orders, most recent first. */
    public function index(Request $request)
    {
        $query = SalesOrder::query()
            ->where('customer_id', $request->user()->id)
            ->where('channel', 'b2b')
            ->with(['warehouse:id,name', 'invoice'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesOrderResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, SalesOrder $salesOrder)
    {
        $this->assertOwned($request, $salesOrder);

        return new SalesOrderResource(
            $salesOrder->load(['warehouse:id,name', 'items.product:id,sku,name', 'invoice'])
        );
    }

    public function store(PortalOrderRequest $request)
    {
        $customer = $request->user();

        $warehouseId = $this->resolveWarehouseId();
        if (! $warehouseId) {
            return response()->json(['message' => 'Online ordering is not available right now. Please contact us.'], 422);
        }

        $data = $request->validated();
        $products = Product::query()
            ->whereIn('id', collect($data['items'])->pluck('product_id')->unique())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        // Resolve each line's price server-side from the customer's tier /
        // overrides — the client never sends prices.
        $lines = [];
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                return response()->json(['message' => 'One or more products are no longer available.'], 422);
            }
            $variantId = $item['variant_id'] ?? null;
            $qty = (float) $item['quantity'];
            $lines[] = [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'quantity' => $qty,
                'unit_price' => $customer->priceFor($product, $variantId, $qty),
                'tax_percent' => (float) $product->tax_percent,
            ];
        }

        $priced = $this->pricing->price($lines);

        $salesOrder = DB::transaction(function () use ($priced, $customer, $warehouseId, $data) {
            $header = $priced['header'];
            $salesOrder = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouseId,
                'status' => 'pending_approval',
                'channel' => 'b2b',
                'order_date' => now()->toDateString(),
                'subtotal' => $header['subtotal'],
                'item_discount_total' => $header['item_discount_total'],
                'discount_type' => $header['discount_type'],
                'discount_value' => $header['discount_value'],
                'discount_amount' => $header['discount_amount'],
                'tax_amount' => $header['tax_amount'],
                'round_off' => $header['round_off'],
                'total' => $header['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($priced['lines'] as $line) {
                $salesOrder->items()->create([
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

            return $salesOrder;
        });

        $this->notifyStaff($salesOrder);

        return (new SalesOrderResource($salesOrder->load(['warehouse:id,name', 'items.product:id,sku,name'])))
            ->response()->setStatusCode(201);
    }

    /** Cancel an order the customer placed, while it is still awaiting approval. */
    public function cancel(Request $request, SalesOrder $salesOrder)
    {
        $this->assertOwned($request, $salesOrder);

        if ($salesOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only orders awaiting approval can be cancelled.'], 422);
        }

        $salesOrder->update(['status' => 'cancelled']);

        return new SalesOrderResource($salesOrder->load(['warehouse:id,name', 'items.product:id,sku,name']));
    }

    /** 404 if the order is not this customer's B2B order (belt-and-braces over the tenant scope). */
    private function assertOwned(Request $request, SalesOrder $salesOrder): void
    {
        abort_unless(
            $salesOrder->customer_id === $request->user()->id && $salesOrder->channel === 'b2b',
            404,
        );
    }

    /** Configured B2B warehouse, else the first available warehouse. */
    private function resolveWarehouseId(): ?int
    {
        $configured = Setting::get('b2b_warehouse_id');
        if ($configured && Warehouse::query()->whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return Warehouse::query()->orderBy('id')->value('id');
    }

    /** Alert staff who can approve B2B orders that a new one has arrived. */
    private function notifyStaff(SalesOrder $salesOrder): void
    {
        $recipients = User::role(['super-admin', 'admin', 'sales-manager'])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new B2bOrderPlacedNotification($salesOrder));
        }
    }
}
