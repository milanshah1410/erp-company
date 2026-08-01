<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a dish is made of.
 *
 * A restaurant sells cooked stock, so billing a plate has to move the paneer and
 * the cream, not a shelf item called "Paneer Tikka". This is the statement that
 * makes that possible — and, as a side effect, the only way food cost is
 * knowable at all.
 *
 * `yield_quantity` is the servings the ingredient lines below produce. Chefs
 * write recipes per kilo of gravy, not per plate, so consumption scales by it
 * rather than assuming every recipe is written for one.
 */
class RestaurantRecipe extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'product_id', 'yield_quantity', 'ingredient_cost', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:3',
        'ingredient_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** The dish this recipe produces. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantRecipeItem::class, 'restaurant_recipe_id');
    }

    /**
     * Ingredient draw-down for one unit of the dish, wastage included.
     *
     * Guards against a zero or missing yield: a recipe saved as yielding nothing
     * would otherwise divide by zero at the till, which is the worst possible
     * moment to discover it.
     */
    public function perServing(RestaurantRecipeItem $item): float
    {
        $yield = (float) $this->yield_quantity;
        $yield = $yield > 0 ? $yield : 1.0;

        return ((float) $item->quantity / $yield) * (1 + ((float) $item->wastage_percent / 100));
    }

    /** Roll the ingredient cost back up onto the header. */
    public function recost(): void
    {
        $this->loadMissing('items');

        $total = $this->items->sum(fn (RestaurantRecipeItem $i) => (float) $i->total_cost);

        $this->forceFill(['ingredient_cost' => round($total, 2)])->save();
    }
}
