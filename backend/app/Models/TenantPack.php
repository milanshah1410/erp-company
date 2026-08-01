<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One workspace's grant of a marketplace pack — bought, comped by an operator, or
 * bundled with its plan.
 *
 * This row is the entitlement of record: {@see \App\Services\Billing\PackEntitlementService}
 * reads every live grant for a tenant and projects the union of their modules into
 * the tenant's `pack_modules` setting. Cancelled and expired rows are kept as
 * billing history, so "live" is a status + date question rather than the row's
 * existence.
 */
class TenantPack extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    public const SOURCES = ['purchased', 'comped', 'bundled'];

    protected $fillable = [
        'tenant_id', 'platform_pack_id', 'status', 'source',
        'price', 'currency', 'starts_at', 'ends_at',
        'subscription_invoice_id', 'granted_by', 'cancelled_at', 'notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(PlatformPack::class, 'platform_pack_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /**
     * Grants that entitle the workspace right now: active, started, not expired.
     * A null `ends_at` runs until cancelled.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->toDateString()));
    }

    public function isLive(): bool
    {
        return $this->status === 'active'
            && ! ($this->starts_at && $this->starts_at->isAfter(now()))
            && ! ($this->ends_at && $this->ends_at->isBefore(now()->startOfDay()));
    }
}
