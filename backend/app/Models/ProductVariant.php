<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'rfid_tag', 'name', 'attributes',
        'cost_price', 'selling_price', 'is_active',
        // Cosmetics plugin: the shade this variant is, as a foreign key rather
        // than only a word in `attributes`, so "which shades of this range are we
        // out of" is a join. The relation is attached by CosmeticsPlugin::boot().
        'cosmetic_shade_id',
    ];

    protected $casts = [
        'attributes' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            if (empty($variant->barcode) && ! empty($variant->sku)) {
                $variant->barcode = $variant->sku;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
