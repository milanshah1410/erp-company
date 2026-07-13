<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesOrderResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\WarehouseStock;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Retail counter checkout. Unlike the Sales Order workflow (draft -> confirm
 * -> deliver), a POS sale is one atomic action: it creates a delivered
 * Sales Order, posts stock outward immediately, raises the invoice and
 * records every payment line in a single transaction.
 */
class PosController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    /** Product list for the POS grid, with on-hand availability for the chosen warehouse. */
    public function products(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');

        $query = Product::query()->where('is_active', true);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->limit(100)->get();

        if ($warehouseId) {
            $stocks = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $products->pluck('id'))
                ->whereNull('variant_id')
                ->get()
                ->keyBy('product_id');

            $products->each(function ($p) use ($stocks) {
                $p->available_qty = $stocks->has($p->id)
                    ? (float) $stocks[$p->id]->quantity - (float) $stocks[$p->id]->reserved_quantity
                    : 0;
            });
        }

        return response()->json(['data' => $products]);
    }

    /** Complete a counter sale: create SO + items, post stock, raise invoice, record payments. */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.mode' => ['required', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $customer = $data['customer_id'] ?? null
            ? Customer::findOrFail($data['customer_id'])
            : Customer::firstOrCreate(
                ['code' => 'WALKIN'],
                ['name' => 'Walk-in Customer', 'type' => 'retail', 'is_active' => true],
            );

        [$subtotal, $taxTotal, $lines] = $this->priceLines($data['items']);
        $total = round($subtotal + $taxTotal, 2);
        $paidTotal = round(array_sum(array_column($data['payments'], 'amount')), 2);

        if ($paidTotal > $total + 0.005) {
            throw ValidationException::withMessages(['payments' => ['Payments exceed the sale total.']]);
        }

        $creditShortfall = round($total - $paidTotal, 2);
        $limit = (float) $customer->credit_limit;
        if ($creditShortfall > 0 && $limit > 0) {
            $exposure = $customer->outstandingBalance() + $creditShortfall;
            if ($exposure > $limit) {
                throw ValidationException::withMessages([
                    'payments' => ["Credit limit exceeded: outstanding + this sale's unpaid balance would be ₹{$exposure}, over the ₹{$limit} limit."],
                ]);
            }
        }

        try {
            $result = DB::transaction(function () use ($request, $data, $customer, $lines, $subtotal, $taxTotal, $total, $paidTotal) {
                $salesOrder = SalesOrder::create([
                    'so_number' => SalesOrder::nextNumber(),
                    'customer_id' => $customer->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'status' => 'delivered',
                    'channel' => 'pos',
                    'order_date' => now()->toDateString(),
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxTotal,
                    'total' => $total,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()?->id,
                    'delivered_at' => now(),
                ]);

                foreach ($lines as $line) {
                    $salesOrder->items()->create($line);

                    $this->stock->outward(
                        warehouseId: $data['warehouse_id'],
                        productId: $line['product_id'],
                        variantId: $line['variant_id'],
                        quantity: (float) $line['quantity'],
                        type: 'sale',
                        referenceType: SalesOrder::class,
                        referenceId: $salesOrder->id,
                        referenceNumber: $salesOrder->so_number,
                        userId: $request->user()?->id,
                    );
                }

                $invoice = Invoice::create([
                    'invoice_number' => Invoice::nextNumber(),
                    'sales_order_id' => $salesOrder->id,
                    'customer_id' => $customer->id,
                    'invoice_date' => now()->toDateString(),
                    'total' => $total,
                    'paid_amount' => 0,
                    'status' => 'unpaid',
                ]);

                foreach ($data['payments'] as $payment) {
                    $invoice->payments()->create([
                        'amount' => $payment['amount'],
                        'mode' => $payment['mode'],
                        'payment_date' => now()->toDateString(),
                        'created_by' => $request->user()?->id,
                    ]);
                }

                $invoice->paid_amount = $paidTotal;
                $invoice->refreshStatus();

                return [$salesOrder, $invoice];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$salesOrder, $invoice] = $result;

        return (new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product'])))
            ->additional([
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total' => $invoice->total,
                    'paid_amount' => $invoice->paid_amount,
                    'balance_due' => $invoice->balanceDue(),
                    'status' => $invoice->status,
                ],
            ])->response()->setStatusCode(201);
    }

    /** Price each cart line and return [subtotal, taxTotal, pricedLines]. */
    private function priceLines(array $items): array
    {
        $subtotal = 0;
        $taxTotal = 0;
        $lines = [];

        foreach ($items as $item) {
            $qty = (float) $item['quantity'];
            $price = (float) $item['unit_price'];
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $lineBase = $qty * $price;
            $lineTax = round($lineBase * $taxPercent / 100, 2);

            $lines[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_percent' => $taxPercent,
                'tax_amount' => $lineTax,
                'line_total' => round($lineBase + $lineTax, 2),
            ];

            $subtotal += $lineBase;
            $taxTotal += $lineTax;
        }

        return [round($subtotal, 2), round($taxTotal, 2), $lines];
    }
}
