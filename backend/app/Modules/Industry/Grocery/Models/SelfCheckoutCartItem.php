<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan at the lane.
 *
 * The price is snapshotted rather than read through to the product: the customer
 * is watching a running total, and a figure that moves while they are still
 * shopping is an argument at the lane. The POS checkout re-prices the basket when
 * it bills it, which is the number that actually matters — this one exists so the
 * screen can show a total and the attendant can see what they are approving.
 */
class SelfCheckoutCartItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'cart_id', 'product_id', 'variant_id', 'quantity', 'unit_price',
        'tax_percent', 'needs_approval', 'approval_reason', 'is_voided',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'needs_approval' => 'boolean',
        'is_voided' => 'boolean',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(SelfCheckoutCart::class, 'cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Line value as shown on the lane screen — tax-inclusive of nothing, like the till. */
    public function lineTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
