<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductWarrantyRequest;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductWarranty;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The retail warranty register.
 *
 * It exists to answer one question, asked across a counter by someone holding a
 * six-month-old shoe and a strong opinion: is this still covered? Everything
 * here is arranged around that. `check` is a first-class endpoint rather than
 * something the caller assembles from the list, and it returns the *reason* a
 * card does not apply, because "it ran out on 12 March" ends the conversation
 * and "no cover found" starts a different one.
 *
 * Cards are issued from an invoice rather than minted automatically on every
 * sale. A shop selling four hundred pairs a week does not want four hundred rows
 * it will never read; it wants the ones a customer asked to have registered.
 * `issuable` lists what an invoice *could* register — its lines whose article
 * carries a warranty policy, minus anything already registered — and `issue`
 * writes the chosen ones.
 */
class ProductWarrantyController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductWarranty::query()->with([
            'customer:id,name,phone',
            'product:id,sku,name',
            'variant:id,sku,name',
            'invoice:id,invoice_number,invoice_date',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('warranty_number', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'source', 'customer_id', 'product_id', 'invoice_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Cover about to run out. Two trades read this list for opposite
        // reasons: one chases the customer to sell an extension, the other
        // finishes a repair before the term closes.
        if ($request->boolean('expiring')) {
            $query->where('status', 'active')
                ->whereBetween('end_date', [
                    now()->toDateString(),
                    now()->addDays((int) $request->query('within_days', 30))->toDateString(),
                ]);
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (ProductWarranty $w) => $this->row($w))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(ProductWarranty $warranty)
    {
        $warranty->load([
            'customer:id,name,phone', 'product:id,sku,name', 'variant:id,sku,name',
            'invoice:id,invoice_number,invoice_date',
            'claims' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return response()->json(['data' => $this->row($warranty, detailed: true)]);
    }

    /**
     * What covers this customer / bill / serial right now.
     *
     * Every matching card is returned, live or not, each with `is_live` and a
     * reason — the counter needs to be able to say what happened to expired
     * cover, not merely that none was found.
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'warranty_number' => ['nullable', 'string', 'max:40'],
        ]);

        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        if ($data === []) {
            return response()->json([
                'message' => 'Give a bill number, a customer, a serial or the card number to look cover up.',
            ], 422);
        }

        $query = ProductWarranty::query()
            ->with(['customer:id,name,phone', 'product:id,sku,name', 'variant:id,sku,name', 'invoice:id,invoice_number'])
            ->orderByDesc('end_date');

        foreach (['invoice_id', 'customer_id', 'serial_no', 'warranty_number'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        $rows = $query->get()->map(fn (ProductWarranty $w) => $this->row($w))->values();

        return response()->json([
            'data' => $rows,
            'is_covered' => $rows->contains('is_live', true),
        ]);
    }

    /**
     * The lines of an invoice that could be registered.
     *
     * Reached through the sales order, because that is where the lines live —
     * an invoice in this ERP carries the money, the order carries the goods.
     * Lines whose article has no warranty policy are still returned, marked
     * `has_policy: false`, so a counter can register cover on something sold
     * before anyone thought to set a policy on it.
     */
    public function issuable(Request $request)
    {
        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);

        $invoice = Invoice::with([
            'salesOrder.items.product:id,sku,name,warranty_months,warranty_terms',
            'salesOrder.items.variant:id,sku,name',
        ])->findOrFail($data['invoice_id']);

        $existing = ProductWarranty::where('invoice_id', $invoice->id)
            ->get(['product_id', 'variant_id'])
            ->map(fn ($w) => $w->product_id.':'.($w->variant_id ?? ''))
            ->all();

        $lines = collect($invoice->salesOrder?->items ?? [])
            // A service line has nothing to warrant — there is no object to
            // bring back — and including it would offer a card on "fitting
            // charges".
            ->reject(fn ($item) => ! $item->product_id || $item->product?->is_service)
            ->map(function ($item) use ($existing) {
                $months = (int) ($item->product?->warranty_months ?? 0);

                return [
                    'product_id' => $item->product_id,
                    'product' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'variant_id' => $item->variant_id,
                    'variant' => $item->variant?->name ?: $item->variant?->sku,
                    'quantity' => (float) $item->quantity,
                    // The serials captured at dispatch, where the trade tracks
                    // them — a watch or an appliance is claimed on its serial,
                    // a pair of shoes on the bill.
                    'serials' => is_array($item->serials) ? $item->serials : [],
                    'warranty_months' => $months ?: null,
                    'warranty_terms' => $item->product?->warranty_terms,
                    'has_policy' => $months > 0,
                    'already_issued' => in_array(
                        $item->product_id.':'.($item->variant_id ?? ''), $existing, true,
                    ),
                ];
            })->values();

        return response()->json([
            'data' => $lines,
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'customer_id' => $invoice->customer_id,
                'customer' => $invoice->customer?->name,
            ],
        ]);
    }

    /**
     * Register cover for one or more lines of an invoice.
     *
     * Idempotent per (invoice, product, variant, serial): re-posting the same
     * request registers nothing twice. That is checked in PHP rather than by a
     * unique index because three of those four columns are nullable and MySQL
     * lets NULLs repeat in a unique key — the index would pass exactly the
     * duplicates that matter.
     */
    public function issue(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:100'],
            'lines.*.coverage_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'lines.*.source' => ['nullable', 'string', 'in:manufacturer,shop,extended'],
        ]);

        $invoice = Invoice::findOrFail($data['invoice_id']);
        $start = $invoice->invoice_date ? Carbon::parse($invoice->invoice_date) : now();

        $issued = [];
        $skipped = 0;

        foreach ($data['lines'] as $line) {
            $product = Product::find($line['product_id']);
            $months = (int) ($line['coverage_months'] ?? $product?->warranty_months ?? 0);

            if ($months < 1) {
                // No policy on the article and none supplied: registering a card
                // with no term would be a card that covers nothing.
                $skipped++;

                continue;
            }

            $exists = ProductWarranty::where('invoice_id', $invoice->id)
                ->where('product_id', $line['product_id'])
                ->where('variant_id', $line['variant_id'] ?? null)
                ->where('serial_no', $line['serial_no'] ?? null)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $issued[] = ProductWarranty::create([
                'warranty_number' => ProductWarranty::nextNumber(),
                'customer_id' => $invoice->customer_id,
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
                'invoice_id' => $invoice->id,
                'source' => $line['source'] ?? 'manufacturer',
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addMonths($months)->toDateString(),
                'coverage_months' => $months,
                'status' => 'active',
                // Snapshotted, not referenced: next season's policy change must
                // not rewrite what this customer was promised today.
                'terms' => $product?->warranty_terms,
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'data' => array_map(fn (ProductWarranty $w) => $this->row(
                $w->fresh(['customer', 'product', 'variant', 'invoice']),
            ), $issued),
            'issued' => count($issued),
            'skipped' => $skipped,
            'message' => count($issued).' warranty card(s) registered'
                .($skipped ? ", {$skipped} skipped (already registered, or no cover term)." : '.'),
        ], 201);
    }

    public function store(ProductWarrantyRequest $request)
    {
        $data = $request->validated();
        $data['end_date'] = $this->resolveEndDate($data);

        $warranty = ProductWarranty::create($data + [
            'warranty_number' => ProductWarranty::nextNumber(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->row($warranty->fresh(['customer', 'product', 'variant', 'invoice']), detailed: true),
        ], 201);
    }

    public function update(ProductWarrantyRequest $request, ProductWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            // Editing the term of a card that has already paid out would rewrite
            // the basis on which a shoe was replaced. Void it and register a
            // corrected one — both facts then stay on the record.
            return response()->json([
                'message' => 'This card has claims against it and can no longer be edited. Void it and register a corrected one.',
            ], 422);
        }

        $data = $request->validated();
        $data['end_date'] = $this->resolveEndDate($data);

        $warranty->update($data);

        return response()->json([
            'data' => $this->row($warranty->fresh(['customer', 'product', 'variant', 'invoice']), detailed: true),
        ]);
    }

    /** Cancel cover — mis-keyed, or the sale itself was reversed. */
    public function void(Request $request, ProductWarranty $warranty)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $warranty->forceFill([
            'status' => 'void',
            'notes' => trim((string) $warranty->notes."\nVoided: ".($data['reason'] ?? '—')),
        ])->save();

        return response()->json([
            'data' => $this->row($warranty->fresh(['customer', 'product', 'variant', 'invoice']), detailed: true),
        ]);
    }

    public function destroy(ProductWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            return response()->json([
                'message' => 'Something has been claimed under this card. Void it instead of deleting it.',
            ], 422);
        }

        $warranty->delete();

        return response()->json(['message' => 'Warranty card removed from the register.']);
    }

    /**
     * The end date, from whichever the caller gave. An explicit date always
     * wins — see the note in ProductWarrantyRequest on why the two are not
     * collapsed into one field.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveEndDate(array $data): string
    {
        if (! empty($data['end_date'])) {
            return Carbon::parse($data['end_date'])->toDateString();
        }

        return Carbon::parse($data['start_date'])
            ->addMonths((int) ($data['coverage_months'] ?? 0))
            ->toDateString();
    }

    /** @return array<string, int|float> */
    private function summary(): array
    {
        $today = now()->toDateString();

        return [
            'registered' => ProductWarranty::count(),
            'active' => ProductWarranty::where('status', 'active')->where('end_date', '>=', $today)->count(),
            'expiring_30d' => ProductWarranty::where('status', 'active')
                ->whereBetween('end_date', [$today, now()->addDays(30)->toDateString()])
                ->count(),
            'claims' => (int) ProductWarranty::sum('claims_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function row(ProductWarranty $w, bool $detailed = false): array
    {
        $base = [
            'id' => $w->id,
            'warranty_number' => $w->warranty_number,
            'customer_id' => $w->customer_id,
            'customer' => $w->customer?->name,
            'customer_phone' => $w->customer?->phone,
            'product_id' => $w->product_id,
            'product' => $w->product?->name,
            'sku' => $w->product?->sku,
            'variant_id' => $w->variant_id,
            'variant' => $w->variant?->name ?: $w->variant?->sku,
            'item' => $w->itemLabel(),
            'serial_no' => $w->serial_no,
            'invoice_id' => $w->invoice_id,
            'invoice_number' => $w->invoice?->invoice_number,
            'source' => $w->source,
            'start_date' => $w->start_date?->toDateString(),
            'end_date' => $w->end_date?->toDateString(),
            'coverage_months' => $w->coverage_months,
            /**
             * What the card pays for, and where. Added by the Electronics &
             * Appliances plugin and reported for every trade, because the
             * defaults are what a card has always meant — parts and labour,
             * carry-in — so a footwear counter reads "Parts & labour" and nothing
             * about its screens changes.
             */
            'unit_id' => $w->unit_id,
            'covers_parts' => (bool) $w->covers_parts,
            'covers_labour' => (bool) $w->covers_labour,
            'coverage' => $w->coverageLabel(),
            'is_onsite' => (bool) $w->is_onsite,
            'status' => $w->status,
            'claims_count' => (int) $w->claims_count,
            'last_claim_at' => $w->last_claim_at?->toIso8601String(),
            /**
             * Computed rather than read off `status`: a row sits at 'active'
             * while its end date quietly passes, and the register should say so
             * without waiting for someone to run a sweep.
             */
            'is_live' => $w->covers(),
            'days_remaining' => $w->daysRemaining(),
            'reason' => $w->refusalReason(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'terms' => $w->terms,
            'notes' => $w->notes,
            'claims' => $w->relationLoaded('claims')
                ? $w->claims->map(fn ($c) => [
                    'id' => $c->id,
                    'claim_number' => $c->claim_number,
                    'reported_on' => $c->reported_on?->toDateString(),
                    'fault' => $c->fault,
                    'status' => $c->status,
                    'resolution' => $c->resolution,
                    'cost' => (float) $c->cost,
                ])->values()
                : [],
        ];
    }
}
