<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item inside a combo pack.
 *
 * The quantity is in the component product's own unit and is the quantity that
 * leaves stock when the pack is billed, so it is held at the same precision the
 * stock ledger uses.
 */
class ComboPackItem extends Model
{
    use BelongsToTenant;

    /** Stated for the same reason as ComboPack's — see the note there. */
    protected $table = 'grocery_combo_pack_items';

    protected $fillable = [
        'grocery_combo_pack_id', 'product_id', 'variant_id', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ComboPack::class, 'grocery_combo_pack_id');
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
