<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'tenant_id', 'plan_id', 'pending_plan_id', 'status', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at',
        'razorpay_subscription_id', 'razorpay_customer_id',
        'platform_coupon_id', 'coupon_code', 'coupon_cycles_remaining',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'coupon_cycles_remaining' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The plan awaiting payment confirmation (set at change-plan time). */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** The recurring coupon pinned to this subscription, if one is attached. */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    /**
     * Does the pinned coupon still discount the next invoice?
     *
     * A null `coupon_cycles_remaining` with a coupon attached is a `forever`
     * coupon; a positive count is a `repeating` one mid-run. The count is
     * decremented by CouponService as each invoice consumes a cycle.
     */
    public function hasLiveCoupon(): bool
    {
        return $this->platform_coupon_id !== null
            && ($this->coupon_cycles_remaining === null || $this->coupon_cycles_remaining > 0);
    }

    /** The end of the period this subscription's next invoice should cover. */
    public function nextPeriodEnd(?Plan $plan = null): Carbon
    {
        $plan ??= $this->plan;

        $base = $this->current_period_end?->isFuture()
            ? $this->current_period_end->copy()
            : Carbon::now();

        return $plan?->billing_cycle === 'yearly' ? $base->addYear() : $base->addMonth();
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function active(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true)
            && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        if ($this->status === 'active' && $this->current_period_end) {
            return $this->current_period_end->isPast();
        }

        if ($this->status === 'trialing' && $this->trial_ends_at) {
            return $this->trial_ends_at->isPast();
        }

        return in_array($this->status, ['cancelled', 'suspended'], true);
    }

    /**
     * Whole days left in the trial (0 once it has ended). Used by the
     * trial-countdown banner and reminder emails.
     */
    public function trialDaysLeft(): int
    {
        if (! $this->trial_ends_at) {
            return 0;
        }

        return max(0, (int) ceil(Carbon::now()->diffInDays($this->trial_ends_at, false)));
    }
}
