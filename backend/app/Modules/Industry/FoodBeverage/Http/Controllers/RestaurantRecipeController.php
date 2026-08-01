<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\FoodBeverage\Http\Requests\RestaurantRecipeRequest;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipe;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipeItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recipes: what each dish is made of.
 *
 * The list carries a food-cost column derived from ingredient cost against the
 * dish's selling price, because that margin is the single number a kitchen
 * actually manages — and until recipes existed it could not be computed at all.
 */
class RestaurantRecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = RestaurantRecipe::query()
            ->with(['product:id,sku,name,selling_price,food_type', 'items.product:id,sku,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (RestaurantRecipe $r) => $this->row($r))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(RestaurantRecipe $restaurantRecipe)
    {
        $restaurantRecipe->load([
            'product:id,sku,name,selling_price,food_type',
            'items.product:id,sku,name,cost_price',
            'items.variant:id,sku',
        ]);

        return response()->json(['data' => $this->row($restaurantRecipe, withItems: true)]);
    }

    public function store(RestaurantRecipeRequest $request)
    {
        $data = $request->validated();

        $recipe = DB::transaction(function () use ($request, $data) {
            $recipe = RestaurantRecipe::create([
                'product_id' => $data['product_id'],
                'yield_quantity' => $data['yield_quantity'],
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($recipe, $data['items']);

            return $recipe;
        });

        return response()->json(['data' => $this->row($recipe->fresh(['product', 'items.product']), withItems: true)], 201);
    }

    public function update(RestaurantRecipeRequest $request, RestaurantRecipe $restaurantRecipe)
    {
        $data = $request->validated();

        DB::transaction(function () use ($restaurantRecipe, $data) {
            $restaurantRecipe->update([
                'product_id' => $data['product_id'],
                'yield_quantity' => $data['yield_quantity'],
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
            ]);

            // Replaced wholesale rather than diffed: a recipe is a short list that
            // is rewritten as a whole when a chef changes it, and matching lines
            // by product would mangle a recipe that legitimately lists the same
            // ingredient twice (base stock and a garnish).
            $restaurantRecipe->items()->delete();
            $this->syncItems($restaurantRecipe, $data['items']);
        });

        return response()->json([
            'data' => $this->row($restaurantRecipe->fresh(['product', 'items.product']), withItems: true),
        ]);
    }

    public function destroy(RestaurantRecipe $restaurantRecipe)
    {
        // Cascade removes the ingredient lines. Past consumption is unaffected:
        // stock movements are immutable ledger rows referencing the order, never
        // the recipe, so deleting a recipe cannot rewrite history.
        $restaurantRecipe->delete();

        return response()->json(['message' => 'Recipe deleted.']);
    }

    /**
     * Write the ingredient lines and roll their cost back up onto the header, so
     * the list's food-cost column never has to sum lines per row.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(RestaurantRecipe $recipe, array $items): void
    {
        $total = 0.0;

        foreach ($items as $line) {
            $unitCost = isset($line['unit_cost'])
                ? (float) $line['unit_cost']
                : (float) (Product::find($line['product_id'])?->cost_price ?? 0);
            $quantity = (float) $line['quantity'];
            $wastage = (float) ($line['wastage_percent'] ?? 0);
            // Costed on the gross quantity: the shop pays for the trim too.
            $lineCost = round($quantity * (1 + $wastage / 100) * $unitCost, 2);

            $recipe->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $quantity,
                'wastage_percent' => $wastage,
                'unit_cost' => $unitCost,
                'total_cost' => $lineCost,
                'remarks' => $line['remarks'] ?? null,
            ]);

            $total += $lineCost;
        }

        $recipe->forceFill(['ingredient_cost' => round($total, 2)])->save();
    }

    private function summary(): array
    {
        $dishes = Product::query()->where('is_active', true)->whereNotNull('kitchen_station')->count();

        return [
            'recipes' => RestaurantRecipe::count(),
            'active' => RestaurantRecipe::where('is_active', true)->count(),
            // How much of the kitchen's menu is actually costed. The gap is the
            // part of the food bill nobody can currently see.
            'dishes_with_station' => $dishes,
        ];
    }

    private function row(RestaurantRecipe $r, bool $withItems = false): array
    {
        $sellingPrice = (float) ($r->product?->selling_price ?? 0);
        $cost = (float) $r->ingredient_cost;

        // Per-serving cost, since ingredient_cost is stated per yield_quantity.
        $yield = (float) $r->yield_quantity;
        $perServing = $yield > 0 ? round($cost / $yield, 2) : $cost;

        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'dish' => $r->product?->name,
            'sku' => $r->product?->sku,
            'food_type' => $r->product?->food_type,
            'yield_quantity' => (float) $r->yield_quantity,
            'ingredient_cost' => round($cost, 2),
            'cost_per_serving' => $perServing,
            'selling_price' => $sellingPrice,
            // Null rather than zero when the dish has no price: an unknown margin
            // reported as 0% would read as "we make nothing on this".
            'food_cost_percent' => $sellingPrice > 0
                ? round($perServing / $sellingPrice * 100, 1)
                : null,
            'margin' => $sellingPrice > 0 ? round($sellingPrice - $perServing, 2) : null,
            'is_active' => (bool) $r->is_active,
            'notes' => $r->notes,
            'items_count' => $r->items_count ?? $r->items->count(),
            'items' => $withItems ? $r->items->map(fn (RestaurantRecipeItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'ingredient' => $i->product?->name,
                'sku' => $i->product?->sku,
                'variant_id' => $i->variant_id,
                'quantity' => (float) $i->quantity,
                'wastage_percent' => (float) $i->wastage_percent,
                'gross_quantity' => round($i->grossQuantity(), 4),
                'unit_cost' => (float) $i->unit_cost,
                'total_cost' => (float) $i->total_cost,
                'remarks' => $i->remarks,
            ])->values() : null,
        ];
    }
}
