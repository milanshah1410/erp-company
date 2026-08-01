<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient line of a recipe.
 *
 * `wastage_percent` is kept separate from `quantity` so the chef's recipe stays
 * the chef's recipe — 200g of paneer is 200g — while the kitchen's actual
 * draw-down still accounts for trim and spillage.
 */
class RestaurantRecipeItem extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'restaurant_recipe_id', 'product_id', 'variant_id',
        'quantity', 'wastage_percent', 'unit_cost', 'total_cost', 'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'wastage_percent' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(RestaurantRecipe::class, 'restaurant_recipe_id');
    }

    /** The ingredient — a stocked product. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** Quantity including its wastage allowance. */
    public function grossQuantity(): float
    {
        return (float) $this->quantity * (1 + ((float) $this->wastage_percent / 100));
    }
}
