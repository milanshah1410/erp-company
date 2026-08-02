<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Services;

use App\Models\Invoice;
use App\Models\PosCounter;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Modules\Industry\Grocery\Models\SelfCheckoutCart;
use App\Modules\Industry\Grocery\Models\SelfCheckoutCartItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The unattended lane.
 *
 * What this service does *not* do is the most important thing about it: it never
 * creates a sale. The cart is a staging basket — scanned into by the customer,
 * cleared by an attendant if it needs clearing, and then rung up through the
 * ordinary `POST /pos/checkout` like every other bill in the shop, with the
 * resulting invoice recorded back onto the cart via `complete()`.
 *
 * That is the trial room's split and it is here for the same reason. A second
 * path to a sale would price, tax, post stock, redeem vouchers and award points
 * *nearly* the same as the first one, and would drift from it release by release
 * — and the copy that gets exercised least is the one that rots. So: one way to
 * turn goods into money, and this is a way of building a basket.
 *
 * **Authentication.** A self-checkout lane is a store-owned device signed in as a
 * store user, not an anonymous endpoint. That is deliberate — a public route that
 * could build a basket and take a payment would be a hole with a shop's stock
 * behind it. What makes the lane "unattended" is the *permission* the lane's login
 * holds: it can scan (`self-checkout.create`) but not approve
 * (`self-checkout.approve`), so a restricted line parks the basket and the
 * supervisor clears it from their own screen under their own credentials.
 */
class SelfCheckoutService
{
    /** Start a basket at a lane. */
    public function start(PosCounter $counter, ?int $customerId = null): SelfCheckoutCart
    {
        if (! $counter->is_active) {
            throw new RuntimeException("Lane {$counter->name} is not in service.");
        }

        if (! $counter->isSelfCheckout()) {
            throw new RuntimeException("{$counter->name} is an attended counter, not a self-checkout lane.");
        }

        /**
         * The drawer has to be open even though nobody stands at the lane. A
         * self-checkout bank still reconciles — cash goes into a machine, cards
         * settle against a terminal — and a basket billed against a lane with no
         * session would have its money attributed to nothing.
         */
        if (! $counter->sessions()->where('status', 'open')->exists()) {
            throw new RuntimeException("Lane {$counter->name} has not been opened for the shift.");
        }

        return SelfCheckoutCart::create([
            'counter_id' => $counter->id,
            'cart_number' => $this->nextNumber(),
            'status' => 'scanning',
            'customer_id' => $customerId,
            'warehouse_id' => $counter->warehouse_id,
        ]);
    }

    /**
     * A scan.
     *
     * Re-scanning the same line increments it rather than adding a second row,
     * because that is what a shopper putting a second tin in the bagging area
     * means and a receipt that lists it twice invites an argument.
     */
    public function scan(SelfCheckoutCart $cart, string $code, float $quantity = 1): SelfCheckoutCartItem
    {
        $this->assertScannable($cart);

        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        [$product, $variant] = $this->resolve($code);

        /**
         * Refused at the lane rather than at payment. An unattended lane has no
         * one to explain a shortfall to, and a basket that fails at the end after
         * forty scans is worse for the queue than a beep on the item that is not
         * there.
         */
        $available = $this->available($cart, $product->id, $variant?->id);
        $alreadyInCart = (float) $cart->liveItems()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->sum('quantity');

        if ($available < $alreadyInCart + $quantity) {
            throw new RuntimeException("{$product->name} is out of stock. Please ask an assistant.");
        }

        $reason = $this->approvalReason($product);

        $line = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'is_voided' => false,
        ]);

        $line->quantity = round((float) ($line->quantity ?? 0) + $quantity, 3);
        $line->unit_price = (float) ($variant?->selling_price ?: $product->selling_price);
        $line->tax_percent = (float) $product->tax_percent;
        $line->needs_approval = $reason !== null;
        $line->approval_reason = $reason;
        $line->save();

        // Unconditionally, not only when this line flagged. A basket the
        // supervisor has just cleared sits at `ready`, and putting one more
        // biscuit in it has to take it back to `scanning` — otherwise the board
        // shows a basket as cleared while the customer is still shopping.
        $this->refreshStatus($cart);

        return $line;
    }

    /**
     * Take a line back out.
     *
     * Voided, not deleted, and it is a supervisor act rather than a customer one
     * — anybody who can silently unscan an item can put it in their bag and walk
     * out with it. The struck-through row is what makes that reviewable.
     */
    public function void(SelfCheckoutCart $cart, SelfCheckoutCartItem $item, ?int $userId): SelfCheckoutCartItem
    {
        $this->assertScannable($cart);

        if ($item->cart_id !== $cart->id) {
            throw new RuntimeException('That line is not on this basket.');
        }

        $item->forceFill(['is_voided' => true, 'needs_approval' => false])->save();

        $cart->forceFill(['approved_by' => $userId])->save();
        $this->refreshStatus($cart);

        return $item;
    }

    /**
     * The attendant clears the basket.
     *
     * Clears *every* flagged line at once rather than one at a time: the
     * supervisor has walked to the lane and looked at the customer, which is the
     * check being performed, and making them tap four times for four bottles adds
     * nothing but queue.
     */
    public function approve(SelfCheckoutCart $cart, ?int $userId): SelfCheckoutCart
    {
        if (! $cart->isLive()) {
            throw new RuntimeException('This basket has already been settled.');
        }

        $cart->items()->where('needs_approval', true)->update([
            'needs_approval' => false,
        ]);

        /**
         * `ready`, not back to `scanning`. The supervisor has walked over and
         * looked at the customer, which is a decision about this basket — the
         * board must show it as cleared and waiting to pay, or the next
         * supervisor along has no way to tell it apart from one nobody has
         * looked at yet. A further scan demotes it again, via refreshStatus.
         */
        $cart->forceFill([
            'status' => 'ready',
            'approved_by' => $userId,
            'approved_at' => now(),
        ])->save();

        return $cart->fresh(['items']);
    }

    /**
     * Record the sale the till made from this basket.
     *
     * Called *after* `/pos/checkout` has billed it. The cart does not create the
     * sale, and this deliberately takes an invoice that already exists rather
     * than the makings of one — the moment this method could construct a sale
     * itself is the moment there are two checkouts to keep in step.
     */
    public function complete(SelfCheckoutCart $cart, Invoice $invoice): SelfCheckoutCart
    {
        if ($cart->status === 'completed') {
            throw new RuntimeException('This basket has already been billed.');
        }

        if ($cart->items()->where('needs_approval', true)->exists()) {
            throw new RuntimeException('This basket is still waiting for an assistant.');
        }

        return DB::transaction(function () use ($cart, $invoice) {
            $cart->forceFill([
                'status' => 'completed',
                'invoice_id' => $invoice->id,
                'sales_order_id' => $invoice->sales_order_id,
                'customer_id' => $cart->customer_id ?? $invoice->customer_id,
                'completed_at' => now(),
            ])->save();

            return $cart->fresh(['items', 'invoice']);
        });
    }

    /** Walked away from, or cleared down by staff at the end of a shift. */
    public function abandon(SelfCheckoutCart $cart, ?string $notes = null): SelfCheckoutCart
    {
        if ($cart->status === 'completed') {
            throw new RuntimeException('A billed basket cannot be abandoned.');
        }

        $cart->forceFill([
            'status' => 'abandoned',
            'notes' => $notes ?: $cart->notes,
        ])->save();

        return $cart;
    }

    /**
     * The basket as the lane screen and the supervisor board both want it.
     *
     * @return array<string, mixed>
     */
    public function present(SelfCheckoutCart $cart): array
    {
        $cart->loadMissing(['items.product:id,sku,name,barcode', 'items.variant:id,sku', 'counter:id,code,name,mode', 'approvedBy:id,name']);

        $lines = $cart->items->map(fn (SelfCheckoutCartItem $i) => [
            'id' => (int) $i->id,
            'product_id' => (int) $i->product_id,
            'variant_id' => $i->variant_id ? (int) $i->variant_id : null,
            'sku' => $i->variant?->sku ?? $i->product?->sku,
            'name' => $i->product?->name,
            'barcode' => $i->product?->barcode,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'tax_percent' => (float) $i->tax_percent,
            'line_total' => $i->lineTotal(),
            'needs_approval' => (bool) $i->needs_approval,
            'approval_reason' => $i->approval_reason,
            'is_voided' => (bool) $i->is_voided,
        ]);

        $live = $lines->where('is_voided', false);

        return [
            'id' => (int) $cart->id,
            'cart_number' => $cart->cart_number,
            'status' => $cart->status,
            'counter' => $cart->counter ? [
                'id' => (int) $cart->counter->id,
                'code' => $cart->counter->code,
                'name' => $cart->counter->name,
            ] : null,
            'warehouse_id' => (int) $cart->warehouse_id,
            'customer_id' => $cart->customer_id ? (int) $cart->customer_id : null,
            'items' => $lines->values()->all(),
            'item_count' => $live->count(),
            'units' => round((float) $live->sum('quantity'), 3),
            // Indicative only — the till re-prices the basket when it bills it.
            // Named so nobody mistakes it for the invoice total.
            'estimated_total' => round((float) $live->sum('line_total'), 2),
            'pending_approvals' => $live->where('needs_approval', true)->count(),
            'approved_by' => $cart->approvedBy?->name,
            'approved_at' => $cart->approved_at?->toDateTimeString(),
            'invoice_id' => $cart->invoice_id ? (int) $cart->invoice_id : null,
            'completed_at' => $cart->completed_at?->toDateTimeString(),
            /**
             * The payload the till posts to /pos/checkout. Assembled here so the
             * lane screen does not have to know the shape of a POS cart — and so
             * that when the checkout contract changes, it changes in one place.
             */
            'checkout_payload' => [
                'warehouse_id' => (int) $cart->warehouse_id,
                'counter_id' => (int) $cart->counter_id,
                'customer_id' => $cart->customer_id ? (int) $cart->customer_id : null,
                'notes' => "Self-checkout {$cart->cart_number}",
                'items' => $live->map(static fn (array $l) => [
                    'product_id' => $l['product_id'],
                    'variant_id' => $l['variant_id'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'],
                    'tax_percent' => $l['tax_percent'],
                ])->values()->all(),
            ],
        ];
    }

    // --- internals -------------------------------------------------------------

    /**
     * Why a human has to look at this line, or null if nobody does.
     *
     * One reason today, and it is the honest one: age restriction is the only
     * thing an unattended lane can decide it is not competent to sell. Kept as a
     * method returning a reason string rather than a boolean so the next reason —
     * a loose-weight item, a manager's markdown — has somewhere to go without
     * changing the shape of anything.
     */
    private function approvalReason(Product $product): ?string
    {
        if ($product->is_age_restricted) {
            return 'Age-restricted item — assistant must verify';
        }

        return null;
    }

    /**
     * Move the basket between scanning and waiting, whenever a line changes.
     *
     * Never sets `ready` — that state means "a supervisor cleared this", which
     * only `approve()` can know. A basket with nothing flagged is simply still
     * being scanned.
     */
    private function refreshStatus(SelfCheckoutCart $cart): void
    {
        if (! $cart->isLive()) {
            return;
        }

        $pending = $cart->items()->where('is_voided', false)->where('needs_approval', true)->exists();

        $cart->forceFill(['status' => $pending ? 'awaiting_approval' : 'scanning'])->save();
    }

    private function assertScannable(SelfCheckoutCart $cart): void
    {
        if (! $cart->isLive()) {
            throw new RuntimeException('This basket has already been settled.');
        }
    }

    /**
     * Barcode, SKU or RFID tag to a sellable line.
     *
     * Products first, then variants — the same order PosController resolves in,
     * so a code that scans one thing at the manned till cannot scan a different
     * thing at the lane.
     *
     * @return array{0: Product, 1: ProductVariant|null}
     */
    private function resolve(string $code): array
    {
        $code = trim($code);

        $product = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('rfid_tag', $code))
            ->first();

        if ($product) {
            return [$product, null];
        }

        $variant = ProductVariant::query()
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->with('product')
            ->first();

        if ($variant && $variant->product && $variant->product->is_active) {
            return [$variant->product, $variant];
        }

        throw new RuntimeException('Item not recognised. Please ask an assistant.');
    }

    private function available(SelfCheckoutCart $cart, int $productId, ?int $variantId): float
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $cart->warehouse_id)
            ->where('product_id', $productId)
            ->when(
                $variantId === null,
                static fn ($q) => $q->whereNull('variant_id'),
                static fn ($q) => $q->where('variant_id', $variantId),
            )
            ->first();

        return round((float) ($stock->quantity ?? 0) - (float) ($stock->reserved_quantity ?? 0), 3);
    }

    /**
     * SC-000123. Its own sequence rather than a document number: a basket is not
     * a document and most of them never become one, so putting abandoned baskets
     * through the shop's numbering would leave gaps in a series a book-keeper
     * reads as missing bills.
     */
    private function nextNumber(): string
    {
        $last = SelfCheckoutCart::query()->orderByDesc('id')->value('cart_number');
        $next = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'SC-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
