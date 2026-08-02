<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductWarranty;
use App\Modules\Industry\Mobile\Http\Requests\InsurancePolicyRequest;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Models\InsurancePolicy;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Http\Request;

/**
 * Handset damage and theft insurance.
 *
 * The book exists to answer one question at a counter, in front of a customer
 * holding a cracked phone and already certain of the answer: am I covered? So
 * `check` is a first-class endpoint keyed on the IMEI — the only thing the customer
 * has — and it returns every policy ever written against the handset with the
 * *reason* anything that is not live is not live. "The policy allowed one claim and
 * it was used in March" is a far better thing to be able to say than "no cover
 * found".
 *
 * `check` deliberately looks at the core warranty register too. The customer does
 * not distinguish between a warranty and a policy, and a screen that answered only
 * half the question would send someone away who was in fact covered.
 */
class InsurancePolicyController extends Controller
{
    public function index(Request $request)
    {
        $query = InsurancePolicy::query()->with([
            'customer:id,name,phone',
            'handset:id,brand,model_name,imei_1',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $digits = Imei::normalize((string) $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('policy_number', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%")
                    ->orWhere('provider_policy_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if ($digits) {
                    $q->orWhere('imei', 'like', "%{$digits}%")
                        ->orWhereHas('handset', fn ($h) => $h->where('imei_1', 'like', "%{$digits}%")
                            ->orWhere('imei_2', 'like', "%{$digits}%"));
                }
            });
        }

        foreach (['status', 'provider', 'customer_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        foreach (['covers_theft', 'covers_liquid_damage', 'covers_accidental_damage'] as $peril) {
            if ($request->filled($peril)) {
                $query->where($peril, $request->boolean($peril));
            }
        }

        // Cover about to run out — the renewal call list, and the reason a shop keeps
        // this book rather than leaving it to the insurer's portal.
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
                ->through(fn (InsurancePolicy $p) => $this->row($p))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(InsurancePolicy $policy)
    {
        $policy->load([
            'customer:id,name,phone', 'product:id,sku,name',
            'handset:id,brand,model_name,imei_1,imei_2',
            'invoice:id,invoice_number,invoice_date',
            'claims:id,insurance_policy_id,job_number,status,received_at,total',
        ]);

        return response()->json(['data' => $this->row($policy, detailed: true)]);
    }

    /**
     * What covers this handset right now — policies *and* warranty cards.
     *
     * Takes the IMEI rather than an id: the customer has a phone in their hand, not a
     * policy number, and requiring the caller to resolve the handset first would fail
     * for exactly the phones this shop did not sell.
     */
    public function check(Request $request)
    {
        $data = $request->validate(['imei' => ['required', 'string', 'max:40']]);

        $raw = (string) $data['imei'];
        $digits = Imei::normalize($raw) ?? $raw;

        $handset = Handset::with('product:id,sku,name')->identifier($raw)->first();

        $policies = InsurancePolicy::with('customer:id,name,phone')
            ->where(function ($q) use ($digits, $handset) {
                $q->where('imei', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('end_date')
            ->get();

        $rows = $policies->map(function (InsurancePolicy $p) {
            $live = $p->covers();

            return $this->row($p) + [
                'is_live' => $live,
                'reason' => $live ? null : $this->whyNot($p),
            ];
        })->values();

        /**
         * The warranty side of the same question, read off the core register. A
         * customer does not distinguish the two, and a repair that a manufacturer
         * warranty would have covered free should not be settled as an insurance
         * claim — that spends a once-only policy on something somebody else was
         * going to pay for.
         */
        $warranties = ProductWarranty::query()
            ->where(function ($q) use ($digits, $handset) {
                $q->where('serial_no', $digits);
                if ($handset) {
                    $q->orWhere('handset_id', $handset->id);
                }
            })
            ->orderByDesc('end_date')
            ->get()
            ->map(fn (ProductWarranty $w) => [
                'id' => $w->id,
                'warranty_number' => $w->warranty_number,
                'source' => $w->source,
                'end_date' => $w->end_date?->toDateString(),
                'status' => $w->status,
                'is_live' => $w->covers(),
                'reason' => $w->covers() ? null : $w->refusalReason(),
            ])->values();

        return response()->json([
            'data' => $rows,
            'warranties' => $warranties,
            'imei' => $digits,
            'imei_valid' => Imei::isValid($digits),
            'handset' => $handset ? [
                'id' => $handset->id,
                'label' => $handset->label(),
                'status' => $handset->status,
            ] : null,
            'is_insured' => $rows->contains('is_live', true),
            'is_under_warranty' => $warranties->contains('is_live', true),
        ]);
    }

    public function store(InsurancePolicyRequest $request)
    {
        $data = $request->validated();

        /**
         * Fill in from the handset whatever the handset already knows — its catalogue
         * line, its IMEI and its owner. Re-keying any of those at the counter is how
         * a policy ends up written against the wrong phone, which is only discovered
         * at claim time.
         */
        if (! empty($data['handset_id'])) {
            $handset = Handset::find($data['handset_id']);

            if ($handset) {
                $data['product_id'] ??= $handset->product_id;
                $data['imei'] ??= $handset->imei_1;
                $data['customer_id'] ??= $handset->customer_id;
            }
        }

        $policy = InsurancePolicy::create($data + [
            'policy_number' => InsurancePolicy::nextNumber(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->row($policy->fresh(['customer', 'product', 'handset']), detailed: true),
            'imei_valid' => Imei::isValid($policy->imei),
        ], 201);
    }

    public function update(InsurancePolicyRequest $request, InsurancePolicy $policy)
    {
        if ($policy->claims_count > 0) {
            /**
             * Editing the terms of a policy that has already paid out would rewrite
             * the basis on which a screen was replaced free. Voiding it and writing a
             * corrected one keeps both facts on the record.
             */
            return response()->json([
                'message' => 'This policy has claims against it and can no longer be edited. Void it and write a corrected one.',
            ], 422);
        }

        $policy->update($request->validated());

        return response()->json(['data' => $this->row($policy->fresh(['customer', 'product', 'handset']), detailed: true)]);
    }

    /** Withdraw cover — mis-keyed, cancelled by the insurer, or premium unpaid. */
    public function void(Request $request, InsurancePolicy $policy)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $policy->forceFill([
            'status' => 'void',
            'notes' => trim((string) $policy->notes."\nVoided: ".($data['reason'] ?? '—')),
        ])->save();

        return response()->json(['data' => $this->row($policy->fresh(['customer', 'product', 'handset']), detailed: true)]);
    }

    public function destroy(InsurancePolicy $policy)
    {
        if ($policy->claims_count > 0) {
            return response()->json([
                'message' => 'A claim has been made on this policy. Void it instead of deleting it.',
            ], 422);
        }

        $policy->delete();

        return response()->json(['message' => 'Policy removed from the book.']);
    }

    /** Why a policy did not apply, in the words the counter would use. */
    private function whyNot(InsurancePolicy $p): string
    {
        if ($p->status === 'void') {
            return 'This policy was cancelled.';
        }
        if ($p->isExhausted()) {
            $limit = (int) $p->claim_limit;

            return $limit === 1
                ? 'The single claim this policy allows has already been made.'
                : "All {$limit} claims on this policy have been used.";
        }
        if ($p->end_date && $p->end_date->isPast()) {
            return "Expired on {$p->end_date->format('d M Y')}.";
        }
        if ($p->start_date && $p->start_date->isFuture()) {
            return "Does not start until {$p->start_date->format('d M Y')}.";
        }

        return 'Not currently in force.';
    }

    private function summary(): array
    {
        $live = InsurancePolicy::where('status', 'active')
            ->where('end_date', '>=', now()->toDateString());

        return [
            'written' => InsurancePolicy::count(),
            'active' => (clone $live)->count(),
            'expiring_30d' => InsurancePolicy::where('status', 'active')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count(),
            'theft_cover' => (clone $live)->where('covers_theft', true)->count(),
            'claims' => (int) InsurancePolicy::sum('claims_count'),
            /**
             * What the shop took in premium. The reason a phone counter sells cover at
             * all: the margin on a handset is thin and the margin on a policy is not.
             */
            'premium_collected' => round(
                (float) InsurancePolicy::where('status', '!=', 'void')->sum('premium'),
                2,
            ),
        ];
    }

    private function row(InsurancePolicy $p, bool $detailed = false): array
    {
        $base = [
            'id' => $p->id,
            'policy_number' => $p->policy_number,
            'provider' => $p->provider,
            'provider_policy_no' => $p->provider_policy_no,
            'customer_id' => $p->customer_id,
            'customer' => $p->customer?->name,
            'handset_id' => $p->handset_id,
            'handset' => $p->handset?->label(),
            'product_id' => $p->product_id,
            // The handset's own IMEI wins when there is one: the policy's copy was
            // keyed by hand and the handset's came off the goods receipt.
            'imei' => $p->handset?->imei_1 ?? $p->imei,
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'coverage_months' => $p->coverage_months,
            'premium' => (float) $p->premium,
            'sum_insured' => $p->sum_insured === null ? null : (float) $p->sum_insured,
            'deductible' => (float) $p->deductible,
            'perils' => $p->perils(),
            'claim_limit' => $p->claim_limit,
            'claims_count' => (int) $p->claims_count,
            'claims_remaining' => $p->claimsRemaining(),
            'days_remaining' => $p->daysRemaining(),
            'status' => $p->status,
            // Computed rather than read off `status`: a row can sit at 'active' while
            // its end date has quietly passed, and the book should say so without
            // waiting for someone to run a sweep.
            'is_live' => $p->covers(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'covers_accidental_damage' => (bool) $p->covers_accidental_damage,
            'covers_liquid_damage' => (bool) $p->covers_liquid_damage,
            'covers_theft' => (bool) $p->covers_theft,
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $p->invoice?->invoice_number,
            'last_claim_at' => $p->last_claim_at?->toIso8601String(),
            'terms' => $p->terms,
            'notes' => $p->notes,
            'claims' => $p->relationLoaded('claims')
                ? $p->claims->map(fn ($job) => [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'status' => $job->status,
                    'received_at' => $job->received_at?->toIso8601String(),
                    'total' => (float) $job->total,
                ])->values()
                : [],
        ];
    }
}
