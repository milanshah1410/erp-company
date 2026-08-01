<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\FoodBeverage\Http\Requests\MenuComboRequest;
use App\Modules\Industry\FoodBeverage\Models\MenuCombo;
use App\Modules\Industry\FoodBeverage\Models\MenuComboItem;
use App\Modules\Industry\FoodBeverage\Services\RecipeConsumptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Combos: "burger + fries + cola for 199".
 *
 * The list is priced two ways on purpose — what the components are worth
 * separately, and what the combo sells for — because the gap between them is the
 * offer, and a shop needs to see when a component price rise has quietly turned a
 * deal into a loss.
 *
 * Saving a combo also sets `products.is_combo`, which is what lets the menu card
 * and the POS grid badge it without joining this table for a boolean.
 */
class MenuComboController extends Controller
{
    public function __construct(private readonly RecipeConsumptionService $costing) {}

    public function index(Request $request)
    {
        $query = MenuCombo::query()
            ->with(['product:id,sku,name,selling_price,tax_percent,is_available', 'items.product:id,sku,name,selling_price'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (MenuCombo $c) => $this->row($c))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(MenuCombo $menuCombo)
    {
        $menuCombo->load([
            'product:id,sku,name,selling_price,tax_percent',
            'items.product:id,sku,name,selling_price',
            'items.variant:id,sku,name',
        ]);

        return response()->json(['data' => $this->row($menuCombo, withItems: true)]);
    }

    public function store(MenuComboRequest $request)
    {
        $data = $request->validated();

        $combo = DB::transaction(function () use ($request, $data) {
            $combo = MenuCombo::create([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($combo, $data['items']);

            return $combo;
        });

        return response()->json([
            'data' => $this->row($combo->fresh(['product', 'items.product']), withItems: true),
        ], 201);
    }

    public function update(MenuComboRequest $request, MenuCombo $menuCombo)
    {
        $data = $request->validated();
        $previousProductId = (int) $menuCombo->product_id;

        DB::transaction(function () use ($menuCombo, $data, $previousProductId) {
            $menuCombo->update([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
            ]);

            // Re-pointed at a different product: the old one is no longer a combo.
            if ($previousProductId !== (int) $data['product_id']) {
                Product::whereKey($previousProductId)->update(['is_combo' => false]);
            }

            // Replaced wholesale rather than diffed — same reasoning as recipes: a
            // combo is a short list rewritten as a whole, and matching by product
            // would mangle one that legitimately lists an item twice.
            $menuCombo->items()->delete();
            $this->syncItems($menuCombo, $data['items']);
        });

        return response()->json([
            'data' => $this->row($menuCombo->fresh(['product', 'items.product']), withItems: true),
        ]);
    }

    public function destroy(MenuCombo $menuCombo)
    {
        DB::transaction(function () use ($menuCombo) {
            /**
             * The product stops being a combo but is not deleted — it has a price,
             * a tax rate and probably history on old bills. Clearing the flag is
             * what stops the till from expanding a bundle that no longer exists.
             */
            Product::whereKey($menuCombo->product_id)->update(['is_combo' => false]);
            $menuCombo->delete();
        });

        return response()->json(['message' => 'Combo deleted.']);
    }

    /**
     * Write the component lines, flag the product, and snapshot what the parts are
     * worth so the list never has to price them per row.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(MenuCombo $combo, array $items): void
    {
        foreach ($items as $index => $line) {
            $combo->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $line['quantity'],
                'is_swappable' => $line['is_swappable'] ?? false,
                'sort_order' => $line['sort_order'] ?? $index,
            ]);
        }

        Product::whereKey($combo->product_id)->update(['is_combo' => true]);

        $combo->load('items.product:id,selling_price');
        $combo->revalue();
    }

    private function summary(): array
    {
        $combos = MenuCombo::query()->with('product:id,selling_price')->get();

        return [
            'combos' => $combos->count(),
            'active' => $combos->where('is_active', true)->count(),
            /**
             * Combos priced at or above the sum of their parts. Not an error — a
             * shop may charge for convenience — but it is the list an owner wants
             * to look at, because it is usually a component price rise nobody
             * noticed.
             */
            'no_saving' => $combos->filter(fn (MenuCombo $c) => $c->savingAmount() <= 0)->count(),
        ];
    }

    private function row(MenuCombo $c, bool $withItems = false): array
    {
        $price = (float) ($c->product?->selling_price ?? 0);
        $componentValue = (float) $c->component_value;
        // Food cost of the bundle, summed from its components' recipes.
        $cost = $this->costing->plateCost((int) $c->product_id);

        return [
            'id' => $c->id,
            'product_id' => $c->product_id,
            'sku' => $c->product?->sku,
            'name' => $c->name,
            'product_name' => $c->product?->name,
            'price' => $price,
            'component_value' => round($componentValue, 2),
            'saving' => round($componentValue - $price, 2),
            'saving_percent' => $componentValue > 0
                ? round(($componentValue - $price) / $componentValue * 100, 1)
                : null,
            'food_cost' => round($cost, 2),
            // Null rather than zero when nothing is priced: an unknown margin
            // reported as 0% reads as "we make nothing on this".
            'food_cost_percent' => $price > 0 && $cost > 0 ? round($cost / $price * 100, 1) : null,
            'is_active' => (bool) $c->is_active,
            'notes' => $c->notes,
            'items_count' => $c->items_count ?? $c->items->count(),
            'items' => $withItems ? $c->items->map(fn (MenuComboItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'item' => $i->product?->name,
                'sku' => $i->product?->sku,
                'variant_id' => $i->variant_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) ($i->product?->selling_price ?? 0),
                'is_swappable' => (bool) $i->is_swappable,
                'sort_order' => (int) $i->sort_order,
            ])->values() : null,
        ];
    }
}
