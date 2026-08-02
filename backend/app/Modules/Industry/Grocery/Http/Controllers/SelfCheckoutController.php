<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PosCounter;
use App\Modules\Industry\Grocery\Models\SelfCheckoutCart;
use App\Modules\Industry\Grocery\Models\SelfCheckoutCartItem;
use App\Modules\Industry\Grocery\Services\SelfCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The unattended lane, and the supervisor screen that watches over it.
 *
 * Two very different callers on one controller, separated by permission rather
 * than by route prefix:
 *
 *  - The **lane** holds `self-checkout.create` and can start a basket and scan
 *    into it. It cannot approve, which is what makes it unattended: a
 *    restricted item parks the basket and stops.
 *  - The **supervisor** holds `self-checkout.approve`, watches `board()` and
 *    clears baskets from their own screen under their own credentials.
 *
 * The lane never bills. `complete()` records an invoice that `/pos/checkout` has
 * already raised — see SelfCheckoutService for why there is exactly one path from
 * goods to money.
 */
class SelfCheckoutController extends Controller
{
    public function __construct(private readonly SelfCheckoutService $lanes) {}

    /**
     * The supervisor board: every live basket across the bank of lanes, the ones
     * waiting for a human first.
     *
     * Ordering is the whole value of the screen — a supervisor covering six lanes
     * needs the one that has stopped, not the one that is quietly scanning.
     */
    public function board(Request $request)
    {
        $carts = SelfCheckoutCart::query()
            ->when($request->filled('counter_id'), fn ($q) => $q->where('counter_id', $request->integer('counter_id')))
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->query('status')),
                fn ($q) => $q->whereIn('status', ['scanning', 'awaiting_approval', 'ready']),
            )
            ->with(['items.product:id,sku,name,barcode', 'items.variant:id,sku', 'counter:id,code,name,mode', 'approvedBy:id,name'])
            ->orderByRaw("FIELD(status, 'awaiting_approval', 'ready', 'scanning')")
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $rows = $carts->map(fn (SelfCheckoutCart $c) => $this->lanes->present($c));

        return response()->json([
            'data' => $rows,
            'summary' => [
                'live' => $rows->count(),
                'awaiting_approval' => $rows->where('status', 'awaiting_approval')->count(),
                'baskets_value' => round((float) $rows->sum('estimated_total'), 2),
            ],
        ]);
    }

    public function show(SelfCheckoutCart $selfCheckoutCart)
    {
        return response()->json(['data' => $this->lanes->present($selfCheckoutCart)]);
    }

    /** Start a basket at a lane. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'counter_id' => ['required', 'exists:pos_counters,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
        ]);

        $counter = PosCounter::findOrFail($data['counter_id']);

        try {
            $cart = $this->lanes->start($counter, isset($data['customer_id']) ? (int) $data['customer_id'] : null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['counter_id' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->lanes->present($cart)], 201);
    }

    /**
     * A scan.
     *
     * Returns the whole basket rather than just the line, because the lane screen
     * repaints the running total and the "waiting for an assistant" banner on
     * every beep, and a second round-trip to find out whether the basket just
     * stopped would show the customer a stale screen.
     */
    public function scan(Request $request, SelfCheckoutCart $selfCheckoutCart)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        try {
            $this->lanes->scan($selfCheckoutCart, $data['code'], (float) ($data['quantity'] ?? 1));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->lanes->present($selfCheckoutCart->fresh())]);
    }

    /** Take a line back out. A supervisor act — see SelfCheckoutService::void. */
    public function void(Request $request, SelfCheckoutCart $selfCheckoutCart, SelfCheckoutCartItem $item)
    {
        try {
            $this->lanes->void($selfCheckoutCart, $item, $request->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Line removed.',
            'data' => $this->lanes->present($selfCheckoutCart->fresh()),
        ]);
    }

    /** The attendant clears every flagged line at once. */
    public function approve(Request $request, SelfCheckoutCart $selfCheckoutCart)
    {
        try {
            $cart = $this->lanes->approve($selfCheckoutCart, $request->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['cart' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Basket approved.',
            'data' => $this->lanes->present($cart),
        ]);
    }

    /**
     * Record the sale the till made from this basket.
     *
     * Gated on `sales.create`, not on a self-checkout permission: this is the
     * step that ties a basket to money, and the authority for it is the authority
     * to ring up a sale.
     */
    public function complete(Request $request, SelfCheckoutCart $selfCheckoutCart)
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
        ]);

        $invoice = Invoice::findOrFail($data['invoice_id']);

        try {
            $cart = $this->lanes->complete($selfCheckoutCart, $invoice);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['invoice_id' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Basket billed.',
            'data' => $this->lanes->present($cart),
        ]);
    }

    /** Walked away from, or cleared down at the end of a shift. */
    public function abandon(Request $request, SelfCheckoutCart $selfCheckoutCart)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:255']]);

        try {
            $cart = $this->lanes->abandon($selfCheckoutCart, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['cart' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Basket cleared.',
            'data' => $this->lanes->present($cart),
        ]);
    }
}
