<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single authority on platform coupons: whether a code may be used, what it
 * takes off, and the recording of the fact that it was used.
 *
 * Every entry point that accepts a code — public signup, the tenant's billing
 * page, an operator applying one by hand — goes through {@see validate()}, so the
 * eligibility rules exist once. The messages it throws are written to be shown to
 * a customer as-is: "this code has expired" is more use than "invalid coupon".
 *
 * Redemption is the part that has to be careful. `redemption_count` is the cap's
 * enforcement counter, so it is re-checked under a row lock at the moment of use —
 * validating a code and spending it are separate events, and a 100-redemption
 * launch promo shared on social media will absolutely be submitted 300 times in
 * the same second.
 */
class CouponService
{
    /** Look a code up without judging it. */
    public function find(?string $code): ?PlatformCoupon
    {
        $code = trim((string) $code);

        return $code === '' ? null : PlatformCoupon::query()->code($code)->first();
    }

    /**
     * Resolve a code and assert it may be spent in this context.
     *
     * @param  array{
     *     tenant_id?: int|null, plan_id?: int|null, amount?: float,
     *     purchase_type?: string, is_new_tenant?: bool
     * }  $context
     *
     * @throws ValidationException with a customer-facing message on `coupon_code`
     */
    public function validate(?string $code, array $context = []): PlatformCoupon
    {
        $coupon = $this->find($code);

        if (! $coupon) {
            $this->reject("That coupon code isn't recognised.");
        }

        if (! $coupon->is_active) {
            $this->reject('That coupon is no longer available.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $this->reject('That coupon is not valid yet — it starts on '.$coupon->starts_at->format('d M Y').'.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            $this->reject('That coupon expired on '.$coupon->ends_at->format('d M Y').'.');
        }
        if ($coupon->isExhausted()) {
            $this->reject('That coupon has reached its redemption limit.');
        }

        $purchaseType = $context['purchase_type'] ?? 'subscription';
        if (! $coupon->allowsPurchaseType($purchaseType)) {
            $this->reject($purchaseType === 'pack'
                ? 'That coupon cannot be used on marketplace packs.'
                : 'That coupon cannot be used on a plan subscription.');
        }

        if (array_key_exists('plan_id', $context) && ! $coupon->allowsPlan($context['plan_id'])) {
            $this->reject('That coupon does not apply to the selected plan.');
        }

        $amount = isset($context['amount']) ? (float) $context['amount'] : null;
        if ($coupon->min_amount !== null && $amount !== null && $amount < (float) $coupon->min_amount) {
            $this->reject('That coupon needs a minimum order of '.$coupon->currency.' '.number_format((float) $coupon->min_amount, 2).'.');
        }

        $tenantId = $context['tenant_id'] ?? null;

        if ($coupon->new_tenants_only) {
            $isNew = $context['is_new_tenant'] ?? ($tenantId === null || ! $this->hasEverPaid((int) $tenantId));
            if (! $isNew) {
                $this->reject('That coupon is only valid on a first subscription.');
            }
        }

        if ($tenantId !== null && $this->tenantRedemptionCount($coupon, (int) $tenantId) >= $coupon->max_redemptions_per_tenant) {
            $this->reject('You have already used that coupon.');
        }

        return $coupon;
    }

    /**
     * Validate a code and return the discount it would give, without spending it.
     * Returns null (never throws) for a blank code, so a "no coupon" checkout and
     * a coupon checkout can share one code path.
     *
     * @return array{coupon: PlatformCoupon, discount: float, label: string}|null
     */
    public function quote(?string $code, float $base, array $context = []): ?array
    {
        if (trim((string) $code) === '') {
            return null;
        }

        $coupon = $this->validate($code, $context + ['amount' => $base]);

        return [
            'coupon' => $coupon,
            'discount' => $coupon->discountOn($base),
            'label' => $coupon->label(),
        ];
    }

    /**
     * Record a redemption and consume one unit of the coupon's global cap.
     *
     * Re-checks the cap under a row lock: {@see validate()} ran earlier, possibly
     * seconds earlier and in a different request, and cannot speak for the
     * counter's state now. Returns null when the coupon was exhausted in the
     * meantime, which the caller should treat as "no discount", not as an error —
     * the money has usually already moved by this point.
     */
    public function redeem(
        PlatformCoupon $coupon,
        int $tenantId,
        float $discount,
        ?Subscription $subscription = null,
        ?SubscriptionInvoice $invoice = null,
    ): ?PlatformCouponRedemption {
        if ($discount <= 0) {
            return null;
        }

        $connection = config('saas.central_connection', 'central');

        return DB::connection($connection)->transaction(function () use ($coupon, $tenantId, $discount, $subscription, $invoice) {
            /** @var PlatformCoupon|null $locked */
            $locked = PlatformCoupon::query()->whereKey($coupon->id)->lockForUpdate()->first();

            if (! $locked || $locked->isExhausted()) {
                return null;
            }

            $locked->increment('redemption_count');

            return PlatformCouponRedemption::create([
                'platform_coupon_id' => $locked->id,
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription?->id,
                'subscription_invoice_id' => $invoice?->id,
                'code' => $locked->code,
                'discount_amount' => round($discount, 2),
                'currency' => $invoice?->currency ?? $locked->currency,
                'redeemed_at' => now(),
            ]);
        });
    }

    /**
     * Pin a coupon to a subscription so renewals keep applying it.
     *
     * A `once` coupon is pinned with a single cycle: the invoice that spends it
     * detaches it again via {@see consumeCycle()}. That keeps one rule for all
     * three durations instead of special-casing the common one.
     */
    public function attachToSubscription(Subscription $subscription, PlatformCoupon $coupon): void
    {
        $subscription->update([
            'platform_coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'coupon_cycles_remaining' => $coupon->cycleCount(),
        ]);
    }

    /**
     * Spend one billing cycle of the subscription's pinned coupon, detaching it
     * once the last cycle is used. A `forever` coupon (null cycles) is untouched.
     */
    public function consumeCycle(Subscription $subscription): void
    {
        if ($subscription->platform_coupon_id === null || $subscription->coupon_cycles_remaining === null) {
            return;
        }

        $remaining = max(0, $subscription->coupon_cycles_remaining - 1);

        $subscription->update($remaining > 0
            ? ['coupon_cycles_remaining' => $remaining]
            : ['platform_coupon_id' => null, 'coupon_code' => null, 'coupon_cycles_remaining' => null]);
    }

    public function detachFromSubscription(Subscription $subscription): void
    {
        $subscription->update([
            'platform_coupon_id' => null,
            'coupon_code' => null,
            'coupon_cycles_remaining' => null,
        ]);
    }

    /**
     * Undo the redemption an invoice consumed, because the invoice was voided.
     *
     * Redemption happens when the discount is committed to an invoice, not when
     * the money arrives — so a voided invoice has to give the code back, or a
     * 100-use promo could be exhausted by invoices nobody ever paid.
     */
    public function release(SubscriptionInvoice $invoice): void
    {
        $connection = config('saas.central_connection', 'central');

        DB::connection($connection)->transaction(function () use ($invoice): void {
            $redemptions = PlatformCouponRedemption::query()
                ->where('subscription_invoice_id', $invoice->id)
                ->get();

            foreach ($redemptions as $redemption) {
                $coupon = PlatformCoupon::query()->whereKey($redemption->platform_coupon_id)->lockForUpdate()->first();
                if ($coupon && $coupon->redemption_count > 0) {
                    $coupon->decrement('redemption_count');
                }
                $redemption->delete();
            }
        });
    }

    /** How many times this tenant has already redeemed the coupon. */
    public function tenantRedemptionCount(PlatformCoupon $coupon, int $tenantId): int
    {
        return PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /** Has this workspace ever paid us? Decides `new_tenants_only` eligibility. */
    public function hasEverPaid(int $tenantId): bool
    {
        return SubscriptionInvoice::query()
            ->where('status', 'paid')
            ->whereHas('subscription', fn ($q) => $q->where('tenant_id', $tenantId))
            ->exists();
    }

    /**
     * @throws ValidationException
     *
     * @return never
     */
    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['coupon_code' => [$message]]);
    }
}
