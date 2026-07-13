<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SalesReturn;
use App\Services\BrokerCommissionService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sales Return / Credit Note: stock coming back into a warehouse against a
 * specific invoice, with a monetary credit applied to that invoice (and
 * therefore the customer's outstanding balance) once approved.
 *
 * Lifecycle: draft -> approve posts every line inward to the immutable
 * ledger and credits the invoice; draft rows can still be edited, cancelled,
 * or deleted.
 */
class SalesReturnController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BrokerCommissionService $brokerCommissions,
    ) {}

    public function index(Request $request)
    {
        $query = SalesReturn::query()->with(['invoice:id,invoice_number', 'customer:id,name', 'warehouse:id,name'])->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('credit_note_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->payload($r)),
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
        $invoice = Invoice::with('salesOrder')->findOrFail($data['invoice_id']);

        $return = DB::transaction(function () use ($request, $data, $invoice) {
            $return = SalesReturn::create([
                'credit_note_number' => SalesReturn::nextNumber(),
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $invoice->salesOrder->warehouse_id,
                'status' => 'draft',
                'return_date' => $data['return_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($return, $data['items']);

            return $return;
        });

        return response()->json(['data' => $this->payload($return->load('items.product', 'invoice', 'customer', 'warehouse'))], 201);
    }

    public function show(SalesReturn $salesReturn)
    {
        return response()->json(['data' => $this->payload($salesReturn->load('items.product', 'invoice', 'customer', 'warehouse'))]);
    }

    public function update(Request $request, SalesReturn $salesReturn)
    {
        if ($salesReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft credit notes can be edited.'], 422);
        }
        $data = $this->validated($request);

        DB::transaction(function () use ($salesReturn, $data) {
            $salesReturn->update([
                'return_date' => $data['return_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $salesReturn->items()->delete();
            $this->syncItems($salesReturn, $data['items']);
        });

        return response()->json(['data' => $this->payload($salesReturn->load('items.product', 'invoice', 'customer', 'warehouse'))]);
    }

    public function destroy(SalesReturn $salesReturn)
    {
        if ($salesReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft credit notes can be deleted.'], 422);
        }
        $salesReturn->delete();

        return response()->json(['message' => 'Credit note deleted.']);
    }

    /** Approve: restock every line and credit the invoice. All-or-nothing. */
    public function approve(Request $request, SalesReturn $salesReturn)
    {
        if ($salesReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft credit notes can be approved.'], 422);
        }

        $salesReturn->load('items.product', 'invoice');

        try {
            DB::transaction(function () use ($salesReturn, $request) {
                foreach ($salesReturn->items as $item) {
                    $this->stock->inward(
                        warehouseId: $salesReturn->warehouse_id,
                        productId: $item->product_id,
                        variantId: $item->variant_id,
                        quantity: (float) $item->quantity,
                        unitCost: (float) $item->unit_price,
                        type: 'return_in',
                        referenceType: SalesReturn::class,
                        referenceId: $salesReturn->id,
                        referenceNumber: $salesReturn->credit_note_number,
                        userId: $request->user()?->id,
                    );
                }

                $invoice = $salesReturn->invoice;
                $invoice->credited_amount = round((float) $invoice->credited_amount + (float) $salesReturn->total, 2);
                $invoice->refreshStatus();

                // Reverse the broker's commission for the returned value.
                $this->brokerCommissions->reverseForReturn($salesReturn);

                $salesReturn->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->payload($salesReturn->fresh()->load('items.product', 'invoice', 'customer', 'warehouse'))]);
    }

    public function cancel(SalesReturn $salesReturn)
    {
        if ($salesReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft credit notes can be cancelled.'], 422);
        }
        $salesReturn->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->payload($salesReturn->load('items.product', 'invoice', 'customer', 'warehouse'))]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** Create line items and recompute credit note totals. */
    private function syncItems(SalesReturn $return, array $items): void
    {
        $subtotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $qty = (float) $item['quantity'];
            $price = (float) $item['unit_price'];
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $lineBase = $qty * $price;
            $lineTax = round($lineBase * $taxPercent / 100, 2);

            $return->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_percent' => $taxPercent,
                'tax_amount' => $lineTax,
                'line_total' => round($lineBase + $lineTax, 2),
                'reason' => $item['reason'] ?? null,
            ]);

            $subtotal += $lineBase;
            $taxTotal += $lineTax;
        }

        $return->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxTotal, 2),
            'total' => round($subtotal + $taxTotal, 2),
        ]);
    }

    private function payload(SalesReturn $r): array
    {
        return [
            'id' => $r->id,
            'credit_note_number' => $r->credit_note_number,
            'invoice_id' => $r->invoice_id,
            'invoice' => $r->relationLoaded('invoice') && $r->invoice
                ? ['id' => $r->invoice->id, 'invoice_number' => $r->invoice->invoice_number] : null,
            'customer_id' => $r->customer_id,
            'customer' => $r->relationLoaded('customer') && $r->customer
                ? ['id' => $r->customer->id, 'name' => $r->customer->name] : null,
            'warehouse_id' => $r->warehouse_id,
            'warehouse' => $r->relationLoaded('warehouse') && $r->warehouse
                ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : null,
            'status' => $r->status,
            'return_date' => $r->return_date?->toDateString(),
            'subtotal' => $r->subtotal,
            'tax_amount' => $r->tax_amount,
            'total' => $r->total,
            'notes' => $r->notes,
            'items_count' => $r->items_count ?? ($r->relationLoaded('items') ? $r->items->count() : null),
            'items' => $r->relationLoaded('items') ? $r->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product
                    ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name] : null,
                'variant_id' => $i->variant_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'tax_percent' => $i->tax_percent,
                'tax_amount' => $i->tax_amount,
                'line_total' => $i->line_total,
                'reason' => $i->reason,
            ]) : null,
            'approved_at' => $r->approved_at,
            'created_at' => $r->created_at,
        ];
    }
}
