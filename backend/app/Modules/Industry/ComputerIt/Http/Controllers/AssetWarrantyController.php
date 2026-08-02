<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Controllers;

use App\Modules\Industry\ComputerIt\Http\Requests\AssetWarrantyRequest;
use App\Modules\Industry\ComputerIt\Models\AssetWarranty;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The warranty register.
 *
 * Its whole purpose is to answer one question at a counter, in front of a
 * customer who is certain the answer is yes: is this still covered, and does the
 * cover pay for someone to come out? So `check` is a first-class endpoint rather
 * than something the caller assembles from a list — it takes a machine (or a
 * bare serial number, because that is all the customer on the phone has) and
 * returns what is live, with the reason anything else is not.
 */
class AssetWarrantyController extends Controller
{
    public function index(Request $request)
    {
        $query = AssetWarranty::query()->with([
            'asset:id,asset_tag,make,model,serial_no,customer_id',
            'customer:id,name,phone',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('warranty_number', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhereHas('asset', fn ($a) => $a->where('asset_tag', 'like', "%{$search}%")
                        ->orWhere('serial_no', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['type', 'provider', 'status', 'service_level', 'asset_id', 'customer_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Cover about to run out — the list a shop works to sell extended cover
        // from, and the one that says what to finish now.
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
                ->through(fn (AssetWarranty $w) => $this->row($w))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(AssetWarranty $warranty)
    {
        $warranty->load([
            'asset:id,asset_tag,make,model,serial_no,customer_id',
            'customer:id,name,phone', 'product:id,sku,name',
            'invoice:id,invoice_number,invoice_date',
            'claims:id,it_warranty_id,job_number,status,received_at,total',
        ]);

        return response()->json(['data' => $this->row($warranty, detailed: true)]);
    }

    /**
     * What covers this machine right now.
     *
     * Accepts either a machine or a bare serial number, because the customer on
     * the phone reads out what is printed on the sticker and has never heard of
     * an asset tag. Returns every registration found — not only the live ones —
     * with `is_live` and a reason, because "your cover ran out in March" is a far
     * better thing to be able to say than "no cover found".
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'exists:computer_it_assets,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
        ]);

        $asset = null;
        if (! empty($data['asset_id'])) {
            $asset = CustomerAsset::find($data['asset_id']);
        } elseif (! empty($data['serial_no'])) {
            $asset = CustomerAsset::where('serial_no', $data['serial_no'])->first();
        }

        if (! $asset && empty($data['serial_no'])) {
            return response()->json([
                'message' => 'Give a machine or a serial number to check.',
            ], 422);
        }

        /**
         * A serial with no machine behind it is still worth answering: a part
         * warranty is registered against the serial on the part long before
         * anyone creates an asset record for it, and refusing to look would
         * hide cover the shop itself sold.
         */
        $query = AssetWarranty::query()->with('product:id,sku,name');
        $query = $asset
            ? $query->where(function ($q) use ($asset, $data) {
                $q->where('asset_id', $asset->id);
                if (! empty($data['serial_no'])) {
                    $q->orWhere('serial_no', $data['serial_no']);
                }
            })
            : $query->where('serial_no', $data['serial_no']);

        $warranties = $query->orderByDesc('end_date')->get();

        $rows = $warranties->map(function (AssetWarranty $w) {
            $live = $w->covers();

            return $this->row($w) + [
                'is_live' => $live,
                'reason' => $live ? null : $this->whyNot($w),
                'days_remaining' => $w->daysRemaining(),
            ];
        })->values();

        $liveRows = $rows->where('is_live', true);

        return response()->json([
            'data' => $rows,
            'asset' => $asset ? [
                'id' => $asset->id,
                'label' => $asset->label(),
                'asset_tag' => $asset->asset_tag,
                'customer' => $asset->customer?->name,
            ] : null,
            // An AMC is not a warranty, but it answers the same question at the
            // counter — "does this cost me anything?" — so the check reports it
            // rather than making someone open a second screen to find out.
            'amc' => $asset?->amcCoversToday() ? [
                'contract_number' => $asset->amcContract?->contract_number,
                'end_date' => $asset->amcContract?->end_date?->toDateString(),
                'visits_remaining' => $asset->amcContract?->visitsRemaining(),
            ] : null,
            'is_covered' => $liveRows->isNotEmpty() || (bool) $asset?->amcCoversToday(),
            // Whether an engineer may be sent, which is the second half of the
            // question and the half that costs the shop money.
            'covers_onsite' => $liveRows->contains('covers_onsite', true),
        ]);
    }

    public function store(AssetWarrantyRequest $request)
    {
        $data = $request->validated();

        // Default the owner and the serial from the machine: both are already
        // known, and re-keying a serial is how a claim ends up unmatchable.
        if (! empty($data['asset_id'])) {
            $asset = CustomerAsset::find($data['asset_id']);
            $data['customer_id'] ??= $asset?->customer_id;
            $data['serial_no'] ??= $asset?->serial_no;
        }

        $warranty = AssetWarranty::create($data + [
            'warranty_number' => AssetWarranty::nextNumber(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($warranty->fresh(['asset', 'customer', 'product']), detailed: true)],
            201,
        );
    }

    public function update(AssetWarrantyRequest $request, AssetWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            // Editing the terms of a cover that has already paid out would
            // rewrite the basis on which past work was given away free. Voiding
            // it and registering a corrected one keeps both facts on the record.
            return response()->json([
                'message' => 'This cover has claims against it and can no longer be edited. Void it and register a corrected one.',
            ], 422);
        }

        $warranty->update($request->validated());

        return response()->json(
            ['data' => $this->row($warranty->fresh(['asset', 'customer', 'product']), detailed: true)],
        );
    }

    /** Cancel cover — mis-keyed, or withdrawn by the manufacturer. */
    public function void(Request $request, AssetWarranty $warranty)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $warranty->forceFill([
            'status' => 'void',
            'notes' => trim((string) $warranty->notes."\nVoided: ".($data['reason'] ?? '—')),
        ])->save();

        return response()->json(
            ['data' => $this->row($warranty->fresh(['asset', 'customer', 'product']), detailed: true)],
        );
    }

    public function destroy(AssetWarranty $warranty)
    {
        if ($warranty->claims_count > 0) {
            return response()->json([
                'message' => 'Work has been done under this cover. Void it instead of deleting it.',
            ], 422);
        }

        $warranty->delete();

        return response()->json(['message' => 'Warranty removed from the register.']);
    }

    /** Why a cover did not apply, in the words the counter would use. */
    private function whyNot(AssetWarranty $w): string
    {
        if ($w->status === 'void') {
            return 'This cover was voided.';
        }
        if ($w->end_date && $w->end_date->isPast()) {
            return "Expired on {$w->end_date->format('d M Y')}.";
        }
        if ($w->start_date && $w->start_date->isFuture()) {
            return "Does not start until {$w->start_date->format('d M Y')}.";
        }

        return 'Not currently in force.';
    }

    private function summary(): array
    {
        return [
            'registered' => AssetWarranty::count(),
            'active' => AssetWarranty::where('status', 'active')
                ->where('end_date', '>=', now()->toDateString())
                ->count(),
            'expiring_30d' => AssetWarranty::where('status', 'active')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count(),
            // Worth its own tile: on-site cover is what obliges the shop to put
            // an engineer in a rickshaw, so it is the number that sizes the week.
            'onsite' => AssetWarranty::where('status', 'active')
                ->whereIn('service_level', AssetWarranty::ONSITE_LEVELS)
                ->where('end_date', '>=', now()->toDateString())
                ->count(),
            'claims' => (int) AssetWarranty::sum('claims_count'),
        ];
    }

    private function row(AssetWarranty $w, bool $detailed = false): array
    {
        $base = [
            'id' => $w->id,
            'warranty_number' => $w->warranty_number,
            'type' => $w->type,
            'provider' => $w->provider,
            'service_level' => $w->service_level,
            'covers_onsite' => $w->coversOnsite(),
            'asset_id' => $w->asset_id,
            'asset' => $w->asset?->label(),
            'asset_tag' => $w->asset?->asset_tag,
            'customer_id' => $w->customer_id,
            'customer' => $w->customer?->name,
            'product_id' => $w->product_id,
            'part' => $w->product?->name,
            'serial_no' => $w->serial_no,
            'start_date' => $w->start_date?->toDateString(),
            'end_date' => $w->end_date?->toDateString(),
            'coverage_months' => $w->coverage_months,
            'days_remaining' => $w->daysRemaining(),
            'status' => $w->status,
            'claims_count' => (int) $w->claims_count,
            'last_claim_at' => $w->last_claim_at?->toIso8601String(),
            'is_live' => $w->covers(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'invoice_id' => $w->invoice_id,
            'invoice_number' => $w->invoice?->invoice_number,
            'service_job_id' => $w->service_job_id,
            'terms' => $w->terms,
            'notes' => $w->notes,
            'claims' => $w->relationLoaded('claims')
                ? $w->claims->map(fn ($job) => [
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
