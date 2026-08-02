<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the planogram: this product belongs on that shelf, this many
 * facings wide, kept between these two quantities.
 *
 * It states an intention, which is what separates it from stock. `StockLocation`
 * says what *is* on the shelf right now; this says what *should* be, and the
 * difference between the two is the replenishment list — the only reason either
 * number is interesting to a supermarket.
 */
class ShelfPlacement extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'location_id', 'product_id', 'variant_id',
        'facings', 'min_qty', 'max_qty', 'sequence', 'is_active',
    ];

    protected $casts = [
        'facings' => 'integer',
        'min_qty' => 'decimal:3',
        'max_qty' => 'decimal:3',
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
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
     * What is actually on the shelf.
     *
     * Matched on the same triple the placement is keyed by, so a placement for a
     * specific variant reads that variant's shelf stock and a plain-product one
     * reads the product's.
     */
    public function onShelf(): float
    {
        $row = StockLocation::query()
            ->where('location_id', $this->location_id)
            ->where('product_id', $this->product_id)
            ->when(
                $this->variant_id === null,
                static fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $this->variant_id),
            )
            ->first();

        return round((float) ($row->quantity ?? 0), 3);
    }
}
