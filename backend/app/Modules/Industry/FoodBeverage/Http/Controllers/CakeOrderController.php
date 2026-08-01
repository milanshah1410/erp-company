<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\FoodBeverage\Http\Requests\CakeOrderRequest;
use App\Modules\Industry\FoodBeverage\Models\CakeOrder;
use App\Modules\Industry\FoodBeverage\Services\CakeOrderService;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The bakery's order book.
 *
 * The default listing is the board — open orders, soonest first — because that is
 * what a bakery opens this screen to see. Delivered and cancelled orders are behind
 * an explicit status filter: they are history, and history at the top of the list
 * pushes tomorrow's cakes off the screen.
 */
class CakeOrderController extends Controller
{
    public function __construct(private readonly CakeOrderService $cakes) {}

    public function index(Request $request)
    {
        $query = CakeOrder::query()->with(['customer:id,code,name,phone', 'product:id,sku,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('message_on_cake', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            // The board by default.
            $query->open();
        }

        if ($request->filled('from')) {
            $query->whereDate('required_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('required_at', '<=', $request->query('to'));
        }

        if ($request->boolean('overdue')) {
            $query->open()->where('required_at', '<', now());
        }

        $query->orderBy('required_at');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (CakeOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->cakes->boardCounts()],
        );
    }

    public function show(CakeOrder $cakeOrder)
    {
        $cakeOrder->load(['customer:id,code,name,phone', 'product:id,sku,name', 'invoice:id,invoice_number,total,paid_amount,status']);

        return response()->json(['data' => $this->row($cakeOrder, detailed: true)]);
    }

    public function store(CakeOrderRequest $request)
    {
        $order = $this->cakes->book($request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->row($order->fresh(['customer', 'product']))], 201);
    }

    public function update(CakeOrderRequest $request, CakeOrder $cakeOrder)
    {
        /**
         * A billed cake is closed to edits. The invoice is the record of what was
         * sold, and letting the weight or the price change underneath it would put
         * the order book and the ledger permanently out of step.
         */
        if ($cakeOrder->invoice_id) {
            return response()->json([
                'message' => 'This order has been billed. Raise a credit note through Sales to change it.',
            ], 422);
        }

        $cakeOrder->update($request->validated());

        return response()->json(['data' => $this->row($cakeOrder->fresh(['customer', 'product']))]);
    }

    public function destroy(CakeOrder $cakeOrder)
    {
        if ($cakeOrder->invoice_id) {
            return response()->json([
                'message' => 'This order has been billed and cannot be deleted.',
            ], 422);
        }

        $cakeOrder->delete();

        return response()->json(['message' => 'Cake order deleted.']);
    }

    /** Move the order along the board: in production, ready, or cancelled. */
    public function setStatus(Request $request, CakeOrder $cakeOrder)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['booked', 'in_production', 'ready', 'cancelled'])],
        ]);

        try {
            $order = $this->cakes->setStatus($cakeOrder, $data['status']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($order->fresh(['customer', 'product']))]);
    }

    /** Hand it over: raises the sale and carries the advance across as a payment. */
    public function deliver(Request $request, CakeOrder $cakeOrder)
    {
        $data = $request->validate([
            'advance_mode' => ['nullable', Rule::in(['cash', 'card', 'upi'])],
        ]);

        try {
            $invoice = $this->cakes->deliver(
                $cakeOrder,
                $data['advance_mode'] ?? 'cash',
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Billed as invoice {$invoice->invoice_number}.",
            'data' => $this->row($cakeOrder->fresh(['customer', 'product', 'invoice'])),
        ]);
    }

    /**
     * Attach the reference photo the customer sent.
     *
     * Stored on the tenant's private disk through TenantStorage and served back
     * through a signed URL — it is customer material, not a public asset.
     */
    public function uploadReference(Request $request, CakeOrder $cakeOrder)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $disk = TenantStorage::disk();
        $path = $disk->putFile('cake-orders', $request->file('image'));

        // The previous reference is removed: a cake has one design, and orphaned
        // uploads accumulate in a bucket nobody ever cleans.
        if ($cakeOrder->reference_image) {
            $disk->delete($cakeOrder->reference_image);
        }

        $cakeOrder->forceFill(['reference_image' => $path])->save();

        return response()->json([
            'data' => [
                'reference_image' => $path,
                'reference_image_url' => TenantStorage::temporaryUrl($path),
            ],
        ]);
    }

    private function row(CakeOrder $o, bool $detailed = false): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'customer_id' => $o->customer_id,
            'customer_name' => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'product_id' => $o->product_id,
            'product' => $o->product?->name,
            'flavour' => $o->flavour,
            'weight_kg' => (float) $o->weight_kg,
            'shape' => $o->shape,
            'tiers' => (int) $o->tiers,
            'is_eggless' => (bool) $o->is_eggless,
            'message_on_cake' => $o->message_on_cake,
            'design_notes' => $o->design_notes,
            'reference_image_url' => TenantStorage::temporaryUrl($o->reference_image),
            'required_at' => $o->required_at?->toDateTimeString(),
            'hours_remaining' => $o->required_at
                ? round(now()->diffInMinutes($o->required_at, false) / 60, 1)
                : null,
            'is_overdue' => $o->isOverdue(),
            'fulfilment' => $o->fulfilment,
            'delivery_address' => $o->delivery_address,
            'delivery_charge' => (float) $o->delivery_charge,
            'price' => (float) $o->price,
            'payable' => $o->payable(),
            'advance_amount' => (float) $o->advance_amount,
            'balance' => $o->balance(),
            'status' => $o->status,
            'invoice_id' => $o->invoice_id,
            'invoice_number' => $detailed ? $o->invoice?->invoice_number : null,
            'notes' => $o->notes,
            'created_at' => $o->created_at?->toDateTimeString(),
        ];
    }
}
