<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\CouponService;
use App\Services\Billing\SubscriptionBillingService;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Subscription administration for the SaaS-owner platform panel: browse every
 * tenant's subscription, change plan / extend periods, and cancel. Guarded by
 * the `subscriptions.*` central permissions.
 *
 * Normal lifecycle transitions (payment captured, halted, …) arrive via the
 * Razorpay webhook; these endpoints are the manual override for support and
 * offline/custom-plan arrangements.
 */
class BillingSubscriptionController extends Controller
{
    public function __construct(private SubscriptionBillingService $billing) {}

    /** subscription status → tenant status, kept in sync on manual edits. */
    private const TENANT_STATUS = [
        'trialing' => 'trial',
        'active' => 'active',
        'past_due' => 'active', // grace period: workspace stays usable
        'suspended' => 'suspended',
        'cancelled' => 'suspended',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['tenant', 'plan']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($planId = $request->query('plan_id')) {
            $query->where('plan_id', $planId);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // Tenant lives on the landlord connection, Subscription on the
            // central one — resolve matching tenant ids first rather than
            // relying on a cross-connection whereHas subquery.
            $tenantIds = Tenant::query()
                ->where(fn ($q) => $q
                    ->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->pluck('id');
            $query->whereIn('tenant_id', $tenantIds);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Subscription $s) => $this->payload($s))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['tenant', 'plan', 'invoices' => fn ($q) => $q->orderByDesc('created_at')]);

        return response()->json(['data' => $this->payload($subscription, withInvoices: true)]);
    }

    /**
     * Manual adjustments: switch plan, move trial/period dates, or force a
     * non-cancelled status (support fixing webhook drift, offline renewals).
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['sometimes', 'integer', Rule::exists('plans', 'id')],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'suspended'])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'current_period_start' => ['sometimes', 'nullable', 'date'],
            'current_period_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $changes = [];
        if (array_key_exists('plan_id', $data) && $data['plan_id'] !== $subscription->plan_id) {
            $changes['plan'] = [$subscription->plan?->name, Plan::find($data['plan_id'])?->name];
        }
        if (array_key_exists('status', $data) && $data['status'] !== $subscription->status) {
            $changes['status'] = [$subscription->status, $data['status']];
        }

        $subscription->fill($data);
        if (array_key_exists('status', $data)) {
            $subscription->cancelled_at = null; // leaving a cancelled state clears the marker
        }
        $subscription->save();

        if (isset($changes['status'])) {
            $subscription->tenant?->update(['status' => self::TENANT_STATUS[$data['status']]]);
        }

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id, 'changes' => $changes])
            ->event('subscription-updated')
            ->log('Operator updated subscription for "'.($subscription->tenant?->company_name ?? '?').'"');

        $subscription->load(['tenant', 'plan']);

        return response()->json(['data' => $this->payload($subscription)]);
    }

    /**
     * Record an offline payment (bank transfer, UPI, cheque, cash) received
     * outside the gateway. Creates a real paid SubscriptionInvoice — so the
     * money shows up in revenue and in the tenant's invoice history — and,
     * unless the operator opts out, renews the billing period and reactivates
     * the subscription/tenant. An operator hard-lock is never lifted by this.
     */
    public function recordPayment(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['bank_transfer', 'upi', 'cheque', 'cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:255'],
            'extend_period' => ['sometimes', 'boolean'],
        ]);

        $extend = (bool) ($data['extend_period'] ?? true);

        // Raised through the billing service so an offline payment produces the
        // same GST tax invoice — number, place of supply, CGST/SGST or IGST — as a
        // gateway payment. The received amount is the gross, so the tax is
        // back-computed out of it rather than added on top.
        $invoice = $this->billing->recordOfflinePayment($subscription, (float) $data['amount'], [
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
        ]);

        if ($extend) {
            // Rolls the period forward, applies a plan change parked at
            // change-plan time, and reactivates the workspace unless it is
            // operator-locked — which only an explicit unsuspend may lift.
            $this->billing->applyPaidPeriod($subscription);
        }

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'extend_period' => $extend,
            ])
            ->event('offline-payment-recorded')
            ->log('Operator recorded an offline payment for "'.($subscription->tenant?->company_name ?? '?').'"');

        $subscription->load(['tenant', 'plan', 'invoices' => fn ($q) => $q->orderByDesc('created_at')]);

        return response()->json([
            'message' => $extend
                ? 'Payment recorded and subscription renewed.'
                : 'Payment recorded (subscription left unchanged).',
            'data' => $this->payload($subscription, withInvoices: true),
        ]);
    }

    /**
     * Attach or clear the recurring coupon on a subscription.
     *
     * Sending a code pins it (a `repeating`/`forever` coupon then discounts each
     * renewal); sending null clears it. Nothing is redeemed here — the discount is
     * spent when an invoice is raised, so pinning a coupon costs a redemption only
     * once it has actually taken money off something.
     */
    public function applyCoupon(Request $request, CouponService $coupons, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'coupon_code' => ['present', 'nullable', 'string', 'max:40'],
        ]);

        if (trim((string) $data['coupon_code']) === '') {
            $coupons->detachFromSubscription($subscription);

            activity('central-billing')->causedBy($request->user())
                ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id])
                ->event('subscription-coupon-cleared')
                ->log('Operator cleared the coupon on subscription for "'.($subscription->tenant?->company_name ?? '?').'"');

            return response()->json([
                'message' => 'Coupon removed. Future renewals bill at list price.',
                'data' => $this->payload($subscription->fresh(['tenant', 'plan'])),
            ]);
        }

        // Validated in the subscription's own context, so a plan-restricted or
        // already-used-by-this-tenant code is refused with the real reason.
        $coupon = $coupons->validate($data['coupon_code'], [
            'tenant_id' => $subscription->tenant_id,
            'plan_id' => $subscription->plan_id,
            'amount' => (float) ($subscription->plan?->price ?? 0),
            'purchase_type' => 'subscription',
        ]);

        $coupons->attachToSubscription($subscription, $coupon);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id, 'code' => $coupon->code])
            ->event('subscription-coupon-applied')
            ->log("Operator applied coupon {$coupon->code} to subscription for \"".($subscription->tenant?->company_name ?? '?').'"');

        return response()->json([
            'message' => $coupon->code.' applied — '.$coupon->label().'.',
            'data' => $this->payload($subscription->fresh(['tenant', 'plan'])),
        ]);
    }

    /**
     * What the subscription's next invoice will come to: list price, coupon, GST
     * split, total. Read-only — nothing is raised or redeemed.
     */
    public function quote(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'plan_id' => ['sometimes', 'integer', Rule::exists('plans', 'id')],
        ]);

        $plan = isset($data['plan_id']) ? Plan::find($data['plan_id']) : null;

        return response()->json([
            'data' => $this->billing->quote($subscription, $plan, $data['coupon_code'] ?? null),
        ]);
    }

    /**
     * Cancel a subscription. `at_cycle_end` (default true) lets the tenant run
     * out the paid period — the daily subscriptions:process sweep suspends the
     * workspace once it lapses. Immediate cancellation suspends right away.
     */
    public function cancel(Request $request, RazorpayService $razorpay, Subscription $subscription): JsonResponse
    {
        if ($subscription->status === 'cancelled') {
            return response()->json(['message' => 'Subscription is already cancelled.'], 422);
        }

        $data = $request->validate([
            'at_cycle_end' => ['sometimes', 'boolean'],
        ]);
        $atCycleEnd = (bool) ($data['at_cycle_end'] ?? true);

        $warning = null;
        if ($subscription->razorpay_subscription_id && $razorpay->configured()) {
            try {
                $razorpay->cancelSubscription($subscription->razorpay_subscription_id, $atCycleEnd);
            } catch (Throwable $e) {
                report($e);
                $warning = 'Razorpay cancellation failed — cancel it in the Razorpay dashboard too.';
            }
        }

        $subscription->cancelled_at = now();
        if (! $atCycleEnd) {
            $subscription->status = 'cancelled';
            $subscription->tenant?->update(['status' => 'suspended']);
        }
        $subscription->save();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id, 'at_cycle_end' => $atCycleEnd])
            ->event('subscription-cancelled')
            ->log('Operator cancelled subscription for "'.($subscription->tenant?->company_name ?? '?').'"');

        $subscription->load(['tenant', 'plan']);

        return response()->json([
            'message' => $atCycleEnd
                ? 'Subscription will end at the close of the current period.'
                : 'Subscription cancelled and workspace suspended.',
            'warning' => $warning,
            'data' => $this->payload($subscription),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Subscription $s, bool $withInvoices = false): array
    {
        $row = [
            'id' => $s->id,
            'tenant_id' => $s->tenant_id,
            'tenant_name' => $s->tenant?->company_name,
            'tenant_email' => $s->tenant?->email,
            'tenant_status' => $s->tenant?->status,
            'plan_id' => $s->plan_id,
            'plan_name' => $s->plan?->name,
            'plan_price' => $s->plan?->price,
            // A plan the tenant chose itself and hasn't paid for yet. Surfaced so
            // an operator changing the plan can see there is already a change in
            // flight, instead of overwriting it blind.
            'pending_plan_id' => $s->pending_plan_id,
            'pending_plan_name' => $s->pending_plan_id ? Plan::find($s->pending_plan_id)?->name : null,
            'billing_cycle' => $s->plan?->billing_cycle,
            'currency' => $s->plan?->currency ?? 'INR',
            'status' => $s->status,
            'trial_ends_at' => $s->trial_ends_at?->toDateTimeString(),
            'current_period_start' => $s->current_period_start?->toDateTimeString(),
            'current_period_end' => $s->current_period_end?->toDateTimeString(),
            'cancelled_at' => $s->cancelled_at?->toDateTimeString(),
            'razorpay_subscription_id' => $s->razorpay_subscription_id,
            // The recurring coupon pinned to this subscription, and how many
            // renewals it still discounts (null with a code = forever).
            'coupon_code' => $s->coupon_code,
            'coupon_cycles_remaining' => $s->coupon_cycles_remaining,
            'created_at' => $s->created_at?->toDateTimeString(),
        ];

        if ($withInvoices) {
            $row['invoices'] = $s->invoices->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->displayNumber(),
                'type' => $i->type,
                'amount' => $i->amount,
                'discount_amount' => $i->discount_amount,
                'tax_amount' => $i->tax_amount,
                'due_date' => $i->due_date?->toDateString(),
                'currency' => $i->currency,
                'status' => $i->status,
                'payment_gateway_ref' => $i->payment_gateway_ref,
                'payment_method' => $i->payment_method,
                'notes' => $i->notes,
                'paid_at' => $i->paid_at?->toDateTimeString(),
                'refund_amount' => $i->refund_amount,
                'refund_reason' => $i->refund_reason,
                'refunded_at' => $i->refunded_at?->toDateTimeString(),
                'created_at' => $i->created_at?->toDateTimeString(),
            ])->values();
        }

        return $row;
    }
}
