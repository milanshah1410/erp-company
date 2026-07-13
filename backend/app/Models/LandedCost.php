<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCost extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'freight_amount', 'duty_amount', 'other_amount',
        'allocation_method', 'total_amount',
    ];

    protected $casts = [
        'freight_amount' => 'decimal:2',
        'duty_amount' => 'decimal:2',
        'other_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
