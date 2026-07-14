<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'coupon_id', 'customer_id', 'invoice_id', 'order_value', 'discount_amount', 'created_by',
    ];

    protected $casts = [
        'order_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
