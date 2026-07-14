<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseIndentItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'purchase_indent_id', 'product_id', 'variant_id',
        'quantity', 'estimated_cost', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'estimated_cost' => 'decimal:4',
    ];

    public function purchaseIndent(): BelongsTo
    {
        return $this->belongsTo(PurchaseIndent::class);
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
