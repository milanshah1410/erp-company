<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarrantyClaimRequest;
use App\Models\ProductWarranty;
use App\Models\WarrantyClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Claims made back against a warranty card.
 *
 * Registering one is deliberately allowed even when the card is not live. A
 * customer who walks in a week after the term ends is a decision the shop makes
 * — most will honour it, some will not — and a register that refused to record
 * the visit would simply push that decision off the books. So the claim is
 * always recorded and the cover's state is recorded with it: `covered_at_report`
 * says whether the term was still running on the day the item came back, which
 * is the fact anyone reviewing the claim later actually needs.
 *
 * Settling is its own endpoint rather than an ordinary edit, because settling is
 * the step with consequences: a replacement or a refund uses the covered item
 * up, and the card behind it has to close with the claim.
 */
class WarrantyClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = WarrantyClaim::query()->with([
            'warranty:id,warranty_number,product_id,variant_id,serial_no,end_date,status',
            'warranty.product:id,sku,name',
            'warranty.variant:id,sku,name',
            'customer:id,name,phone',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('claim_number', 'like', "%{$search}%")
                    ->orWhere('fault', 'like', "%{$search}%")
                    ->orWhereHas('warranty', fn ($w) => $w->where('warranty_number', 'like', "%{$search}%")
                        ->orWhere('serial_no', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'resolution', 'warranty_id', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The desk's own worklist: everything not yet settled, oldest first —
        // an item left with a shop for three weeks is the complaint that turns
        // into a review.
        if ($request->boolean('open')) {
            $query->whereNotIn('status', ['resolved', 'rejected'])->reorder('reported_on');
        } else {
            $query->orderByDesc('id');
        }

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (WarrantyClaim $c) => $this->row($c))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(WarrantyClaim $claim)
    {
        $claim->load([
            'warranty:id,warranty_number,product_id,variant_id,serial_no,start_date,end_date,status,invoice_id',
            'warranty.product:id,sku,name', 'warranty.variant:id,sku,name',
            'warranty.invoice:id,invoice_number',
            'customer:id,name,phone', 'handler:id,name',
            'settlementInvoice:id,invoice_number,total',
        ]);

        return response()->json(['data' => $this->row($claim, detailed: true)]);
    }

    public function store(WarrantyClaimRequest $request)
    {
        $data = $request->validated();
        $warranty = ProductWarranty::findOrFail($data['warranty_id']);

        if ($warranty->status === 'claimed') {
            // The covered item was already replaced or refunded — there is
            // nothing left to bring back, and a second claim against the same
            // card would give away a second pair for one failure.
            return response()->json([
                'message' => 'This card was already settled by replacement or refund. Register cover on the replacement instead.',
            ], 422);
        }

        $claim = DB::transaction(function () use ($data, $warranty, $request) {
            $claim = WarrantyClaim::create($data + [
                'claim_number' => WarrantyClaim::nextNumber(),
                // Defaulted from the card rather than asked for: the person who
                // holds the cover is the person making the claim, and re-keying
                // it is how a claim ends up filed against the wrong customer.
                'customer_id' => $data['customer_id'] ?? $warranty->customer_id,
                'status' => $data['status'] ?? 'open',
                'created_by' => $request->user()?->id,
            ]);

            $warranty->recordClaim();

            return $claim;
        });

        return response()->json([
            'data' => $this->row($claim->fresh(['warranty.product', 'warranty.variant', 'customer']), detailed: true),
        ], 201);
    }

    public function update(WarrantyClaimRequest $request, WarrantyClaim $claim)
    {
        if ($claim->isClosed()) {
            return response()->json([
                'message' => 'This claim is closed. Register a fresh claim rather than reopening a settled one.',
            ], 422);
        }

        $claim->update($request->validated());

        return response()->json([
            'data' => $this->row($claim->fresh(['warranty.product', 'warranty.variant', 'customer']), detailed: true),
        ]);
    }

    /**
     * Settle the claim: repair, replace, refund or reject.
     *
     * The one place the card's status moves. Replacing or refunding consumes the
     * covered item, so the card closes with the claim; repairing does not, and
     * the same pair can fail again inside the same term.
     */
    public function resolve(Request $request, WarrantyClaim $claim)
    {
        if ($claim->isClosed()) {
            return response()->json(['message' => 'This claim has already been settled.'], 422);
        }

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['repair', 'replace', 'refund', 'rejected'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'resolved_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            // The replacement bill or credit note, where one was raised. The
            // money is made by the sales spine as usual; this is only the link.
            'settlement_invoice_id' => ['nullable', 'exists:invoices,id'],
        ]);

        DB::transaction(function () use ($claim, $data, $request) {
            $claim->forceFill([
                'resolution' => $data['resolution'],
                'resolution_note' => $data['resolution_note'] ?? $claim->resolution_note,
                'resolved_on' => $data['resolved_on'] ?? now()->toDateString(),
                'cost' => $data['cost'] ?? $claim->cost,
                'settlement_invoice_id' => $data['settlement_invoice_id'] ?? $claim->settlement_invoice_id,
                'status' => $data['resolution'] === 'rejected' ? 'rejected' : 'resolved',
                'handled_by' => $claim->handled_by ?? $request->user()?->id,
            ])->save();

            if ($claim->consumesCover() && $claim->warranty) {
                $claim->warranty->forceFill(['status' => 'claimed'])->save();
            }
        });

        return response()->json([
            'data' => $this->row($claim->fresh(['warranty.product', 'warranty.variant', 'customer']), detailed: true),
        ]);
    }

    public function destroy(WarrantyClaim $claim)
    {
        if ($claim->isClosed()) {
            return response()->json([
                'message' => 'A settled claim is part of the shop’s record and cannot be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($claim) {
            // Give the count back, or a mis-keyed claim would permanently mark a
            // card as having been claimed against.
            if ($warranty = $claim->warranty) {
                $warranty->forceFill([
                    'claims_count' => max(0, (int) $warranty->claims_count - 1),
                ])->save();
            }

            $claim->delete();
        });

        return response()->json(['message' => 'Claim removed.']);
    }

    /** @return array<string, int|float> */
    private function summary(): array
    {
        return [
            'total' => WarrantyClaim::count(),
            'open' => WarrantyClaim::whereNotIn('status', ['resolved', 'rejected'])->count(),
            'rejected' => WarrantyClaim::where('status', 'rejected')->count(),
            // What honouring warranties has cost the shop. The number that turns
            // this register from paperwork into a buying decision.
            'cost' => (float) WarrantyClaim::sum('cost'),
        ];
    }

    /** @return array<string, mixed> */
    private function row(WarrantyClaim $c, bool $detailed = false): array
    {
        $w = $c->warranty;

        $base = [
            'id' => $c->id,
            'claim_number' => $c->claim_number,
            'warranty_id' => $c->warranty_id,
            'warranty_number' => $w?->warranty_number,
            'item' => $w?->itemLabel(),
            'serial_no' => $w?->serial_no,
            'customer_id' => $c->customer_id,
            'customer' => $c->customer?->name,
            'reported_on' => $c->reported_on?->toDateString(),
            'fault' => $c->fault,
            'status' => $c->status,
            'resolution' => $c->resolution,
            'resolved_on' => $c->resolved_on?->toDateString(),
            'cost' => (float) $c->cost,
            /**
             * Was the term still running on the day it came back? Evaluated
             * against the report date, not today — a claim reported in the last
             * week of cover is a covered claim however long it then took to
             * settle, and judging it by today's date would turn every slow
             * settlement into an out-of-warranty one.
             */
            'covered_at_report' => $w && $c->reported_on
                ? $w->covers($c->reported_on->copy()->startOfDay())
                : null,
            'warranty_end_date' => $w?->end_date?->toDateString(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'resolution_note' => $c->resolution_note,
            'settlement_invoice_id' => $c->settlement_invoice_id,
            'settlement_invoice_number' => $c->settlementInvoice?->invoice_number,
            'handled_by' => $c->handled_by,
            'handler' => $c->handler?->name,
            'invoice_number' => $w?->invoice?->invoice_number,
            'warranty_status' => $w?->status,
        ];
    }
}
