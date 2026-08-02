<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Mobile\Http\Requests\HandsetRequest;
use App\Modules\Industry\Mobile\Models\Buyback;
use App\Modules\Industry\Mobile\Models\EmiPlan;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Models\InsurancePolicy;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Http\Request;

/**
 * The IMEI register.
 *
 * It exists for one lookup, done a dozen times a day: somebody reads fifteen digits
 * off the back of a handset and everything the shop knows about that phone has to
 * come back — who bought it and when, what covers it, what is still owed on it,
 * what has already been repaired, and whether it has been through here before as a
 * trade-in. `lookup` is that, and the screen is built around it.
 *
 * Deliberately assembled server-side. The answer spans the handset register, the
 * core warranty register, the insurance book, the finance files, the job book and
 * the part-exchange register — and a counter screen that had to make six calls to
 * answer one question would be used once and then abandoned.
 */
class HandsetController extends Controller
{
    public function index(Request $request)
    {
        $query = Handset::query()->with([
            'customer:id,name,phone',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $digits = Imei::normalize((string) $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('brand', 'like', "%{$search}%")
                    ->orWhere('model_name', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if ($digits) {
                    $q->orWhere('imei_1', 'like', "%{$digits}%")
                        ->orWhere('imei_2', 'like', "%{$digits}%");
                }
            });
        }

        foreach (['status', 'customer_id', 'product_id', 'brand'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_third_party')) {
            $query->where('is_third_party', $request->boolean('is_third_party'));
        }

        /**
         * Handsets whose IMEI fails its own check digit — the shop's mis-keying list,
         * and the cheapest moment to fix a transposed pair is before cover is written
         * against it.
         *
         * Filtered in PHP because Luhn is not expressible as a WHERE clause, so it is
         * applied to the page after paging; the count in the summary is computed over
         * every row and is the authoritative total.
         */
        $suspectOnly = $request->boolean('suspect');

        $query->orderByDesc('id');

        $page = $query->paginate(min((int) $request->query('per_page', 25), 200));
        $rows = $page->through(fn (Handset $h) => $this->row($h))->toArray();

        if ($suspectOnly) {
            $rows['data'] = array_values(array_filter(
                $rows['data'],
                static fn (array $row) => ! $row['imei_valid'],
            ));
        }

        return response()->json($rows + ['summary' => $this->summary()]);
    }

    /** Everything the shop knows about one handset, keyed on the number in hand. */
    public function lookup(Request $request)
    {
        $data = $request->validate(['imei' => ['required', 'string', 'max:40']]);

        $raw = (string) $data['imei'];
        $digits = Imei::normalize($raw) ?? $raw;

        $handset = Handset::with(['customer:id,name,phone', 'product:id,sku,name'])
            ->identifier($raw)
            ->first();

        /**
         * Everything below is matched on the *number* as well as on the register row,
         * so a handset the shop never stocked — insured over the counter, or brought
         * in for a repair — still answers. That case is not an edge: it is most of
         * the insurance and repair business.
         */
        $warranties = ProductWarranty::with(['customer:id,name,phone', 'product:id,sku,name'])
            ->where(function ($q) use ($digits, $handset) {
                $q->where('serial_no', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('end_date')
            ->get();

        $policies = InsurancePolicy::with('customer:id,name,phone')
            ->where(function ($q) use ($digits, $handset) {
                $q->where('imei', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('end_date')
            ->get();

        $emi = EmiPlan::with(['customer:id,name,phone', 'invoice:id,invoice_number', 'instalments'])
            ->where(function ($q) use ($digits, $handset) {
                $q->where('imei', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('id')
            ->get();

        $repairs = ServiceJob::query()
            ->where(function ($q) use ($digits, $handset) {
                $q->where('item_serial', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'job_number', 'status', 'complaint', 'received_at', 'total', 'is_warranty']);

        // Has this handset been through here before as a trade-in? The question a
        // shop asks the second time the same IMEI turns up.
        $tradeIn = Buyback::where('imei', $digits)->orderByDesc('id')->first();

        return response()->json([
            'imei' => $digits,
            'imei_valid' => Imei::isValid($digits),
            'handset' => $handset ? $this->row($handset, detailed: true) : null,
            'warranties' => $warranties->map(fn (ProductWarranty $w) => [
                'id' => $w->id,
                'warranty_number' => $w->warranty_number,
                'source' => $w->source,
                'customer' => $w->customer?->name,
                'start_date' => $w->start_date?->toDateString(),
                'end_date' => $w->end_date?->toDateString(),
                'status' => $w->status,
                'is_live' => $w->covers(),
                'claims_count' => (int) $w->claims_count,
            ])->values(),
            'policies' => $policies->map(fn (InsurancePolicy $p) => [
                'id' => $p->id,
                'policy_number' => $p->policy_number,
                'provider' => $p->provider,
                'customer' => $p->customer?->name,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'status' => $p->status,
                'is_live' => $p->covers(),
                'perils' => $p->perils(),
                'deductible' => (float) $p->deductible,
                'claims_remaining' => $p->claimsRemaining(),
            ])->values(),
            'emi_plans' => $emi->map(fn (EmiPlan $e) => [
                'id' => $e->id,
                'plan_number' => $e->plan_number,
                'financier' => $e->financier,
                'financier_name' => $e->financier_name,
                'customer' => $e->customer?->name,
                'tenure_months' => (int) $e->tenure_months,
                'paid_count' => $e->paidCount(),
                'outstanding' => $e->outstanding(),
                'overdue_count' => $e->overdueCount(),
                'status' => $e->status,
            ])->values(),
            'repairs' => $repairs->map(fn (ServiceJob $j) => [
                'id' => $j->id,
                'job_number' => $j->job_number,
                'status' => $j->status,
                'complaint' => $j->complaint,
                'received_at' => $j->received_at?->toIso8601String(),
                'is_warranty' => (bool) $j->is_warranty,
                'total' => (float) $j->total,
            ])->values(),
            'trade_in' => $tradeIn ? [
                'id' => $tradeIn->id,
                'buyback_number' => $tradeIn->buyback_number,
                'status' => $tradeIn->status,
                'grade' => $tradeIn->grade,
                'offered_value' => (float) $tradeIn->offered_value,
                'quoted_on' => $tradeIn->quoted_on?->toDateString(),
            ] : null,
            // Anything live at all, so the screen can lead with the answer rather
            // than with five collapsed panels.
            'is_covered' => $warranties->contains(fn (ProductWarranty $w) => $w->covers())
                || $policies->contains(fn (InsurancePolicy $p) => $p->covers()),
        ]);
    }

    public function show(Handset $handset)
    {
        $handset->load([
            'customer:id,name,phone', 'product:id,sku,name',
            'saleInvoice:id,invoice_number,invoice_date,total',
        ]);

        return response()->json(['data' => $this->row($handset, detailed: true)]);
    }

    public function store(HandsetRequest $request)
    {
        $data = $request->validated();

        // Fill brand, model and the standard cover from the catalogue line, so a
        // handset booked in at goods receipt carries the same terms as the article it
        // came from without anyone re-keying them.
        $data = $this->fillFromProduct($data);

        $handset = Handset::create($data + ['created_by' => $request->user()?->id]);

        return response()->json([
            'data' => $this->row($handset->fresh(['customer', 'product']), detailed: true),
            // Advisory, never enforced — see Imei. The screen warns; the row stands.
            'imei_valid' => $handset->imeiLooksValid(),
        ], 201);
    }

    public function update(HandsetRequest $request, Handset $handset)
    {
        $handset->update($this->fillFromProduct($request->validated()));

        return response()->json([
            'data' => $this->row($handset->fresh(['customer', 'product']), detailed: true),
            'imei_valid' => $handset->imeiLooksValid(),
        ]);
    }

    public function destroy(Handset $handset)
    {
        /**
         * A handset with history is never deleted. Its warranty cards, policies and
         * job cards all point at it, and removing the row makes every one of them
         * unreadable — which is why the register carries `returned` and `scrapped` as
         * terminal states instead.
         */
        $hasHistory = $handset->warranties()->exists()
            || $handset->policies()->exists()
            || $handset->emiPlans()->exists()
            || $handset->repairs()->exists();

        if ($hasHistory) {
            return response()->json([
                'message' => 'This handset has cover, finance or repairs against it. Mark it returned or scrapped instead of deleting it.',
            ], 422);
        }

        $handset->delete();

        return response()->json(['message' => 'Handset removed from the register.']);
    }

    /**
     * Copy what the catalogue already knows. Copied rather than referenced: a product
     * whose warranty policy is shortened next season must not retroactively shorten
     * cover on handsets already sold — the same rule the core warranty card follows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillFromProduct(array $data): array
    {
        if (empty($data['product_id'])) {
            return $data;
        }

        $product = Product::find($data['product_id']);

        if (! $product) {
            return $data;
        }

        $data['brand'] ??= $product->brand?->name;
        $data['model_name'] ??= $product->name;
        $data['warranty_months'] ??= $product->warranty_months;

        return $data;
    }

    private function summary(): array
    {
        $rows = Handset::query()->get(['id', 'imei_1', 'status', 'is_third_party']);

        return [
            'registered' => $rows->count(),
            'in_stock' => $rows->where('status', 'in_stock')->count(),
            'sold' => $rows->where('status', 'sold')->count(),
            // Handsets the shop did not sell — the repair and insurance book.
            'third_party' => $rows->where('is_third_party', true)->count(),
            // The mis-keying list, counted over every row rather than the current
            // page so the number on the tile is the real one.
            'suspect' => $rows->reject(fn (Handset $h) => Imei::isValid($h->imei_1))->count(),
        ];
    }

    private function row(Handset $h, bool $detailed = false): array
    {
        $base = [
            'id' => $h->id,
            'label' => $h->label(),
            'brand' => $h->brand,
            'model_name' => $h->model_name,
            'storage' => $h->storage,
            'colour' => $h->colour,
            'imei_1' => $h->imei_1,
            'imei_2' => $h->imei_2,
            'serial_no' => $h->serial_no,
            'imei_valid' => $h->imeiLooksValid(),
            'customer_id' => $h->customer_id,
            'customer' => $h->customer?->name,
            'customer_phone' => $h->customer?->phone,
            'product_id' => $h->product_id,
            'product' => $h->product?->name,
            'status' => $h->status,
            'is_third_party' => (bool) $h->is_third_party,
            'sold_at' => $h->sold_at?->toDateString(),
            'warranty_months' => $h->warranty_months,
            // Derived from the sale date and the standard term, so the counter can
            // see the cover a card *would* be issued for before issuing it.
            'standard_cover_until' => $h->warrantyEndsOn(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'sale_invoice_id' => $h->sale_invoice_id,
            'sale_invoice_number' => $h->saleInvoice?->invoice_number,
            'last_service_at' => $h->last_service_at?->toIso8601String(),
            'is_active' => (bool) $h->is_active,
            'notes' => $h->notes,
        ];
    }
}
