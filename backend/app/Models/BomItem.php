<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'bom_id', 'product_id', 'variant_id',
        'quantity', 'wastage_percent', 'unit_cost', 'total_cost', 'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'wastage_percent' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
