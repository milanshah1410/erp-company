<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promotional coupon the SaaS operator issues against its own subscription and
 * pack pricing (Volume 11 §Billing → Coupons).
 *
 * Not to be confused with {@see Coupon}, the tenant-side retail discount a shop
 * gives its own customers at the counter. This one discounts what the tenant pays
 * the platform.
 *
 * Everything here answers "may this code be used, and for how much" —
 * {@see \App\Services\Billing\CouponService} is the only thing that decides it, so
 * the checks below are shaped as reusable predicates rather than a single
 * validate() that would have to be duplicated at each redemption site.
 */
class PlatformCoupon extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    public const DURATIONS = ['once', 'repeating', 'forever'];

    public const DISCOUNT_TYPES = ['percent', 'flat'];

    public const APPLIES_TO = ['subscription', 'pack', 'both'];

    protected $fillable = [
        'code', 'name', 'description',
        'discount_type', 'discount_value', 'currency', 'max_discount_amount', 'min_amount',
        'duration', 'duration_cycles',
        'applies_to', 'plan_ids', 'new_tenants_only',
        'max_redemptions', 'max_redemptions_per_tenant',
        'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'duration_cycles' => 'integer',
        'plan_ids' => 'array',
        'new_tenants_only' => 'boolean',
        'max_redemptions' => 'integer',
        'max_redemptions_per_tenant' => 'integer',
        'redemption_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(PlatformCouponRedemption::class);
    }

    /** Codes are matched case-insensitively; store them normalised. */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function scopeCode(Builder $query, string $code): Builder
    {
        return $query->where('code', self::normaliseCode($code));
    }

    /** Live now: switched on and inside its date window. */
    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemption_count >= $this->max_redemptions;
    }

    public function allowsPlan(?int $planId): bool
    {
        $ids = $this->plan_ids ?? [];

        if ($ids === [] || $planId === null) {
            return $ids === [];
        }

        return in_array($planId, array_map('intval', $ids), true);
    }

    public function allowsPurchaseType(string $type): bool
    {
        return $this->applies_to === 'both' || $this->applies_to === $type;
    }

    /**
     * How much this coupon takes off a taxable base, in the base's currency.
     *
     * The discount can never exceed the base — a ₹2,000-off coupon on a ₹999 plan
     * makes the invoice free, not negative.
     */
    public function discountOn(float $base): float
    {
        $discount = $this->discount_type === 'percent'
            ? $base * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        if ($this->max_discount_amount !== null) {
            $discount = min($discount, (float) $this->max_discount_amount);
        }

        return round(min($discount, $base), 2);
    }

    /**
     * Cycles this coupon should discount when attached to a subscription:
     * 1 for `once`, the configured count for `repeating`, null for `forever`.
     */
    public function cycleCount(): ?int
    {
        return match ($this->duration) {
            'repeating' => max(1, (int) ($this->duration_cycles ?? 1)),
            'forever' => null,
            default => 1,
        };
    }

    /** Human summary for the panel and the tenant-facing "coupon applied" line. */
    public function label(): string
    {
        $off = $this->discount_type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.').'%'
            : $this->currency.' '.number_format((float) $this->discount_value, 2);

        return $off.' off'.match ($this->duration) {
            'repeating' => ' for '.$this->cycleCount().' billing cycles',
            'forever' => ' for the life of the subscription',
            default => '',
        };
    }
}
