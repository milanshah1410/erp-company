<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipe;
use App\Services\StockService;

/**
 * Moves the stock a settled bill actually consumed.
 *
 * Before this existed, settling a restaurant bill raised the Sales Order and the
 * Invoice but touched no stock at all — correct-looking, because you cannot
 * deplete a shelf item called "Paneer Tikka", but it meant a restaurant's
 * inventory and gross margin were both blind.
 *
 * Two rules resolve every line:
 *
 *  1. If the dish has a recipe, consume its ingredients, scaled by the recipe's
 *     yield and grossed up for wastage.
 *  2. If it has no recipe and is not a service, consume the item itself. That is
 *     what a bottled drink or a packet of crisps is — sold exactly as bought —
 *     and it closes the hole that would otherwise leave every non-recipe line
 *     depleting nothing.
 *
 * Deliberately posts with `allowNegative: true`. A kitchen that has run its
 * opening balances in badly must still be able to hand a table its bill; refusing
 * to settle because the system thinks the paneer ran out would stop the shop and
 * teach the staff to bypass the system. A negative balance is a visible problem
 * to reconcile — a till that will not close is a worse one.
 */
class RecipeConsumptionService
{
    public function __construct(private readonly StockService $stock) {}

    /** Ledger movement type for an ingredient leaving the store into a dish. */
    public const MOVEMENT = 'recipe_consume';

    /**
     * Deplete everything a settled order consumed.
     *
     * Called from inside RestaurantService::settle(), after its invoice-exists
     * guard, so it inherits that method's idempotency: a ticket that already has
     * an invoice returns before reaching here and can never be depleted twice.
     *
     * @return int Number of stock movements posted.
     */
    public function consumeForOrder(RestaurantOrder $order, int $warehouseId, ?int $userId = null): int
    {
        $items = $order->billableItems()->with('product:id,name,is_service')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $recipes = $this->recipesFor($items->pluck('product_id')->unique()->all());

        $posted = 0;

        foreach ($items as $item) {
            $recipe = $recipes->get($item->product_id);

            $posted += $recipe
                ? $this->consumeRecipe($recipe, $item, $order, $warehouseId, $userId)
                : $this->consumeItemItself($item, $order, $warehouseId, $userId);
        }

        return $posted;
    }

    /**
     * Active recipes for these dishes, ingredients eager-loaded.
     *
     * @param  list<int>  $productIds
     * @return \Illuminate\Support\Collection<int, RestaurantRecipe>
     */
    private function recipesFor(array $productIds)
    {
        return RestaurantRecipe::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->with('items')
            ->get()
            ->keyBy('product_id');
    }

    /** Draw down each ingredient of a dish, scaled to the quantity sold. */
    private function consumeRecipe(
        RestaurantRecipe $recipe,
        RestaurantOrderItem $item,
        RestaurantOrder $order,
        int $warehouseId,
        ?int $userId,
    ): int {
        $posted = 0;

        foreach ($recipe->items as $ingredient) {
            $quantity = round($recipe->perServing($ingredient) * (float) $item->quantity, 4);

            if ($quantity <= 0) {
                continue;
            }

            $this->stock->outward(
                warehouseId: $warehouseId,
                productId: $ingredient->product_id,
                variantId: $ingredient->variant_id,
                quantity: $quantity,
                type: self::MOVEMENT,
                referenceType: RestaurantOrder::class,
                referenceId: $order->id,
                referenceNumber: $order->order_number,
                userId: $userId,
                allowNegative: true,
            );

            $posted++;
        }

        return $posted;
    }

    /**
     * A line with no recipe: sold as bought, so the product itself moves.
     *
     * Services never move — a service charge or a cover charge has nothing behind
     * it on a shelf.
     */
    private function consumeItemItself(
        RestaurantOrderItem $item,
        RestaurantOrder $order,
        int $warehouseId,
        ?int $userId,
    ): int {
        if ($item->product?->is_service || (float) $item->quantity <= 0) {
            return 0;
        }

        $this->stock->outward(
            warehouseId: $warehouseId,
            productId: $item->product_id,
            variantId: $item->variant_id,
            quantity: (float) $item->quantity,
            type: self::MOVEMENT,
            referenceType: RestaurantOrder::class,
            referenceId: $order->id,
            referenceNumber: $order->order_number,
            userId: $userId,
            allowNegative: true,
        );

        return 1;
    }

    /**
     * Cost one plate of a dish from its recipe, at current ingredient cost.
     *
     * Used by the menu screen's food-cost column and by the recipe editor's
     * roll-up. Returns 0.0 for a dish with no recipe — an unknown cost, reported
     * as unknown rather than guessed from the selling price.
     */
    public function plateCost(int $productId): float
    {
        $recipe = RestaurantRecipe::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->with('items')
            ->first();

        if (! $recipe) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($recipe->items as $ingredient) {
            $unitCost = (float) $ingredient->unit_cost
                ?: (float) (Product::find($ingredient->product_id)?->cost_price ?? 0);

            $total += $recipe->perServing($ingredient) * $unitCost;
        }

        return round($total, 4);
    }
}
