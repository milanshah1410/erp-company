<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GST tax invoice the SaaS operator raises against a tenant's subscription.
 *
 * `amount` is the gross total the tenant pays — the figure every revenue query and
 * gateway reconciliation uses — and the tax columns decompose it:
 *
 *     taxable_amount = subtotal - discount_amount
 *     tax_amount     = cgst_amount + sgst_amount + igst_amount
 *     amount         = taxable_amount + tax_amount
 *
 * {@see \App\Services\Billing\SubscriptionBillingService} is the only writer of
 * that decomposition; nothing else should compose an invoice by hand.
 */
class SubscriptionInvoice extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    public const STATUSES = ['created', 'paid', 'failed', 'refunded', 'void'];

    public const TYPES = ['subscription', 'pack', 'adjustment'];

    protected $fillable = [
        'number', 'subscription_id', 'type', 'period_start', 'period_end', 'due_date',
        'amount', 'currency', 'status',
        'subtotal', 'discount_amount', 'taxable_amount', 'tax_rate',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount',
        'place_of_supply', 'seller_state_code', 'is_interstate',
        'customer_gstin', 'customer_legal_name', 'sac_code',
        'platform_coupon_id', 'coupon_code', 'tenant_pack_id',
        'payment_gateway_ref', 'payment_method', 'notes', 'paid_at',
        'refund_amount', 'refund_reason', 'refunded_at',
        'attempt_count', 'last_attempt_at', 'reminder_stage', 'last_reminder_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'is_interstate' => 'boolean',
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'reminder_stage' => 'integer',
        'last_reminder_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    public function tenantPack(): BelongsTo
    {
        return $this->belongsTo(TenantPack::class, 'tenant_pack_id');
    }

    /** Raised but not yet settled — what a renewal/dunning sweep works on. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['created', 'failed']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString());
    }

    public function isOutstanding(): bool
    {
        return in_array($this->status, ['created', 'failed'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOutstanding()
            && $this->due_date !== null
            && $this->due_date->isBefore(now()->startOfDay());
    }

    /** Whole days past due (0 when not overdue). */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_date->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * Display number. Every row written since GST numbering landed has one; the
     * fallback keeps a hand-inserted row readable rather than blank.
     */
    public function displayNumber(): string
    {
        return $this->number ?: sprintf('SUB-%06d', $this->id);
    }

    /** True when GST was charged (a zero-rated/export invoice has no tax). */
    public function hasTax(): bool
    {
        return (float) $this->tax_amount > 0;
    }
}
