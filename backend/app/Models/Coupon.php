<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'description', 'discount_type', 'discount_value', 'max_discount',
        'min_order_value', 'usage_limit', 'usage_limit_per_customer', 'used_count',
        'valid_from', 'valid_to', 'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'used_count' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Check whether this coupon may be applied to an order of $orderValue for
     * the given customer. Returns [valid, reason]; reason is null when valid.
     *
     * @return array{0: bool, 1: ?string}
     */
    public function checkEligibility(float $orderValue, ?int $customerId = null): array
    {
        if (! $this->is_active) {
            return [false, 'This coupon is not active.'];
        }

        $today = now()->startOfDay();
        if ($this->valid_from && $today->lt($this->valid_from->startOfDay())) {
            return [false, 'This coupon is not valid yet.'];
        }
        if ($this->valid_to && $today->gt($this->valid_to->startOfDay())) {
            return [false, 'This coupon has expired.'];
        }

        if ($orderValue < (float) $this->min_order_value) {
            return [false, 'Order value is below the minimum required for this coupon.'];
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return [false, 'This coupon has reached its usage limit.'];
        }

        if ($this->usage_limit_per_customer !== null && $customerId !== null) {
            $customerUses = $this->redemptions()->where('customer_id', $customerId)->count();
            if ($customerUses >= $this->usage_limit_per_customer) {
                return [false, 'This customer has already used this coupon the maximum number of times.'];
            }
        }

        return [true, null];
    }

    /** Discount this coupon yields on $orderValue (0 when ineligible). */
    public function discountFor(float $orderValue): float
    {
        if ($this->discount_type === 'flat') {
            $discount = (float) $this->discount_value;
        } else {
            $discount = $orderValue * (float) $this->discount_value / 100;
            if ($this->max_discount !== null) {
                $discount = min($discount, (float) $this->max_discount);
            }
        }

        // Never discount more than the order itself.
        return round(min($discount, $orderValue), 2);
    }
}
