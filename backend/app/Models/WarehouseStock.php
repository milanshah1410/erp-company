<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id',
        'quantity', 'reserved_quantity', 'average_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'average_cost' => 'decimal:4',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Quantity available to sell/transfer (on-hand minus reserved). */
    public function availableQuantity(): float
    {
        return (float) $this->quantity - (float) $this->reserved_quantity;
    }
}
