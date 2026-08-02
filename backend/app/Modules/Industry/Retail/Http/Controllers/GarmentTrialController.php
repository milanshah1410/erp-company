<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Industry\Retail\Models\GarmentTrial;
use App\Modules\Industry\Retail\Models\GarmentTrialItem;
use App\Modules\Industry\Retail\Services\TrialService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The trial room — garments out of the shop and not yet paid for.
 *
 * Every screen here answers a custody question, so the list leads with what is
 * overdue and the summary is denominated in money at risk rather than in row
 * counts: "₹18,400 out, ₹4,100 of it late" is the sentence the owner wants, and
 * "7 open trials" is not.
 *
 * Note what is *not* here: creating the sale. A garment kept off a trial is
 * billed at the counter like anything else, and the trial's `link-invoice`
 * endpoint records which bill settled it. Re-implementing checkout so this
 * screen could own it would produce a second, worse till.
 */
class GarmentTrialController extends Controller
{
    public function __construct(private readonly TrialService $trials) {}

    public function index(Request $request)
    {
        $query = GarmentTrial::query()->with([
            'customer:id,name,phone', 'warehouse:id,name', 'items',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('trial_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'customer_id', 'warehouse_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The chase list. Computed in SQL rather than filtered in PHP after
        // paging, or page 2 would quietly hold overdue trials the counter never
        // sees.
        if ($request->boolean('overdue')) {
            $query->where('status', 'open')->whereDate('due_at', '<', now()->toDateString());
        }

        /**
         * Open trials first and oldest-due at the top, because this list is
         * worked rather than browsed: what is late is what needs a phone call.
         * Closed ones fall below in reverse date order like any other archive.
         */
        $query->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('due_at')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GarmentTrial $t) => $this->row($t))->toArray()
                + ['summary' => $this->summary()],
        );
    }

    public function show(GarmentTrial $trial)
    {
        return response()->json(['data' => $this->row($this->loaded($trial), detailed: true)]);
    }

    /** Issue garments on trial: create the slip and reserve every line. */
    public function store(Request $request)
    {
        $data = $request->validate([
            /**
             * Required, unlike almost every other customer_id at this counter.
             * A trial is goods leaving on somebody's word, and "walk-in" is not
             * a person you can telephone on Thursday.
             */
            'customer_id' => ['required', 'exists:customers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'due_at' => ['required', 'date'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_mode' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $trial = GarmentTrial::create([
            'trial_number' => GarmentTrial::nextNumber(),
            'customer_id' => $data['customer_id'],
            'warehouse_id' => $data['warehouse_id'],
            'issued_at' => now(),
            'due_at' => $data['due_at'],
            'status' => 'open',
            'deposit_amount' => $data['deposit_amount'] ?? 0,
            'deposit_mode' => $data['deposit_mode'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        try {
            $trial = $this->trials->issue($trial, $data['items']);
        } catch (RuntimeException $e) {
            // The slip is not left half-built: nothing was reserved if the issue
            // refused, so the empty header goes with it.
            $trial->delete();

            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->row($this->loaded($trial), detailed: true)], 201);
    }

    /**
     * Settle lines: some came back, some were kept.
     *
     * Sits on edit rather than create — it is working an existing slip — and is
     * additive, so a trial settled over two visits behaves like one settled in
     * a single visit.
     */
    public function settle(Request $request, GarmentTrial $trial)
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.returned_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sold_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        // Ids are checked against *this* trial rather than the table, so a
        // mis-sent id cannot settle a line on somebody else's slip.
        $owned = $trial->items()->pluck('id')->all();
        foreach ($data['lines'] as $line) {
            if (! in_array((int) $line['item_id'], $owned, true)) {
                throw ValidationException::withMessages([
                    'lines' => ['One of those lines is not on this trial.'],
                ]);
            }
        }

        try {
            $trial = $this->trials->settle($trial, $data['lines']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['lines' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->row($this->loaded($trial), detailed: true)]);
    }

    /** Record the bill the kept garments were rung up on. */
    public function linkInvoice(Request $request, GarmentTrial $trial)
    {
        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);

        $invoice = Invoice::findOrFail($data['invoice_id']);

        // A trial belongs to a named customer; attaching a bill made out to
        // somebody else would make the record say something untrue.
        if ($invoice->customer_id && (int) $invoice->customer_id !== (int) $trial->customer_id) {
            throw ValidationException::withMessages([
                'invoice_id' => ['That invoice is billed to a different customer.'],
            ]);
        }

        $trial = $this->trials->linkInvoice($trial, $invoice->id);

        return response()->json(['data' => $this->row($this->loaded($trial), detailed: true)]);
    }

    /** Abandon a slip — mis-keyed, or the customer changed their mind at the door. */
    public function cancel(Request $request, GarmentTrial $trial)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $trial = $this->trials->cancel($trial, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->row($this->loaded($trial), detailed: true)]);
    }

    public function destroy(GarmentTrial $trial)
    {
        /**
         * A slip with goods still out cannot simply be deleted: the reservation
         * would be stranded and the shop would silently believe it has stock it
         * cannot find. Cancelling releases it and leaves the record; that is the
         * supported way out.
         */
        if ($trial->isOpen()) {
            return response()->json([
                'message' => 'This trial still has garments out. Cancel it instead — that releases the stock.',
            ], 422);
        }

        $trial->delete();

        return response()->json(['message' => 'Trial removed.']);
    }

    private function loaded(GarmentTrial $trial): GarmentTrial
    {
        return $trial->load([
            'customer:id,name,phone', 'warehouse:id,name', 'invoice:id,invoice_number,total',
            'items.product:id,sku,name', 'items.variant:id,sku,name',
        ]);
    }

    /**
     * The trial book at a glance — in money, because that is the exposure.
     * `conversion` is the number the shop is actually managing: what share of
     * trials end in a sale.
     */
    private function summary(): array
    {
        $open = GarmentTrial::with('items')->where('status', 'open')->get();
        $settled = GarmentTrial::whereIn('status', ['closed', 'converted'])->count();
        $converted = GarmentTrial::where('status', 'converted')->count();

        return [
            'open' => $open->count(),
            'overdue' => $open->filter(fn (GarmentTrial $t) => $t->isOverdue())->count(),
            'value_out' => round((float) $open->sum(fn (GarmentTrial $t) => $t->outstandingValue()), 2),
            'value_overdue' => round((float) $open
                ->filter(fn (GarmentTrial $t) => $t->isOverdue())
                ->sum(fn (GarmentTrial $t) => $t->outstandingValue()), 2),
            'converted' => $converted,
            'conversion_percent' => $settled > 0 ? round($converted / $settled * 100, 1) : 0.0,
        ];
    }

    private function row(GarmentTrial $t, bool $detailed = false): array
    {
        $base = [
            'id' => $t->id,
            'trial_number' => $t->trial_number,
            'customer_id' => $t->customer_id,
            'customer_name' => $t->customer?->name,
            'customer_phone' => $t->customer?->phone,
            'warehouse_id' => $t->warehouse_id,
            'warehouse_name' => $t->warehouse?->name,
            'issued_at' => $t->issued_at?->toIso8601String(),
            'due_at' => $t->due_at?->toDateString(),
            'status' => $t->status,
            // Derived, not stored: a trial that fell due at midnight is overdue
            // at nine the next morning whether or not a sweep has run.
            'is_overdue' => $t->isOverdue(),
            'pieces' => round((float) $t->items->sum('quantity'), 3),
            'outstanding_quantity' => $t->outstandingQuantity(),
            'outstanding_value' => $t->outstandingValue(),
            'deposit_amount' => (float) $t->deposit_amount,
            'invoice_id' => $t->invoice_id,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'deposit_mode' => $t->deposit_mode,
            'deposit_refunded' => (bool) $t->deposit_refunded,
            'closed_at' => $t->closed_at?->toIso8601String(),
            'invoice_number' => $t->invoice?->invoice_number,
            'notes' => $t->notes,
            'items' => $t->items->map(fn (GarmentTrialItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'sku' => $i->variant?->sku ?? $i->product?->sku,
                'variant_id' => $i->variant_id,
                'variant_name' => $i->variant?->name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'returned_quantity' => (float) $i->returned_quantity,
                'sold_quantity' => (float) $i->sold_quantity,
                'outstanding_quantity' => $i->outstandingQuantity(),
                'remarks' => $i->remarks,
            ])->values(),
        ];
    }
}
