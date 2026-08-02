<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherRedemption;
use App\Services\GiftVoucherService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The gift-voucher register.
 *
 * Issuing and looking one up are the two things this screen exists for;
 * *spending* one happens at the till through PosController, which is the only
 * place a voucher can be set against a bill. That split is deliberate — a
 * redeem button here would let someone draw a voucher down with no sale behind
 * it, which is a hole in the books, not a feature.
 *
 * `check` is a first-class endpoint for the same reason the warranty register
 * has one: the question is asked at the counter, in front of the person holding
 * the card, and "expired on 12 June" is a far better answer than "invalid".
 */
class GiftVoucherController extends Controller
{
    public function __construct(private readonly GiftVoucherService $vouchers) {}

    public function index(Request $request)
    {
        $query = GiftVoucher::query()->with('customer:id,name,phone');

        if ($search = $request->query('search')) {
            $code = strtoupper(preg_replace('/\s+/', '', (string) $search) ?: '');

            $query->where(function ($q) use ($search, $code) {
                $q->where('code', 'like', "%{$code}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        /**
         * Cards about to lapse. Filtered on the date rather than on
         * `status = 'expired'` — a voucher expiring next week is still 'active',
         * and this list exists precisely to catch it before it is not.
         */
        if ($request->boolean('expiring')) {
            $query->where('status', 'active')
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [
                    now()->toDateString(),
                    now()->addDays((int) $request->query('within_days', 30))->toDateString(),
                ]);
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GiftVoucher $v) => $this->row($v))->toArray()
                + ['summary' => $this->summary()],
        );
    }

    /**
     * Is this card good, and for how much?
     *
     * On `.view` rather than an edit permission: this is a question, and the
     * person who has to answer it is whoever is standing at the counter.
     */
    public function check(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        $voucher = $this->vouchers->findByCode($data['code']);

        if (! $voucher) {
            return response()->json([
                'found' => false,
                'is_spendable' => false,
                'reason' => "No gift voucher matches the code \"{$data['code']}\".",
            ], 404);
        }

        return response()->json([
            'found' => true,
            'is_spendable' => $voucher->isSpendable(),
            'reason' => $voucher->refusalReason(),
            'data' => $this->row($voucher->load('customer:id,name,phone'), detailed: true),
        ]);
    }

    public function show(GiftVoucher $giftVoucher)
    {
        $giftVoucher->load([
            'customer:id,name,phone',
            'issuedInvoice:id,invoice_number',
            'redemptions.invoice:id,invoice_number',
        ]);

        return response()->json(['data' => $this->row($giftVoucher, detailed: true)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // Optional: an unprinted voucher gets a generated code. A shop with
            // pre-printed cards types the number off the card instead.
            'code' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'face_value' => ['required', 'numeric', 'gt:0'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'issued_invoice_id' => ['nullable', 'exists:invoices,id'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $voucher = $this->vouchers->issue($data, $request->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->row($voucher, detailed: true)], 201);
    }

    /**
     * Correct a voucher's details — never its money.
     *
     * `face_value` and `balance` are absent from the rules on purpose. The
     * balance is derived from the redemption ledger, so editing it by hand would
     * put the two permanently out of step; a card issued for the wrong amount is
     * cancelled and reissued, which leaves both facts on the record.
     */
    public function update(Request $request, GiftVoucher $giftVoucher)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $giftVoucher->update($data);
        // Extending the date on a lapsed card must bring it back to life, and
        // shortening it must take a live one out — both are the status pass.
        $giftVoucher->refreshStatus();

        return response()->json(['data' => $this->row($giftVoucher->fresh('customer'), detailed: true)]);
    }

    /**
     * Withdraw a voucher — lost, issued in error, or written off.
     *
     * Sits on delete authority rather than edit: it is the destructive act in
     * this register even though the row survives it, and what it destroys is
     * something a customer is holding in their hand.
     */
    public function cancel(Request $request, GiftVoucher $giftVoucher)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $giftVoucher->forceFill([
            'status' => 'cancelled',
            'notes' => trim((string) $giftVoucher->notes."\nCancelled: ".($data['reason'] ?: '—')),
        ])->save();

        return response()->json(['data' => $this->row($giftVoucher->fresh('customer'), detailed: true)]);
    }

    public function destroy(GiftVoucher $giftVoucher)
    {
        // A spent voucher is part of how bills were settled. Deleting it would
        // orphan payment rows that say a voucher paid for goods.
        if ($giftVoucher->redemptions()->exists()) {
            return response()->json([
                'message' => 'This voucher has been used against a bill. Cancel it instead of deleting it.',
            ], 422);
        }

        $giftVoucher->delete();

        return response()->json(['message' => 'Voucher removed.']);
    }

    /**
     * What the shop owes on vouchers. `outstanding` is a real liability — money
     * already banked against goods not yet handed over — which is the figure an
     * accountant asks for and no other screen reports.
     */
    private function summary(): array
    {
        return [
            'active' => GiftVoucher::where('status', 'active')->count(),
            'outstanding' => round((float) GiftVoucher::where('status', 'active')->sum('balance'), 2),
            'issued_value' => round((float) GiftVoucher::whereIn('status', ['active', 'redeemed', 'expired'])
                ->sum('face_value'), 2),
            'redeemed_value' => round((float) GiftVoucherRedemption::where('amount', '>', 0)->sum('amount'), 2),
            'expiring_30d' => GiftVoucher::where('status', 'active')
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count(),
        ];
    }

    private function row(GiftVoucher $v, bool $detailed = false): array
    {
        $base = [
            'id' => $v->id,
            'code' => $v->code,
            'customer_id' => $v->customer_id,
            'customer_name' => $v->customer?->name,
            'recipient_name' => $v->recipient_name,
            'recipient_phone' => $v->recipient_phone,
            'face_value' => (float) $v->face_value,
            'balance' => (float) $v->balance,
            'issued_on' => $v->issued_on?->toDateString(),
            'expires_on' => $v->expires_on?->toDateString(),
            'status' => $v->status,
            // Computed, not read off `status`: a card can sit at 'active' while
            // its expiry has quietly passed, and the register should say so
            // without waiting for a sweep.
            'is_spendable' => $v->isSpendable(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'reason' => $v->refusalReason(),
            'issued_invoice_id' => $v->issued_invoice_id,
            'issued_invoice_number' => $v->issuedInvoice?->invoice_number,
            'notes' => $v->notes,
            'redemptions' => $v->relationLoaded('redemptions')
                ? $v->redemptions->map(fn (GiftVoucherRedemption $r) => [
                    'id' => $r->id,
                    'amount' => (float) $r->amount,
                    'invoice_id' => $r->invoice_id,
                    'invoice_number' => $r->invoice?->invoice_number,
                    'redeemed_at' => $r->redeemed_at?->toIso8601String(),
                    'reference' => $r->reference,
                ])->values()
                : [],
        ];
    }
}
