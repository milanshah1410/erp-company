<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesOrderResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OldGoldReceipt;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Models\PosCounter;
use App\Models\PosCounterSession;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\WarehouseStock;
use App\Modules\Industry\Cosmetics\Services\CosmeticCounterService;
use App\Modules\Industry\Distribution\Services\CreditControlService;
use App\Modules\Industry\Grocery\Services\ComboPackService;
use App\Modules\Industry\Toys\Services\ToyCounterService;
use App\Services\BarcodeService;
use App\Services\BrokerCommissionService;
use App\Services\DocumentPricingService;
use App\Services\GiftVoucherService;
use App\Services\JewelleryPricingService;
use App\Services\LoyaltyService;
use App\Services\PaymentGatewayService;
use App\Services\PharmacyService;
use App\Services\PosCounterService;
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
        private readonly LoyaltyService $loyalty,
        private readonly PaymentGatewayService $gateways,
        private readonly BarcodeService $barcodes,
        private readonly PharmacyService $pharmacy,
        private readonly JewelleryPricingService $jewellery,
        private readonly GiftVoucherService $vouchers,
        private readonly PosCounterService $counters,
        /**
         * Grocery combo packs. The counter bills "1 × Diwali Hamper"; the shelf
         * loses sugar, ghee and dry fruit. Injected here for the same reason
         * CreditControlService is — the rule belongs to the vertical, but the
         * only place it can be applied is where stock actually moves, and a
         * workspace that never bought Grocery simply has no packs to expand.
         */
        private readonly ComboPackService $comboPacks,
        /**
         * Toy safety. A toy whose mandatory certificate has lapsed may not be
         * sold at all, and nothing on the shelf looks any different — so the
         * check has to happen where the sale does. Injected for the same reason
         * as the two above: the rule belongs to the vertical, the only place it
         * can be applied is the till, and a workspace without Toys is answered
         * "not your business" by the service itself.
         */
        private readonly ToyCounterService $toys,
        /**
         * Shelf life. Selling cosmetics past their date is an offence rather than
         * a markdown decision, and the batch FEFO is about to reach for is only
         * knowable at the moment of billing — so, like the three above, the rule
         * belongs to the vertical and the till is the only place it can be
         * applied. A workspace without Beauty is answered "not your business" by
         * the service itself.
         */
        private readonly CosmeticCounterService $cosmetics,
    ) {}

    /**
     * The till shift this request is billing on, or null when the shop does not
     * run counters.
     *
     * Request-scoped state on a controller, which is safe here because Laravel
     * resolves a fresh controller per request — and preferable to threading the
     * session through eleven tender sites and every closure between them, where
     * the one that got missed would silently under-report a drawer.
     */
    private ?PosCounterSession $session = null;

    /**
     * Record a tender line, stamped with the drawer it passed through.
     *
     * Every payment this controller writes goes through here — sale, refund,
     * voucher restoration and exchange credit alike. That is deliberate: cash-up
     * only works if *all* the money moving at a till is attributed, and a refund
     * that skipped the stamp would leave the shift over by exactly the amount
     * handed back.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tender(Invoice $invoice, array $attributes): void
    {
        $invoice->payments()->create($attributes + ['pos_session_id' => $this->session?->id]);
    }

    /**
     * Resolve the shift for a request that named a counter.
     *
     * Optional throughout: a shop that has never defined a lane passes no
     * `counter_id`, nothing is stamped, and the till behaves exactly as it did
     * before counters existed. Once a counter *is* named it must have an open
     * session, because a bill attributed to a drawer nobody opened is a bill
     * whose cash has nowhere to be counted.
     */
    private function resolveSession(?int $counterId): void
    {
        $this->session = null;

        if (! $counterId) {
            return;
        }

        $counter = PosCounter::find($counterId);

        if (! $counter) {
            throw ValidationException::withMessages(['counter_id' => ['That counter does not exist.']]);
        }

        try {
            $this->session = $this->counters->requireOpenSession($counter);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['counter_id' => [$e->getMessage()]]);
        }
    }

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

        $this->attachRatePricing($products);

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
                'tax_percent' => (float) $product->tax_percent,
                'available_qty' => $product->available_qty ?? null,
            ] + $this->priceOf($product)]);
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
            // The lane this bill was rung up at. Optional: a single-till shop
            // has no counters defined and nothing changes for it.
            'counter_id' => ['nullable', 'exists:pos_counters,id'],
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
            // Points the customer wants to spend on this bill. Settled as a
            // tender, not a discount, so the invoice bills at the price rung up.
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            // The prescription authorising any scheduled medicine on the bill.
            'prescription_id' => ['nullable', 'exists:prescriptions,id'],
            // Old-gold receipts the customer is settling this bill with.
            'old_gold_receipt_ids' => ['nullable', 'array'],
            'old_gold_receipt_ids.*' => ['integer'],
            // Gift vouchers presented at the till. Codes rather than ids: what
            // the counter has is a card, and making the till look the id up
            // first would just be a round-trip in the way of a queue.
            'gift_voucher_codes' => ['nullable', 'array', 'max:5'],
            'gift_voucher_codes.*' => ['string', 'max:40'],
        ]);

        // Resolved before anything is written, so a bill aimed at a closed
        // drawer is refused rather than posted and then orphaned.
        $this->resolveSession(isset($data['counter_id']) ? (int) $data['counter_id'] : null);

        // Scrap already taken in settles part of the bill like any other tender.
        // Only unsettled receipts count — one already set off against another
        // invoice would be spending the same gold twice.
        $oldGold = ! empty($data['old_gold_receipt_ids'])
            ? OldGoldReceipt::whereIn('id', $data['old_gold_receipt_ids'])->whereNull('invoice_id')->get()
            : collect();

        if (! empty($data['old_gold_receipt_ids']) && $oldGold->count() !== count($data['old_gold_receipt_ids'])) {
            throw ValidationException::withMessages([
                'old_gold_receipt_ids' => ['One or more old-gold receipts have already been settled against another bill.'],
            ]);
        }

        $oldGoldTotal = round((float) $oldGold->sum('amount'), 2);

        // A Schedule H/H1/X line may not leave the counter without a usable
        // prescription behind it. Checked before anything is written, so a
        // refused sale leaves no stock movement or half-built order behind.
        $prescription = isset($data['prescription_id'])
            ? Prescription::find($data['prescription_id'])
            : null;

        try {
            $this->pharmacy->assertSellable($data['items'], $prescription);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['prescription_id' => [$e->getMessage()]]);
        }

        /**
         * The same guard for a toy shop, at the same point and for the same
         * reason: a toy whose mandatory certificate has lapsed may not leave the
         * counter, and a refusal has to happen before anything is written.
         * Reported against `items` because it is a line that is the problem, and
         * the counter's fix is to take it off the bill.
         */
        try {
            $this->toys->assertSellable($data['items']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        /**
         * And the same guard for a beauty counter: the batch FEFO is about to
         * reach for may have gone off, which for cosmetics is a refusal and not a
         * markdown. Checked against the same warehouse the sale will draw from,
         * so the answer is about the stock that will actually leave the shop.
         */
        try {
            $this->cosmetics->assertSellable($data['items'], $data['warehouse_id'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        // Points belong to a named customer. A walk-in sale falls back to the
        // shared WALKIN record, which must never accrue or spend a balance.
        $member = empty($data['customer_id']) ? null : $customer;

        $priced = $this->pricing->price($data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        $total = $priced['header']['total'];
        $tendered = round(array_sum(array_column($data['payments'], 'amount')), 2);

        // Points are quoted against the billed total before any cash lands, so
        // the cap ("points may pay at most 50% of a bill") means what it says.
        try {
            $redemption = $this->loyalty->quoteRedemption($member, (float) $total, (int) ($data['redeem_points'] ?? 0));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['redeem_points' => [$e->getMessage()]]);
        }

        /**
         * Vouchers are quoted against what the bill still owes after the other
         * non-cash tenders, so a card is never drawn down further than it needed
         * to be — a customer who also spent points keeps the balance on the card.
         * Quoted before anything is written, like points, so a dead or duplicated
         * voucher refuses the sale rather than half-completing it.
         */
        try {
            $voucherQuote = $this->vouchers->quote(
                $data['gift_voucher_codes'] ?? [],
                max(0, round($total - $tendered - $redemption['amount'] - $oldGoldTotal, 2)),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['gift_voucher_codes' => [$e->getMessage()]]);
        }

        $paidTotal = round($tendered + $redemption['amount'] + $oldGoldTotal + $voucherQuote['total'], 2);

        if ($paidTotal > $total + 0.005) {
            // Old gold overshooting the bill is a normal jewellery situation,
            // not a mis-keyed tender: the customer brought in more than they
            // spent. Say so, because "payments exceed the total" sends the
            // counter looking for a typo that is not there. The excess stays an
            // open receipt — a credit against their next visit.
            if ($oldGoldTotal > 0 && round($tendered + $redemption['amount'], 2) <= $total + 0.005) {
                throw ValidationException::withMessages(['old_gold_receipt_ids' => [
                    'The selected old gold is worth more than this bill. Leave the larger receipt open as credit, or settle the difference separately.',
                ]]);
            }

            throw ValidationException::withMessages(['payments' => ['Payments exceed the sale total.']]);
        }

        $this->assertWithinCreditLimit($customer, round($total - $paidTotal, 2));

        try {
            $result = DB::transaction(function () use ($request, $data, $customer, $member, $priced, $total, $paidTotal, $redemption, $prescription, $oldGold, $oldGoldTotal, $voucherQuote) {
                [$salesOrder, $invoice] = $this->createSale($request, $data, $customer, $priced, $total);

                // Tie the sale to the authority it was dispensed under, and
                // write the Schedule H1 / X register entries as it happens —
                // a register reconstructed later from invoices is exactly what
                // an inspection is meant to catch.
                if ($prescription) {
                    $salesOrder->forceFill(['prescription_id' => $prescription->id])->save();
                    $this->pharmacy->recordDispense(
                        $prescription,
                        $data['items'],
                        $salesOrder,
                        $invoice,
                        $request->user()?->id,
                    );
                }

                foreach ($data['payments'] as $payment) {
                    $this->tender($invoice, [
                        'amount' => $payment['amount'],
                        'mode' => $payment['mode'],
                        'payment_date' => now()->toDateString(),
                        'created_by' => $request->user()?->id,
                    ]);
                }

                if ($oldGoldTotal > 0) {
                    $numbers = $oldGold->pluck('receipt_number')->implode(', ');
                    $this->tender($invoice, [
                        'amount' => $oldGoldTotal,
                        'mode' => 'old_gold',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Old gold receipt(s) {$numbers}",
                        'created_by' => $request->user()?->id,
                    ]);
                    // Stamping the receipts is what stops the same scrap being
                    // spent against a second bill.
                    OldGoldReceipt::whereIn('id', $oldGold->pluck('id'))->update([
                        'invoice_id' => $invoice->id,
                        'sales_order_id' => $salesOrder->id,
                    ]);
                }

                if ($redemption['points'] > 0) {
                    $this->tender($invoice, [
                        'amount' => $redemption['amount'],
                        'mode' => 'loyalty',
                        'payment_date' => now()->toDateString(),
                        'notes' => "{$redemption['points']} loyalty points redeemed",
                        'created_by' => $request->user()?->id,
                    ]);
                    $this->loyalty->redeemForInvoice($invoice, $redemption['points'], $request->user()?->id);
                }

                /**
                 * Gift vouchers, as a tender line each. One payment row per card
                 * rather than one for the lot: a bill settled with two cards has
                 * to be traceable to both, and the redemption ledger and the
                 * payment ledger must agree card for card if a return is ever to
                 * put the right value back on the right one.
                 */
                foreach ($voucherQuote['lines'] as $line) {
                    $this->tender($invoice, [
                        'amount' => $line['amount'],
                        'mode' => 'voucher',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Gift voucher {$line['voucher']->code}",
                        'created_by' => $request->user()?->id,
                    ]);
                }
                $this->vouchers->spend($voucherQuote['lines'], $invoice, $salesOrder, $request->user()?->id);

                $invoice->paid_amount = $paidTotal;
                $invoice->refreshStatus();

                // Awarded on the billed total, so a customer who paid half in
                // points still earns on the full sale.
                $earned = $member
                    ? $this->loyalty->awardForInvoice($invoice, $request->user()?->id)
                    : null;

                return [$salesOrder, $invoice, $earned];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$salesOrder, $invoice, $earned] = $result;

        return $this->saleResponse($salesOrder, $invoice, $member ? [
            'points_earned' => $earned?->points ?? 0,
            'points_redeemed' => $redemption['points'],
            'points_redeemed_value' => $redemption['amount'],
            'points_balance' => $member->fresh()->loyaltyPoints(),
        ] : null, array_map(static fn (array $line) => [
            'code' => $line['voucher']->code,
            'amount' => $line['amount'],
            'balance' => (float) $line['voucher']->fresh()->balance,
        ], $voucherQuote['lines']));
    }

    /**
     * The price to put on the till for a product.
     *
     * An ornament has no stored selling price — it is worth its metal at
     * today's rate plus making — so a jewellery item is priced here rather than
     * read off the product. Anything else keeps its selling price, and a
     * jewellery item whose purity has no rate quoted yet falls back to it too,
     * with the reason attached so the counter knows why the number looks wrong.
     *
     * @return array<string, mixed>
     */
    private function priceOf(Product $product): array
    {
        if (! $product->isRatePriced()) {
            return ['unit_price' => (float) $product->selling_price];
        }

        try {
            $priced = $this->jewellery->priceProduct($product);
        } catch (RuntimeException $e) {
            return [
                'unit_price' => (float) $product->selling_price,
                'pricing_error' => $e->getMessage(),
            ];
        }

        return [
            'unit_price' => $priced['unit_price'],
            'jewellery' => $priced,
        ];
    }

    /**
     * Which lines in the current cart are scheduled medicine, so the till can
     * ask for the prescription *before* the customer has queued to pay rather
     * than refusing the sale at the last step.
     */
    public function prescriptionCheck(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->pharmacy->inspect($data['items'])]);
    }

    /**
     * Collect the balance of a counter bill online: raise a payment link for it
     * and hand the till a QR the customer scans with any UPI or wallet app.
     *
     * Deliberately after the sale rather than as a tender inside it. The invoice
     * has to exist before it can be paid, and a counter cannot hold a cart open
     * while someone finds their phone — so the sale completes with whatever was
     * tendered and the remaining balance is collected against the invoice, the
     * same way a payment link works anywhere else in the system.
     */
    public function collectOnline(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            // Defaults to the whole balance due — the usual case at a counter.
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        if (! PaymentGatewayAccount::where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'No payment gateway is enabled. Configure one under Finance → Payment Gateways.',
            ], 422);
        }

        $invoice = Invoice::findOrFail($data['invoice_id']);

        // An unpaid balance may already have a live link against it. Reuse it
        // rather than leaving the customer a second QR for the same money — but
        // only when it is for the same money: a counter that asks for a
        // different amount must not be silently handed the old one.
        $existing = PaymentTransaction::where('invoice_id', $invoice->id)
            ->whereIn('status', ['created', 'pending'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        $wanted = isset($data['amount']) ? round((float) $data['amount'], 2) : null;
        $reusable = $existing
            && ($wanted === null || abs((float) $existing->amount - $wanted) < 0.005);

        if ($existing && ! $reusable) {
            // Retire the stale one so there is only ever a single live QR for a
            // bill — two payable links against one invoice is how a customer
            // ends up paying the wrong amount.
            $existing->update(['status' => 'cancelled']);
        }

        $txn = $reusable ? $existing : $this->gateways->createLinkForInvoice($invoice, [
            'amount' => $wanted,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->collectionPayload($txn->refresh())]);
    }

    /**
     * Poll a counter collection. The till calls this every few seconds while the
     * QR is on screen; the transaction itself is moved to paid by the gateway's
     * callback or webhook, so this only ever reads.
     */
    public function collectStatus(string $token)
    {
        $txn = PaymentTransaction::where('public_token', $token)->firstOrFail();

        // A link nobody paid before it lapsed should stop looking payable.
        if ($txn->isExpired() && $txn->status !== 'expired') {
            $txn->update(['status' => 'expired']);
        }

        return response()->json(['data' => $this->collectionPayload($txn)]);
    }

    /** The shape the till renders: the QR, the money, and where it stands. */
    private function collectionPayload(PaymentTransaction $txn): array
    {
        $invoice = $txn->invoice;

        return [
            'token' => $txn->public_token,
            'pay_url' => $txn->payUrl(),
            // Rendered server-side so the counter needs no QR library and the
            // encoded URL cannot drift from the one the link actually resolves.
            'qr' => $this->barcodes->qrDataUri($txn->payUrl(), 320),
            'amount' => (float) $txn->amount,
            'currency' => $txn->currency,
            'status' => $txn->status,
            'is_paid' => $txn->isPaid(),
            'paid_at' => $txn->paid_at?->toIso8601String(),
            'expires_at' => $txn->expires_at?->toIso8601String(),
            'gateway' => $txn->gateway,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'balance_due' => $invoice->balanceDue(),
                'status' => $invoice->status,
            ] : null,
        ];
    }

    /**
     * What the counter may offer this customer on a bill of this value: their
     * balance, the cap, and what redeeming it all is worth. Drives the POS
     * redeem box without making the till re-implement the programme's rules.
     */
    public function loyaltyQuote(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        $max = $this->loyalty->maxRedeemablePoints($customer, (float) $data['amount']);

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'balance' => $customer->loyaltyPoints(),
            'max_redeemable_points' => $max,
            'max_redeemable_value' => $this->loyalty->valueOf($max),
            'will_earn' => $this->loyalty->earnedFor((float) $data['amount']),
            'config' => $this->loyalty->config(),
        ]]);
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
            // Carried so the lookup can offer to collect on a bill that is
            // still short — the same list is used to find a sale to return
            // against and to chase one that went out on credit.
            'balance_due' => $i->balanceDue(),
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
            // The lane handing the money back — which is this shift's drawer,
            // whatever shift the original sale was rung up on.
            'counter_id' => ['nullable', 'exists:pos_counters,id'],
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

        $this->resolveSession(isset($data['counter_id']) ? (int) $data['counter_id'] : null);

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
                $voucherRestored = 0.0;

                if ($refundMode !== 'credit') {
                    /**
                     * Whatever a gift voucher paid for goes back onto the card,
                     * and the money refund is reduced by exactly that much.
                     *
                     * Refunding voucher-paid goods in cash would turn a gift
                     * voucher into a cash withdrawal — which is precisely what no
                     * shop allows, and what a customer would otherwise be able to
                     * do by buying a cheap item with a card and returning it. The
                     * two are one decision, so they sit in one branch: restoring
                     * the card *and* handing over the full cash would refund
                     * twice.
                     *
                     * Not done when the customer keeps store credit: the value is
                     * already sitting on the invoice, and crediting the card as
                     * well would be the same double-refund by another route.
                     */
                    $voucherRestored = $this->vouchers->reverseForInvoice(
                        $invoice,
                        (float) $return->total,
                        $return->credit_note_number,
                        $request->user()?->id,
                    );

                    if ($voucherRestored > 0) {
                        $this->tender($invoice, [
                            'amount' => -$voucherRestored,
                            'mode' => 'voucher',
                            'payment_date' => now()->toDateString(),
                            'notes' => "Gift voucher value restored for credit note {$return->credit_note_number}",
                            'created_by' => $request->user()?->id,
                        ]);
                    }

                    $refund = round((float) $return->total - $voucherRestored, 2);

                    if ($refund > 0) {
                        $this->tender($invoice, [
                            'amount' => -$refund,
                            'mode' => $refundMode,
                            'payment_date' => now()->toDateString(),
                            'notes' => "Refund for credit note {$return->credit_note_number}",
                            'created_by' => $request->user()?->id,
                        ]);
                    }

                    $invoice->paid_amount = round((float) $invoice->paid_amount - $refund - $voucherRestored, 2);
                    $invoice->refreshStatus();
                }

                // Goods going back take their points with them, whether the
                // money was refunded or kept as store credit — the sale is
                // reversed either way.
                $this->loyalty->reverseForInvoice(
                    $invoice,
                    (float) $return->total,
                    $return->credit_note_number,
                    $request->user()?->id,
                );

                return [$return, $refund, $voucherRestored];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$return, $refund, $voucherRestored] = $result;

        return response()->json([
            'data' => [
                'credit_note_number' => $return->credit_note_number,
                'invoice_number' => $invoice->invoice_number,
                'return_total' => (float) $return->total,
                'refund_amount' => $refund,
                'refund_mode' => $refundMode,
                // Reported separately so the receipt can say "₹500 back on your
                // gift card, ₹250 in cash" rather than understating the refund.
                'voucher_restored' => $voucherRestored,
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
            'counter_id' => ['nullable', 'exists:pos_counters,id'],
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
            // Cards put towards the replacement, exactly as on a plain sale.
            'gift_voucher_codes' => ['nullable', 'array', 'max:5'],
            'gift_voucher_codes.*' => ['string', 'max:40'],
        ]);

        $originalInvoice = Invoice::with('salesOrder')->findOrFail($data['original_invoice_id']);
        if (! $originalInvoice->salesOrder) {
            throw ValidationException::withMessages(['original_invoice_id' => ['This invoice has no linked sale to return against.']]);
        }
        $this->assertReturnable($originalInvoice, $data['return_items']);

        /**
         * The replacement half of an exchange is a sale, so it is held to the
         * same rule as one: a toy that may not be sold may not be swapped into a
         * customer's hands either. Only the outgoing lines are checked — what is
         * coming *back* is coming back regardless of its paperwork, and refusing
         * a return would leave the customer holding the very article the shop
         * should not have sold.
         */
        try {
            $this->toys->assertSellable($data['items']);
            // Same rule, same reason: an expired foundation may not be swapped
            // into a customer's hands any more than it may be sold to them.
            $this->cosmetics->assertSellable($data['items'], $data['warehouse_id'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $this->resolveSession(isset($data['counter_id']) ? (int) $data['counter_id'] : null);

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);
        $refundMode = $data['refund_mode'] ?? 'cash';

        $newPriced = $this->pricing->price($data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        $newTotal = $newPriced['header']['total'];

        $returnPriced = $this->pricing->price($data['return_items'], $data['return_discount_type'] ?? null, (float) ($data['return_discount_value'] ?? 0));
        $returnTotal = $returnPriced['header']['total'];

        $appliedCredit = round(min($returnTotal, $newTotal), 2);
        $excessReturn = round($returnTotal - $appliedCredit, 2);
        $paidTotal = round(array_sum(array_column($data['payments'] ?? [], 'amount')), 2);

        // Quoted against what the replacement still owes after the exchange
        // credit and the cash tendered, so a card is never drawn down for value
        // the traded-in goods already covered.
        try {
            $voucherQuote = $this->vouchers->quote(
                $data['gift_voucher_codes'] ?? [],
                max(0, round($newTotal - $paidTotal - $appliedCredit, 2)),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['gift_voucher_codes' => [$e->getMessage()]]);
        }

        $covered = round($paidTotal + $appliedCredit + $voucherQuote['total'], 2);
        if ($covered > $newTotal + 0.005) {
            throw ValidationException::withMessages(['payments' => ['Payments plus the exchange credit exceed the new sale total.']]);
        }

        $this->assertWithinCreditLimit($customer, round($newTotal - $covered, 2));

        try {
            $result = DB::transaction(function () use ($request, $data, $originalInvoice, $customer, $newPriced, $newTotal, $appliedCredit, $excessReturn, $paidTotal, $refundMode, $voucherQuote) {
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

                /**
                 * Only the *excess* can launder a gift voucher into cash — the
                 * applied part never leaves the shop, it moves straight into the
                 * replacement — so the voucher restore is scoped to the excess
                 * and the money handed over is reduced by whatever went back onto
                 * the card. Same rule as a plain return, applied to the only part
                 * of an exchange that behaves like one.
                 */
                $voucherRestored = 0.0;
                if ($excessCashRefund > 0) {
                    $voucherRestored = $this->vouchers->reverseForInvoice(
                        $originalInvoice,
                        $excessCashRefund,
                        $return->credit_note_number,
                        $request->user()?->id,
                    );

                    if ($voucherRestored > 0) {
                        $this->tender($originalInvoice, [
                            'amount' => -$voucherRestored,
                            'mode' => 'voucher',
                            'payment_date' => now()->toDateString(),
                            'notes' => "Gift voucher value restored for credit note {$return->credit_note_number}",
                            'created_by' => $request->user()?->id,
                        ]);
                    }

                    $excessCashRefund = round($excessCashRefund - $voucherRestored, 2);
                }

                $consume = round($appliedCredit + $excessCashRefund, 2);
                if ($consume > 0) {
                    $this->tender($originalInvoice, [
                        'amount' => -$consume,
                        'mode' => 'exchange',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Exchange against credit note {$return->credit_note_number}",
                        'created_by' => $request->user()?->id,
                    ]);
                }

                if ($consume > 0 || $voucherRestored > 0) {
                    $originalInvoice->paid_amount = round(
                        (float) $originalInvoice->paid_amount - $consume - $voucherRestored,
                        2,
                    );
                    $originalInvoice->refreshStatus();
                }

                // 2. Ring up the new sale.
                [$salesOrder, $invoice] = $this->createSale($request, $data, $customer, $newPriced, $newTotal);

                foreach ($data['payments'] ?? [] as $payment) {
                    $this->tender($invoice, [
                        'amount' => $payment['amount'],
                        'mode' => $payment['mode'],
                        'payment_date' => now()->toDateString(),
                        'created_by' => $request->user()?->id,
                    ]);
                }

                if ($appliedCredit > 0) {
                    $this->tender($invoice, [
                        'amount' => $appliedCredit,
                        'mode' => 'exchange',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Exchange credit from credit note {$return->credit_note_number}",
                        'created_by' => $request->user()?->id,
                    ]);
                }

                // Cards put towards the replacement, one tender line each — the
                // same treatment they get on a plain sale.
                foreach ($voucherQuote['lines'] as $line) {
                    $this->tender($invoice, [
                        'amount' => $line['amount'],
                        'mode' => 'voucher',
                        'payment_date' => now()->toDateString(),
                        'notes' => "Gift voucher {$line['voucher']->code}",
                        'created_by' => $request->user()?->id,
                    ]);
                }
                $this->vouchers->spend($voucherQuote['lines'], $invoice, $salesOrder, $request->user()?->id);

                $invoice->paid_amount = round($paidTotal + $appliedCredit + $voucherQuote['total'], 2);
                $invoice->refreshStatus();

                // Both legs move points, and both have to: reversing the return
                // without awarding the replacement would quietly cost a customer
                // points for swapping a shirt for the same shirt in another size.
                $this->loyalty->reverseForInvoice(
                    $originalInvoice,
                    (float) $return->total,
                    $return->credit_note_number,
                    $request->user()?->id,
                );

                if (! empty($data['customer_id'])) {
                    $this->loyalty->awardForInvoice($invoice, $request->user()?->id);
                }

                return [$return, $salesOrder, $invoice, $voucherRestored];
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        [$return, $salesOrder, $invoice, $voucherRestored] = $result;

        return $this->exchangeResponse($return, $salesOrder, $invoice, $returnTotal, $newTotal, $refundMode, $voucherRestored);
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

    /**
     * Guard the counter against a sale the party is not good for.
     *
     * Only the unpaid part of the tender is at stake, so a fully paid bill is
     * never refused however deep in arrears the party is — cash is cash. Beyond
     * that the decision belongs to CreditControlService, which is also what the
     * order desk and the despatch door ask, so a party on supply hold cannot walk
     * up to the counter and be served instead.
     */
    private function assertWithinCreditLimit(Customer $customer, float $shortfall): void
    {
        if ($shortfall <= 0) {
            return;
        }

        if ($reason = app(CreditControlService::class)->blockReason($customer, $shortfall)) {
            throw ValidationException::withMessages(['payments' => [$reason]]);
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
            // Both stamps, not just the session: "what did lane 4 take this
            // month" is asked far more often than "what happened on shift 812",
            // and it should not need a join through every session the lane ran.
            'pos_counter_id' => $this->session?->counter_id,
            'pos_session_id' => $this->session?->id,
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

        /**
         * The ornaments in this sale, loaded once. A jewellery line has to
         * record the rate and weights it was billed at — see the snapshot note
         * below — and doing that per line would be a query per ring.
         */
        $ornaments = $this->jewellery->snapshotsForLines($priced['lines']);

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
                // Jewellery: metal rate, weights, making, stones and hallmark as
                // they stood at the moment of sale. Spread rather than listed so
                // a non-jewellery line adds nothing at all.
                ...($ornaments[$line['product_id']] ?? []),
            ]);
        }

        /**
         * Stock moves in its own pass, over the *expanded* lines rather than the
         * billed ones. The bill still says "1 × Diwali Hamper" — that is what the
         * customer bought, what GST reports and what a return is raised against —
         * while the shelf loses the three things actually handed over. For a
         * workspace with no combo packs (every workspace that never bought
         * Grocery) this is the same list it always was, in the same order.
         */
        foreach ($this->comboPacks->stockLines($priced['lines']) as $line) {
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
        }

        /**
         * Restocking runs over the expanded lines, for the same reason the sale's
         * outward does — and because it must: a hamper that went out as three
         * items has to come back as three items, or the shelf never recovers what
         * the sale took off it. This is the return half of the pairing every
         * counter-side grant needs (see also the gift-voucher restore below and
         * the loyalty reversal); `exchange` gets it for free by calling this
         * method and createSale rather than posting stock itself.
         */
        foreach ($this->comboPacks->stockLines($priced['lines']) as $line) {
            $this->stock->inward(
                warehouseId: $return->warehouse_id,
                productId: $line['product_id'],
                variantId: $line['variant_id'],
                quantity: (float) $line['quantity'],
                unitCost: (float) $line['unit_cost'],
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

    /**
     * Price the ornaments on a grid page off today's rate card.
     *
     * The grid hands the till whole product rows, and an ornament's
     * `selling_price` is meaningless — usually zero. Without this, tapping a
     * ring in the grid adds it to the cart for nothing while scanning the same
     * ring's tag prices it correctly, which is a difference no counter should
     * have to know about.
     *
     * Costs nothing for a shop with no metal: the filter empties and the method
     * returns before touching the rate card.
     */
    private function attachRatePricing($products): void
    {
        $ornaments = $products->filter(fn (Product $p) => $p->isRatePriced());

        if ($ornaments->isEmpty()) {
            return;
        }

        // One query for every stone on the page, and the rate card is memoised
        // inside the pricing service — so a hundred ornaments cost two queries
        // and not two hundred.
        $ornaments->load('stones');

        foreach ($ornaments as $product) {
            foreach ($this->priceOf($product) as $key => $value) {
                $product->setAttribute($key, $value);
            }
        }
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

        /**
         * A combo pack has no stock of its own — nothing on the shelf is called
         * "Diwali Hamper" until somebody makes one up — so without this every
         * pack reads as out of stock on a grid where the counter can plainly sell
         * one. What it can sell is however many its scarcest component allows.
         *
         * Costs one extra query, and only when the tenant actually has packs.
         */
        $buildable = $this->comboPacks->buildableQuantities(
            $products->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $warehouseId,
        );

        if ($buildable !== []) {
            $products->each(function ($p) use ($buildable) {
                if (array_key_exists((int) $p->id, $buildable)) {
                    $p->available_qty = $buildable[(int) $p->id];
                    // Flagged so the grid can badge it: "3 available" on a pack
                    // means three makeable, not three sitting on a shelf.
                    $p->is_combo_pack = true;
                }
            });
        }
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

    /** @param  array<string, mixed>|null  $loyalty  Points movement to print on the receipt. */
    /** @param  list<array{code: string, amount: float, balance: float}>  $vouchers */
    private function saleResponse(SalesOrder $salesOrder, Invoice $invoice, ?array $loyalty = null, array $vouchers = [])
    {
        return (new SalesOrderResource($salesOrder->load(['customer', 'warehouse', 'items.product'])))
            ->additional(array_filter([
                'invoice' => $this->invoiceSummary($invoice),
                'loyalty' => $loyalty,
                // What is left on each card, so the receipt can print it. A
                // customer handed back a card with no balance on it deserves to
                // be told, and the alternative is them finding out next visit.
                'vouchers' => $vouchers,
            ]))
            ->response()
            ->setStatusCode(201);
    }

    private function exchangeResponse(SalesReturn $return, SalesOrder $salesOrder, Invoice $invoice, float $returnTotal, float $newTotal, string $refundMode, float $voucherRestored = 0.0)
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
                    // Part of net_refund that went back onto a gift card rather
                    // than across the counter, so the slip can say which.
                    'voucher_restored' => $voucherRestored,
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
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'balance_due' => $invoice->balanceDue(),
            'status' => $invoice->status,
        ];
    }
}
