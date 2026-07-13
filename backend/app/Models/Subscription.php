<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at',
        'razorpay_subscription_id', 'razorpay_customer_id',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
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
