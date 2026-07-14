<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturingOrderMaterial extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'manufacturing_order_id', 'product_id', 'variant_id',
        'quantity', 'unit_cost', 'total_cost', 'consumed_quantity', 'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'consumed_quantity' => 'decimal:3',
    ];

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
