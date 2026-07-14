<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesOrderResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\WarehouseStock;
use App\Services\BrokerCommissionService;
use App\Services\DocumentPricingService;
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
 *
 * The controller also drives the two after-sale counter flows:
 *  - Return: goods come back against a past invoice; stock is restocked, the
 *    invoice credited (a Sales Return / credit note) and the money refunded.
 *  - Exchange: a return and a fresh sale settled together, with the returned
 *    goods' value applied as an 'exchange' credit against the new invoice and
 *    only the net difference tendered or refunded.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BrokerCommissionService $brokerCommissions,
        private readonly DocumentPricingService $pricing,
    ) {}

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
            $this->attachAvailability($products, (int) $warehouseId);
        }

        return response()->json(['data' => $products]);
    }

    /**
     * Resolve a single item from a scanned/keyed code (barcode, SKU or RFID tag)
     * so the terminal can add exactly one line per scan. Matches products first,
     * then product variants, returning the price and on-hand availability.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
        ]);

        $code = trim($data['code']);
        $warehouseId = $data['warehouse_id'] ?? null;

        $product = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('rfid_tag', $code))
            ->first();

        if ($product) {
            if ($warehouseId) {
                $this->attachAvailability(collect([$product]), (int) $warehouseId);
            }

            return response()->json(['data' => [
                'product_id' => $product->id,
                'variant_id' => null,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'unit_price' => (float) $product->selling_price,
                'tax_percent' => (float) $product->tax_percent,
                'available_qty' => $product->available_qty ?? null,
            ]]);
        }

        $variant = ProductVariant::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('rfid_tag', $code))
            ->with('product')
            ->first();

        if ($variant && $variant->product) {
            $available = null;
            if ($warehouseId) {
                $stock = WarehouseStock::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $variant->product_id)
                    ->where('variant_id', $variant->id)
                    ->first();
                $available = $stock ? (float) $stock->quantity - (float) $stock->reserved_quantity : 0;
            }

            return response()->json(['data' => [
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'name' => trim($variant->product->name.' '.($variant->name ?? '')),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'unit_price' => (float) ($variant->selling_price ?: $variant->product->selling_price),
                'tax_percent' => (float) $variant->product->tax_percent,
                'available_qty' => $available,
            ]]);
        }

        return response()->json(['message' => "No product matches code \"{$code}\"."], 404);
    }

    /** Complete a counter sale: create SO + items, post stock, raise invoice, record payments. */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'broker_id' => ['nullable', 'exists:brokers,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.mode' => ['required', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        $priced = $this->pricing->price($data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        $total = $priced['header']['total'];
        $paidTotal = round(array_sum(array_column($data['payments'], 'amount')), 2);

        if ($paidTotal > $total + 0.005) {
            throw ValidationException::withMessages(['payments' => ['Payments exceed the sale total.']]);
        }

        $this->assertWithinCreditLimit($customer, round($total - $paidTotal, 2));

        try {
            $result = DB::transaction(function () use ($request, $data, $customer, $priced, $total, $paidTotal) {
                [$salesOrder, $invoice] = $this->createSale($request, $data, $customer, $priced, $total);

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

        return $this->saleResponse($salesOrder, $invoice);
    }

    /** Recent invoices for the return/exchange lookup, newest first. */
    public function invoices(Request $request)
    {
        $query = Invoice::query()
            ->with(['customer:id,name', 'salesOrder:id,so_number,channel'])
            ->orderByDesc('id');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $invoices = $query->limit(25)->get()->map(fn ($i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'customer' => $i->customer?->name,
            'customer_id' => $i->customer_id,
            'total' => $i->total,
            'status' => $i->status,
            'channel' => $i->salesOrder?->channel,
        ]);

        return response()->json(['data' => $invoices]);
    }

    /** Invoice detail with per-line returnable quantity (sold minus already returned). */
    public function invoice(Invoice $invoice)
    {
        $invoice->load(['customer', 'salesOrder.items.product', 'salesOrder.warehouse']);

        if (! $invoice->salesOrder) {
            return response()->json(['message' => 'This invoice has no linked sale to return against.'], 422);
        }

        $returned = $this->returnedQuantities($invoice);

        $lines = $invoice->salesOrder->items->map(function ($it) use ($returned) {
            $key = $it->product_id.':'.($it->variant_id ?? 0);
            $ret = $returned[$key] ?? 0;

            return [
                'product_id' => $it->product_id,
                'variant_id' => $it->variant_id,
                'name' => $it->product?->name,
                'sku' => $it->product?->sku,
                'sold_quantity' => (float) $it->quantity,
                'returned_quantity' => $ret,
                'returnable_quantity' => max(0, (float) $it->quantity - $ret),
                'unit_price' => (float) $it->unit_price,
                'tax_percent' => (float) $it->tax_percent,
                'discount_type' => $it->discount_type,
                'discount_value' => (float) $it->discount_value,
            ];
        })->values();

        return response()->json(['data' => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'customer' => $invoice->customer?->name,
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $invoice->salesOrder->warehouse_id,
            'warehouse' => $invoice->salesOrder->warehouse?->name,
            'total' => $invoice->total,
            'paid_amount' => $invoice->paid_amount,
            'credited_amount' => $invoice->credited_amount,
            'balance_due' => $invoice->balanceDue(),
            'status' => $invoice->status,
            'lines' => $lines,
        ]]);
    }

    /**
     * Process a counter return: raise an approved credit note against the
     * invoice, restock every line, credit the invoice and refund the money
     * (a negative payment in the chosen tender, unless kept as store credit).
     */
    public function processReturn(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'return_date' => ['nullable', 'date'],
            'refund_mode' => ['nullable', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'notes' => ['nullable', 'string'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = Invoice::with('salesOrder')->findOrFail($data['invoice_id']);
        if (! $invoice->salesOrder) {
            throw ValidationException::withMessages(['invoice_id' => ['This invoice has no linked sale to return against.']]);
        }
        $this->assertReturnable($invoice, $data['items']);

        $refundMode = $data['refund_mode'] ?? 'cash';

        try {
            $result = DB::transaction(function () use ($request, $data, $invoice, $refundMode) {
                $return = $this->createApprovedReturn(
                    $request,
                    $invoice,
                    $data['items'],
                    $data['discount_type'] ?? null,
                    (float) ($data['discount_value'] ?? 0),
                    $data['return_date'] ?? now()->toDateString(),
                    $data['notes'] ?? null,
                );

                $refund = 0.0;
                if ($refundMode !== 'credit') {
                    $refund = (float) $return->total;
                    $invoice->payments()->create([
                        'amount' => -$refund,
                        'mode' => $refundMode,
                        'payment_date' => now()->toDateString(),
                        'notes' => "Refund for credit note {$return->credit_note_number}",
                        'created_by' => $request->user()?->id,
                    ]);
                    $invoice->paid_amount = round((float) $invoice->paid_amount - $refund, 2);
                    $invoice->refreshStatus();
                }

                return [$return, $refund];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$return, $refund] = $result;

        return response()->json([
            'data' => [
                'credit_note_number' => $return->credit_note_number,
                'invoice_number' => $invoice->invoice_number,
                'return_total' => (float) $return->total,
                'refund_amount' => $refund,
                'refund_mode' => $refundMode,
                'balance_due' => $invoice->fresh()->balanceDue(),
            ],
        ], 201);
    }

    /**
     * Exchange: return goods and ring up a new sale in one transaction. The
     * returned value is applied as an 'exchange' credit against the new
     * invoice; only the net (extra to pay, or difference to refund) moves.
     */
    public function exchange(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'original_invoice_id' => ['required', 'exists:invoices,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'broker_id' => ['nullable', 'exists:brokers,id'],
            'notes' => ['nullable', 'string'],
            'refund_mode' => ['nullable', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'return_items' => ['required', 'array', 'min:1'],
            'return_items.*.product_id' => ['required', 'exists:products,id'],
            'return_items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'return_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'return_items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'return_items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'return_items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'return_items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'return_items.*.reason' => ['nullable', 'string', 'max:255'],
            'return_discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'return_discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.mode' => ['required_with:payments', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
        ]);

        $originalInvoice = Invoice::with('salesOrder')->findOrFail($data['original_invoice_id']);
        if (! $originalInvoice->salesOrder) {
            throw ValidationException::withMessages(['original_invoice_id' => ['This invoice has no linked sale to return against.']]);
        }
        $this->assertReturnable($originalInvoice, $data['return_items']);

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);
        $refundMode = $data['refund_mode'] ?? 'cash';

        $newPriced = $this->pricing->price($data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        $newTotal = $newPriced['header']['total'];

        $returnPriced = $this->pricing->price($data['return_items'], $data['return_discount_type'] ?? null, (float) ($data['return_discount_value'] ?? 0));
        $returnTotal = $returnPriced['header']['total'];

        $appliedCredit = round(min($returnTotal, $newTotal), 2);
        $excessReturn = round($returnTotal - $appliedCredit, 2);
        $paidTotal = round(array_sum(array_column($data['payments'] ?? [], 'amount')), 2);

        $covered = round($paidTotal + $appliedCredit, 2);
        if ($covered > $newTotal + 0.005) {
            throw ValidationException::withMessages(['payments' => ['Payments plus the exchange credit exceed the new sale total.']]);
        }

        $this->assertWithinCreditLimit($customer, round($newTotal - $covered, 2));

        try {
            $result = DB::transaction(function () use ($request, $data, $originalInvoice, $customer, $newPriced, $newTotal, $returnTotal, $appliedCredit, $excessReturn, $paidTotal, $refundMode) {
                // 1. Return the traded-in goods against the original invoice.
                $return = $this->createApprovedReturn(
                    $request,
                    $originalInvoice,
                    $data['return_items'],
                    $data['return_discount_type'] ?? null,
                    (float) ($data['return_discount_value'] ?? 0),
                    now()->toDateString(),
                    $data['notes'] ?? null,
                );

                // Consume the credit off the original invoice: the applied part
                // moves into the new sale, and any excess is cash-refunded unless
                // the customer keeps it as store credit.
                $excessCashRefund = $refundMode !== 'credit' ? $excessReturn : 0.0;
                $consume = round($appliedCredit + $excessCashRefund, 2);
                if ($consume > 0) {
                    $originalInvoice->payments()->create([
                        'amount' => -$consume,
                        'mode' => 'exchange',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Exchange against credit note {$return->credit_note_number}",
                        'created_by' => $request->user()?->id,
                    ]);
                    $originalInvoice->paid_amount = round((float) $originalInvoice->paid_amount - $consume, 2);
                    $originalInvoice->refreshStatus();
                }

                // 2. Ring up the new sale.
                [$salesOrder, $invoice] = $this->createSale($request, $data, $customer, $newPriced, $newTotal);

                foreach ($data['payments'] ?? [] as $payment) {
                    $invoice->payments()->create([
                        'amount' => $payment['amount'],
                        'mode' => $payment['mode'],
                        'payment_date' => now()->toDateString(),
                        'created_by' => $request->user()?->id,
                    ]);
                }

                if ($appliedCredit > 0) {
                    $invoice->payments()->create([
                        'amount' => $appliedCredit,
                        'mode' => 'exchange',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Exchange credit from credit note {$return->credit_note_number}",
                        'created_by' => $request->user()?->id,
                    ]);
                }

                $invoice->paid_amount = round($paidTotal + $appliedCredit, 2);
                $invoice->refreshStatus();

                return [$return, $salesOrder, $invoice];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$return, $salesOrder, $invoice] = $result;

        return $this->exchangeResponse($return, $salesOrder, $invoice, $returnTotal, $newTotal, $refundMode);
    }

    // ---- Shared helpers -----------------------------------------------------

    /** Resolve the paying customer, defaulting to the shared walk-in record. */
    private function resolveCustomer(?int $customerId): Customer
    {
        return $customerId
            ? Customer::findOrFail($customerId)
            : Customer::firstOrCreate(
                ['code' => 'WALKIN'],
                ['name' => 'Walk-in Customer', 'type' => 'retail', 'is_active' => true],
            );
    }

    /** Guard the customer's credit limit against the unpaid balance of a sale. */
    private function assertWithinCreditLimit(Customer $customer, float $shortfall): void
    {
        $limit = (float) $customer->credit_limit;
        if ($shortfall > 0 && $limit > 0) {
            $exposure = $customer->outstandingBalance() + $shortfall;
            if ($exposure > $limit) {
                throw ValidationException::withMessages([
                    'payments' => ["Credit limit exceeded: outstanding + this sale's unpaid balance would be ₹{$exposure}, over the ₹{$limit} limit."],
                ]);
            }
        }
    }

    /** Create the delivered POS sales order + items, post stock outward and raise the (unpaid) invoice. */
    private function createSale(Request $request, array $data, Customer $customer, array $priced, float $total): array
    {
        $header = $priced['header'];

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $customer->id,
            'broker_id' => $data['broker_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'],
            'status' => 'delivered',
            'channel' => 'pos',
            'order_date' => now()->toDateString(),
            'subtotal' => $header['subtotal'],
            'item_discount_total' => $header['item_discount_total'],
            'discount_type' => $header['discount_type'],
            'discount_value' => $header['discount_value'],
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $total,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
            'delivered_at' => now(),
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
            'broker_id' => $salesOrder->broker_id,
            'invoice_date' => now()->toDateString(),
            'total' => $total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        return [$salesOrder, $invoice];
    }

    /**
     * Raise an already-approved credit note against an invoice: price the lines,
     * restock each inward, credit the invoice and reverse broker commission.
     * Mirrors SalesReturnController::approve, collapsed into a single step.
     */
    private function createApprovedReturn(Request $request, Invoice $invoice, array $items, ?string $discountType, float $discountValue, string $returnDate, ?string $notes): SalesReturn
    {
        $priced = $this->pricing->price($items, $discountType, $discountValue);
        $h = $priced['header'];

        $return = SalesReturn::create([
            'credit_note_number' => SalesReturn::nextNumber(),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $invoice->salesOrder->warehouse_id,
            'status' => 'approved',
            'return_date' => $returnDate,
            'subtotal' => $h['subtotal'],
            'item_discount_total' => $h['item_discount_total'],
            'discount_type' => $h['discount_type'],
            'discount_value' => $h['discount_value'],
            'discount_amount' => $h['discount_amount'],
            'tax_amount' => $h['tax_amount'],
            'round_off' => $h['round_off'],
            'total' => $h['total'],
            'notes' => $notes,
            'created_by' => $request->user()?->id,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        foreach ($priced['lines'] as $line) {
            $return->items()->create([
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
                'reason' => $line['source']['reason'] ?? null,
            ]);

            $this->stock->inward(
                warehouseId: $return->warehouse_id,
                productId: $line['product_id'],
                variantId: $line['variant_id'],
                quantity: (float) $line['quantity'],
                unitCost: (float) $line['unit_price'],
                type: 'return_in',
                referenceType: SalesReturn::class,
                referenceId: $return->id,
                referenceNumber: $return->credit_note_number,
                userId: $request->user()?->id,
            );
        }

        $invoice->credited_amount = round((float) $invoice->credited_amount + (float) $return->total, 2);
        $invoice->refreshStatus();

        $this->brokerCommissions->reverseForReturn($return);

        return $return;
    }

    /** On-hand (quantity minus reserved) per product for a warehouse, set on each model. */
    private function attachAvailability($products, int $warehouseId): void
    {
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

    /** Quantity already returned per product/variant across approved credit notes on this invoice. */
    private function returnedQuantities(Invoice $invoice): array
    {
        $returned = [];
        $items = SalesReturnItem::query()
            ->whereHas('salesReturn', fn ($q) => $q->where('invoice_id', $invoice->id)->where('status', 'approved'))
            ->get();

        foreach ($items as $it) {
            $key = $it->product_id.':'.($it->variant_id ?? 0);
            $returned[$key] = ($returned[$key] ?? 0) + (float) $it->quantity;
        }

        return $returned;
    }

    /** Reject a return whose quantities exceed what remains returnable on the invoice. */
    private function assertReturnable(Invoice $invoice, array $items): void
    {
        $invoice->loadMissing('salesOrder.items');

        $sold = [];
        foreach ($invoice->salesOrder->items as $it) {
            $key = $it->product_id.':'.($it->variant_id ?? 0);
            $sold[$key] = ($sold[$key] ?? 0) + (float) $it->quantity;
        }

        $returned = $this->returnedQuantities($invoice);

        foreach ($items as $line) {
            $key = $line['product_id'].':'.($line['variant_id'] ?? 0);
            $available = ($sold[$key] ?? 0) - ($returned[$key] ?? 0);
            if ((float) $line['quantity'] > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'items' => ["A return line exceeds the returnable quantity (max {$available}) for that product on this invoice."],
                ]);
            }
        }
    }

    private function saleResponse(SalesOrder $salesOrder, Invoice $invoice)
    {
        return (new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product'])))
            ->additional(['invoice' => $this->invoiceSummary($invoice)])
            ->response()
            ->setStatusCode(201);
    }

    private function exchangeResponse(SalesReturn $return, SalesOrder $salesOrder, Invoice $invoice, float $returnTotal, float $newTotal, string $refundMode)
    {
        $net = round($newTotal - $returnTotal, 2);

        return (new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product'])))
            ->additional([
                'invoice' => $this->invoiceSummary($invoice),
                'exchange' => [
                    'credit_note_number' => $return->credit_note_number,
                    'return_total' => $returnTotal,
                    'new_total' => $newTotal,
                    'net_payable' => max(0, $net),
                    'net_refund' => max(0, -$net),
                    'refund_mode' => $refundMode,
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    private function invoiceSummary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => $invoice->total,
            'paid_amount' => $invoice->paid_amount,
            'balance_due' => $invoice->balanceDue(),
            'status' => $invoice->status,
        ];
    }
}
