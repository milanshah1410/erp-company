<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfqItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'rfq_id', 'product_id', 'variant_id', 'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class);
    }
}
