<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dish inside a combo.
 *
 * `is_swappable` is recorded but not yet honoured at the till: a combo whose
 * choices were never captured could not be given a choice UI later without a
 * migration over live data, so the shape is here from the start.
 */
class MenuComboItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'menu_combo_id', 'product_id', 'variant_id', 'quantity', 'is_swappable', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'is_swappable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function combo(): BelongsTo
    {
        return $this->belongsTo(MenuCombo::class, 'menu_combo_id');
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
