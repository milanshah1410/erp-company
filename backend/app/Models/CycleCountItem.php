<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleCountItem extends Model
{
    protected $fillable = [
        'cycle_count_id', 'product_id', 'variant_id',
        'system_quantity', 'counted_quantity', 'variance', 'unit_cost', 'notes',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'variance' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function cycleCount(): BelongsTo
    {
        return $this->belongsTo(CycleCount::class);
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
