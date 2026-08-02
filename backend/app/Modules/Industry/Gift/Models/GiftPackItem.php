<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing inside a hamper.
 *
 * A plain line: what goes in, how many, and where in the list it is shown. The
 * order is load-bearing in a shop that prints its contents on a card — "the good
 * chocolate first" is a merchandising decision, not an accident of insertion.
 *
 * No rate column. Money on a hamper line would be a fourth answer to what the
 * pack is worth, and the three that exist already (the product's cost, the
 * product's price, and what a build was actually costed at) are the honest ones.
 */
class GiftPackItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pack_id', 'product_id', 'variant_id', 'quantity', 'sort_order', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'quantity' => 1,
        'sort_order' => 0,
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(GiftPack::class, 'pack_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * What one of this component is carried at. The product's cost price rather
     * than a warehouse average, because this feeds the *estimate* a shop prices a
     * hamper against; the build itself is costed by StockService out of the
     * layers it actually consumed.
     */
    public function unitCost(): float
    {
        return (float) ($this->product?->cost_price ?? 0);
    }
}
