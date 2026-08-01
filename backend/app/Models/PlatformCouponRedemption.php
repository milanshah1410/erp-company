<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a platform coupon: which workspace, on which invoice, for how much.
 *
 * The coupon's own `redemption_count` is what enforcement reads (it is
 * incremented under a row lock); this table is the trail behind that number —
 * needed to answer "what did the launch promo cost us" and to enforce the
 * per-tenant cap.
 */
class PlatformCouponRedemption extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'platform_coupon_id', 'tenant_id', 'subscription_id', 'subscription_invoice_id',
        'code', 'discount_amount', 'currency', 'redeemed_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
