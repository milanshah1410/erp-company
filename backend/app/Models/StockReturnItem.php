<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReturnItem extends Model
{
    protected $fillable = [
        'stock_return_id', 'product_id', 'variant_id', 'quantity', 'unit_cost', 'reason',
        'batch_number', 'expiry_date', 'serials', 'batch_allocations',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'expiry_date' => 'date',
        'serials' => 'array',
        'batch_allocations' => 'array',
    ];

    public function stockReturn(): BelongsTo
    {
        return $this->belongsTo(StockReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
