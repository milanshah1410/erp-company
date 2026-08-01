<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipe;
use App\Services\StockService;
use Illuminate\Support\Collection;

/**
 * Moves the stock a settled bill actually consumed.
 *
 * Before this existed, settling a restaurant bill raised the Sales Order and the
 * Invoice but touched no stock at all — correct-looking, because you cannot
 * deplete a shelf item called "Paneer Tikka", but it meant a restaurant's
 * inventory and gross margin were both blind.
 *
 * Three rules resolve every line:
 *
 *  1. If the line is a combo, expand it into its component dishes and resolve
 *     each of those by rules 2 and 3. A combo has no recipe of its own — what a
 *     meal deal consumed is what its burger, its fries and its cola consumed.
 *  2. If the dish has a recipe, consume its ingredients, scaled by the recipe's
 *     yield and grossed up for wastage.
 *  3. If it has no recipe and is not a service, consume the item itself. That is
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
    public function __construct(
        private readonly StockService $stock,
        /** Combo expansion — see rule 1. */
        private readonly MenuOfferService $offers,
    ) {}

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
        $items = $order->billableItems()->with('product:id,name,is_service,is_combo')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        /**
         * Flatten the ticket into what it really consumed, combos expanded, before
         * touching stock. Done as one pass so recipes and product flags are loaded
         * once for the whole bill rather than per line — a twenty-line ticket at a
         * busy till should not be twenty round trips.
         *
         * @var list<array{product_id: int, variant_id: int|null, quantity: float}> $lines
         */
        $lines = $this->flatten($items);

        if ($lines === []) {
            return 0;
        }

        $productIds = array_values(array_unique(array_column($lines, 'product_id')));
        $recipes = $this->recipesFor($productIds);
        $services = $this->serviceFlags($productIds);

        $posted = 0;

        foreach ($lines as $line) {
            $recipe = $recipes->get($line['product_id']);

            $posted += $recipe
                ? $this->consumeRecipe($recipe, $line, $order, $warehouseId, $userId)
                : $this->consumeAsSold($line, $services, $order, $warehouseId, $userId);
        }

        return $posted;
    }

    /**
     * The ticket as a flat list of things actually consumed.
     *
     * A combo contributes its components and not itself; everything else
     * contributes itself. A combo whose definition has been deleted or
     * deactivated falls through to contributing itself, which is the safer
     * failure: depleting the wrong thing is recoverable, depleting nothing hides
     * the sale from inventory entirely.
     *
     * @param  Collection<int, RestaurantOrderItem>  $items
     * @return list<array{product_id: int, variant_id: int|null, quantity: float}>
     */
    private function flatten(Collection $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $quantity = (float) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $components = $item->product?->is_combo
                ? $this->offers->expandCombo((int) $item->product_id, $quantity)
                : [];

            if ($components !== []) {
                array_push($lines, ...$components);

                continue;
            }

            $lines[] = [
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
                'quantity' => $quantity,
            ];
        }

        return $lines;
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

    /**
     * Which of these products are services, keyed by id.
     *
     * @param  list<int>  $productIds
     * @return array<int, bool>
     */
    private function serviceFlags(array $productIds): array
    {
        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('is_service', 'id')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    /**
     * Draw down each ingredient of a dish, scaled to the quantity sold.
     *
     * @param  array{product_id: int, variant_id: int|null, quantity: float}  $line
     */
    private function consumeRecipe(
        RestaurantRecipe $recipe,
        array $line,
        RestaurantOrder $order,
        int $warehouseId,
        ?int $userId,
    ): int {
        $posted = 0;

        foreach ($recipe->items as $ingredient) {
            $quantity = round($recipe->perServing($ingredient) * $line['quantity'], 4);

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
     *
     * @param  array{product_id: int, variant_id: int|null, quantity: float}  $line
     * @param  array<int, bool>  $services
     */
    private function consumeAsSold(
        array $line,
        array $services,
        RestaurantOrder $order,
        int $warehouseId,
        ?int $userId,
    ): int {
        if ($services[$line['product_id']] ?? false) {
            return 0;
        }

        $this->stock->outward(
            warehouseId: $warehouseId,
            productId: $line['product_id'],
            variantId: $line['variant_id'],
            quantity: $line['quantity'],
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
     *
     * A combo costs the sum of its components, each costed this same way, so the
     * food-cost column means the same thing for a meal deal as for a dish.
     */
    public function plateCost(int $productId): float
    {
        $components = $this->offers->expandCombo($productId);

        if ($components !== []) {
            $total = 0.0;
            foreach ($components as $component) {
                $total += $this->plateCost($component['product_id']) * $component['quantity'];
            }

            return round($total, 4);
        }

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
