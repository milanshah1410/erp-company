<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One garment on a trial slip.
 *
 * `returned_quantity` and `sold_quantity` are the two ways a piece can come off
 * the slip, and what they do not account for is still with the customer. Storing
 * the outstanding figure as well would be a third number that can disagree with
 * the other two, so it is derived.
 */
class GarmentTrialItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'trial_id', 'product_id', 'variant_id',
        'quantity', 'unit_price', 'returned_quantity', 'sold_quantity', 'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'returned_quantity' => 'decimal:3',
        'sold_quantity' => 'decimal:3',
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(GarmentTrial::class, 'trial_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Still with the customer. Clamped at zero — never a negative exposure. */
    public function outstandingQuantity(): float
    {
        return max(0.0, round(
            (float) $this->quantity - (float) $this->returned_quantity - (float) $this->sold_quantity,
            3,
        ));
    }

    /** How this line reads on the slip: what it is, in one string. */
    public function label(): string
    {
        return trim(($this->product?->name ?? 'Item').' '.($this->variant?->name ?? ''));
    }
}
