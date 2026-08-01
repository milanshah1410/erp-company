<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlatformCoupon;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\PlatformBillingProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Composes the money on a subscription invoice: price → coupon → GST → total, and
 * the lifecycle around it (issue, settle, void).
 *
 * This is the only place that writes the tax decomposition on
 * {@see SubscriptionInvoice}, so the identity documented on that model holds for
 * every row regardless of which path created it — a renewal sweep, an operator
 * recording a bank transfer, or a Razorpay payment landing on the webhook.
 *
 * Two things are deliberate:
 *
 *  - **Coupons are redeemed at issue, not at payment.** The discount is committed
 *    to a numbered invoice at that moment, so that is when a use of the code is
 *    spent; {@see void()} gives it back. Waiting for payment would let a promo be
 *    handed to unlimited tenants who all pay next week.
 *  - **The tenant's GST identity is snapshotted onto the invoice.** Fixing a typo
 *    in a GSTIN tomorrow must not silently rewrite an invoice already issued.
 */
class SubscriptionBillingService
{
    public function __construct(
        private PlatformTaxCalculator $tax,
        private CouponService $coupons,
        private InvoiceNumberService $numbers,
    ) {}

    /**
     * What the subscription's next invoice will look like, without writing
     * anything. Used by the tenant's billing page ("you'll pay ₹1,179 incl. GST"),
     * the operator's Renewals panel, and issue() itself.
     *
     * @param  string|null  $couponCode  Overrides the subscription's pinned coupon.
     *                                   An invalid code throws, so a checkout can
     *                                   surface the reason.
     * @return array<string, mixed>
     */
    public function quote(Subscription $subscription, ?Plan $plan = null, ?string $couponCode = null): array
    {
        $plan ??= $subscription->pendingPlan ?: $subscription->plan;
        $tenant = $subscription->tenant;
        $currency = $plan?->currency ?? 'INR';
        $gross = (float) ($plan?->price ?? 0);

        $coupon = $this->resolveCoupon($subscription, $plan, $couponCode, $gross);
        $discount = $coupon?->discountOn($this->taxableBase($gross, $currency)) ?? 0.0;

        $breakdown = $this->tax->breakdown($gross, $discount, $currency, $tenant);

        return $breakdown + [
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name,
            'billing_cycle' => $plan?->billing_cycle,
            'list_price' => round($gross, 2),
            'coupon_id' => $coupon?->id,
            'coupon_code' => $coupon?->code,
            'coupon_label' => $coupon?->label(),
            'period_start' => ($subscription->current_period_end?->isFuture()
                ? $subscription->current_period_end->copy()
                : Carbon::now())->toDateString(),
            'period_end' => $subscription->nextPeriodEnd($plan)->toDateString(),
        ];
    }

    /**
     * Raise the next invoice for a subscription (status `created`, unpaid).
     *
     * @param  array{
     *     plan?: Plan|null, coupon_code?: string|null, due_date?: Carbon|string|null,
     *     period_start?: Carbon|string|null, period_end?: Carbon|string|null,
     *     notes?: string|null
     * }  $options
     */
    public function issue(Subscription $subscription, array $options = []): SubscriptionInvoice
    {
        $plan = $options['plan'] ?? ($subscription->pendingPlan ?: $subscription->plan);
        $quote = $this->quote($subscription, $plan, $options['coupon_code'] ?? null);

        $dueDays = (int) config('saas.billing.renewal.due_days', 7);
        $dueDate = isset($options['due_date'])
            ? Carbon::parse($options['due_date'])
            : Carbon::now()->addDays($dueDays);

        $connection = config('saas.central_connection', 'central');

        $invoice = DB::connection($connection)->transaction(function () use ($subscription, $quote, $options, $dueDate) {
            $invoice = SubscriptionInvoice::create($this->taxColumns($quote) + [
                'number' => $this->numbers->next(),
                'subscription_id' => $subscription->id,
                'type' => 'subscription',
                'status' => 'created',
                'period_start' => isset($options['period_start'])
                    ? Carbon::parse($options['period_start'])->toDateString()
                    : $quote['period_start'],
                'period_end' => isset($options['period_end'])
                    ? Carbon::parse($options['period_end'])->toDateString()
                    : $quote['period_end'],
                'due_date' => $dueDate->toDateString(),
                'notes' => $options['notes'] ?? null,
            ]);

            $this->spendCoupon($subscription, $invoice, $quote);

            return $invoice;
        });

        return $invoice;
    }

    /**
     * Record an invoice for money a gateway has already collected.
     *
     * Idempotent on the gateway payment id — the checkout callback and the webhook
     * both call this for the same payment, and only one invoice may result. The
     * collected total is authoritative, so GST is back-computed out of it rather
     * than added on top.
     */
    public function recordGatewayPayment(
        Subscription $subscription,
        string $paymentRef,
        float $total,
        string $currency = 'INR',
        ?Carbon $paidAt = null,
    ): SubscriptionInvoice {
        $existing = SubscriptionInvoice::query()->where('payment_gateway_ref', $paymentRef)->first();
        if ($existing) {
            return $existing;
        }

        $breakdown = $this->tax->breakdownFromTotal($total, $currency, $subscription->tenant);
        $connection = config('saas.central_connection', 'central');

        return DB::connection($connection)->transaction(function () use ($subscription, $paymentRef, $breakdown, $paidAt) {
            // Re-check inside the transaction: the webhook and the browser
            // callback routinely arrive together.
            $existing = SubscriptionInvoice::query()
                ->where('payment_gateway_ref', $paymentRef)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return SubscriptionInvoice::create($this->taxColumns($breakdown) + [
                'number' => $this->numbers->next(),
                'subscription_id' => $subscription->id,
                'type' => 'subscription',
                'status' => 'paid',
                'payment_gateway_ref' => $paymentRef,
                'paid_at' => $paidAt ?? now(),
                'period_start' => $subscription->current_period_start?->toDateString(),
                'period_end' => $subscription->current_period_end?->toDateString(),
            ]);
        });
    }

    /**
     * Raise a paid invoice for money received outside any gateway (bank transfer,
     * UPI, cheque, cash). GST is back-computed from the amount actually received.
     *
     * @param  array{method?: string, reference?: string|null, notes?: string|null, paid_at?: Carbon|string|null}  $options
     */
    public function recordOfflinePayment(Subscription $subscription, float $total, array $options = []): SubscriptionInvoice
    {
        $currency = $subscription->plan?->currency ?? 'INR';
        $breakdown = $this->tax->breakdownFromTotal($total, $currency, $subscription->tenant);

        return SubscriptionInvoice::create($this->taxColumns($breakdown) + [
            'number' => $this->numbers->next(),
            'subscription_id' => $subscription->id,
            'type' => 'subscription',
            'status' => 'paid',
            'payment_gateway_ref' => $options['reference'] ?? null,
            'payment_method' => $options['method'] ?? 'other',
            'notes' => $options['notes'] ?? null,
            'paid_at' => isset($options['paid_at']) ? Carbon::parse($options['paid_at']) : now(),
            'period_start' => $subscription->current_period_start?->toDateString(),
            'period_end' => $subscription->current_period_end?->toDateString(),
        ]);
    }

    /**
     * Settle an outstanding invoice and roll the subscription forward.
     *
     * The invoice carries the period it was raised for, so renewing from it keeps
     * the billing calendar stable even when payment lands late — a renewal paid
     * three days after the period started still ends on the original date, rather
     * than quietly gifting the tenant three days on every late payment.
     *
     * An operator hard-lock is never lifted here; only an explicit unsuspend may.
     *
     * @param  array{method?: string|null, reference?: string|null, paid_at?: Carbon|string|null, extend?: bool}  $options
     */
    public function markPaid(SubscriptionInvoice $invoice, array $options = []): SubscriptionInvoice
    {
        if ($invoice->status === 'paid') {
            return $invoice;
        }

        $subscription = $invoice->subscription;
        $paidAt = isset($options['paid_at']) ? Carbon::parse($options['paid_at']) : now();

        $invoice->update([
            'status' => 'paid',
            'paid_at' => $paidAt,
            'payment_method' => $options['method'] ?? $invoice->payment_method,
            'payment_gateway_ref' => $options['reference'] ?? $invoice->payment_gateway_ref,
            'attempt_count' => $invoice->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        if ($subscription && ($options['extend'] ?? true)) {
            $this->applyPaidPeriod($subscription, $invoice);
        }

        return $invoice->refresh();
    }

    /**
     * Cancel an unpaid invoice. The coupon it consumed is released, and the number
     * stays on the row: a GST series may not have holes, so a cancelled tax invoice
     * remains in the books as `void` rather than being deleted.
     */
    public function void(SubscriptionInvoice $invoice, ?string $reason = null): SubscriptionInvoice
    {
        if ($invoice->status === 'paid') {
            return $invoice;
        }

        $this->coupons->release($invoice);

        $invoice->update([
            'status' => 'void',
            'notes' => trim((string) $invoice->notes.($reason ? "\nVoided: {$reason}" : '')) ?: null,
        ]);

        return $invoice->refresh();
    }

    /**
     * Move the subscription onto the period an invoice paid for, and bring the
     * workspace back up.
     */
    public function applyPaidPeriod(Subscription $subscription, ?SubscriptionInvoice $invoice = null): void
    {
        // A plan change parked at change-plan time takes effect on payment.
        $plan = $subscription->pendingPlan ?: $subscription->plan;

        $periodEnd = $invoice?->period_end
            ? $invoice->period_end->copy()
            : $subscription->nextPeriodEnd($plan);

        $periodStart = $invoice?->period_start
            ? $invoice->period_start->copy()
            : Carbon::now();

        $subscription->update([
            'plan_id' => $plan?->id ?? $subscription->plan_id,
            'pending_plan_id' => null,
            'status' => 'active',
            'cancelled_at' => null,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);

        $tenant = $subscription->tenant;
        if ($tenant && ! $tenant->isOperatorSuspended()) {
            $tenant->update(['status' => 'active', 'suspended_by' => null]);
        }
    }

    /**
     * The invoice this subscription already has open for the given period end, if
     * any. Used by the renewal sweep so a re-run never double-bills.
     */
    public function openInvoiceForPeriod(Subscription $subscription, Carbon $periodEnd): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', 'subscription')
            ->whereIn('status', ['created', 'failed', 'paid'])
            ->whereDate('period_end', $periodEnd->toDateString())
            ->first();
    }

    /**
     * Snapshot of the seller + customer identity for an invoice PDF. Read from the
     * invoice row where it was captured, falling back to the live profile for
     * legacy rows raised before GST fields existed.
     *
     * @return array<string, mixed>
     */
    public function documentContext(SubscriptionInvoice $invoice, ?Tenant $tenant = null): array
    {
        $profile = PlatformBillingProfile::all();

        return [
            'seller' => [
                'name' => config('app.name'),
                'legal_name' => $profile['legal_name'],
                'address' => $profile['address'],
                'gstin' => $profile['gstin'],
                'state_code' => $invoice->seller_state_code ?: $profile['state_code'],
                'pan' => $profile['pan'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
            ],
            'customer' => [
                'name' => $invoice->customer_legal_name ?: $tenant?->billingName(),
                'gstin' => $invoice->customer_gstin ?: $tenant?->billing_gstin,
                'address' => $tenant?->billing_address,
                'email' => $tenant?->email,
                'state_code' => $invoice->place_of_supply,
            ],
            'terms' => $profile['invoice_terms'],
        ];
    }

    /**
     * The coupon that should discount this invoice: an explicitly supplied code
     * (validated, so a bad one is reported) or the subscription's pinned one.
     */
    private function resolveCoupon(Subscription $subscription, ?Plan $plan, ?string $code, float $gross): ?PlatformCoupon
    {
        if (trim((string) $code) !== '') {
            return $this->coupons->validate($code, [
                'tenant_id' => $subscription->tenant_id,
                'plan_id' => $plan?->id,
                'amount' => $this->taxableBase($gross, $plan?->currency ?? 'INR'),
                'purchase_type' => 'subscription',
            ]);
        }

        if (! $subscription->hasLiveCoupon()) {
            return null;
        }

        $pinned = $subscription->coupon;

        // A pinned coupon that has since been switched off or has run past its end
        // date stops discounting — the promo ended, and honouring it forever
        // because it was once attached is not what "expires on" means.
        return $pinned && $pinned->isCurrentlyValid() && $pinned->allowsPlan($plan?->id) ? $pinned : null;
    }

    /**
     * Record the coupon on the invoice's ledger: spend one redemption, count the
     * cycle down on the subscription, and pin a multi-cycle coupon that arrived as
     * a one-off code so the next renewal keeps it.
     *
     * @param  array<string, mixed>  $quote
     */
    private function spendCoupon(Subscription $subscription, SubscriptionInvoice $invoice, array $quote): void
    {
        if (empty($quote['coupon_id']) || (float) $quote['discount_amount'] <= 0) {
            return;
        }

        $coupon = PlatformCoupon::find($quote['coupon_id']);
        if (! $coupon) {
            return;
        }

        $this->coupons->redeem(
            $coupon,
            (int) $subscription->tenant_id,
            (float) $quote['discount_amount'],
            $subscription,
            $invoice,
        );

        if ($subscription->platform_coupon_id === $coupon->id) {
            $this->coupons->consumeCycle($subscription);
        } elseif ($coupon->duration !== 'once') {
            // Arrived as a typed code but discounts more than one cycle: pin it,
            // then immediately consume the cycle this invoice used.
            $this->coupons->attachToSubscription($subscription, $coupon);
            $this->coupons->consumeCycle($subscription->refresh());
        }
    }

    /**
     * The base a percentage coupon applies to — the taxable value, never the
     * gross. "20% off" on a tax-inclusive price would otherwise quietly discount
     * the government's share too.
     */
    private function taxableBase(float $gross, string $currency): float
    {
        $rate = strtoupper($currency) === 'INR' ? PlatformBillingProfile::effectiveTaxRate() : 0.0;

        return PlatformBillingProfile::get('prices_include_tax') && $rate > 0
            ? round($gross / (1 + $rate / 100), 2)
            : round($gross, 2);
    }

    /**
     * Map a tax breakdown onto invoice columns.
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function taxColumns(array $quote): array
    {
        return [
            'amount' => $quote['amount'],
            'currency' => $quote['currency'],
            'subtotal' => $quote['subtotal'],
            'discount_amount' => $quote['discount_amount'],
            'taxable_amount' => $quote['taxable_amount'],
            'tax_rate' => $quote['tax_rate'],
            'cgst_amount' => $quote['cgst_amount'],
            'sgst_amount' => $quote['sgst_amount'],
            'igst_amount' => $quote['igst_amount'],
            'tax_amount' => $quote['tax_amount'],
            'place_of_supply' => $quote['place_of_supply'],
            'seller_state_code' => $quote['seller_state_code'],
            'is_interstate' => $quote['is_interstate'],
            'customer_gstin' => $quote['customer_gstin'],
            'customer_legal_name' => $quote['customer_legal_name'],
            'sac_code' => $quote['sac_code'],
            'platform_coupon_id' => $quote['coupon_id'] ?? null,
            'coupon_code' => $quote['coupon_code'] ?? null,
        ];
    }
}
