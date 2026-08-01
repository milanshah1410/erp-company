<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipe;
use App\Services\BarcodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The menu — a view of the product master through a kitchen's eyes.
 *
 * There is no menu_items table on purpose. The dish a waiter keys, the dish the
 * kitchen cooks and the dish the accountant bills must be one row, or the three
 * will drift; the menu is therefore the product master, and what this controller
 * adds is the handful of attributes only a menu cares about: the veg mark, the
 * station, the prep time, and whether it can be sold right now.
 *
 * `is_available` is the sold-out switch and is deliberately distinct from
 * `is_active`. A dish off because the fish did not arrive this morning is not a
 * dish withdrawn from the menu — tomorrow it comes back with its price, recipe
 * and history intact.
 */
class RestaurantMenuController extends Controller
{
    public function __construct(private readonly BarcodeService $barcodes) {}

    /** Tenant setting holding the token behind the shop-wide menu QR. */
    private const MENU_TOKEN_KEY = 'restaurant_menu_token';

    public function index(Request $request)
    {
        $query = Product::query()
            ->with('category:id,name')
            ->where('is_active', true)
            // A service line (Service Charge, Delivery Charge) is not a dish.
            ->where(fn ($q) => $q->where('is_service', false)->orWhereNull('is_service'));

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"));
        }
        foreach (['category_id', 'kitchen_station', 'food_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }

        $query->orderBy('menu_sort_order')->orderBy('name');

        $page = $query->paginate(min((int) $request->query('per_page', 50), 200));

        // Which dishes are costed, resolved in one query rather than per row.
        $recipes = RestaurantRecipe::query()
            ->whereIn('product_id', $page->pluck('id'))
            ->where('is_active', true)
            ->pluck('ingredient_cost', 'product_id');

        return response()->json(
            $page->through(fn (Product $p) => $this->row($p, $recipes))->toArray()
            + ['summary' => $this->summary()]
        );
    }

    /**
     * Set the menu-only attributes of a dish.
     *
     * Scoped hard to those five fields: price, tax and stock flags belong to the
     * product screen, and letting a menu screen write them would give two screens
     * authority over the same numbers.
     */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'food_type' => ['nullable', Rule::in(['veg', 'non_veg', 'egg', 'vegan'])],
            'kitchen_station' => ['nullable', Rule::in(['kitchen', 'bar', 'tandoor', 'dessert', 'chinese'])],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'is_available' => ['nullable', 'boolean'],
            'menu_sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $product->update($data);

        return response()->json(['data' => $this->row($product->fresh(['category']))]);
    }

    /**
     * Sold out / back on. Its own endpoint because it is the one action a busy
     * counter takes mid-service, and it should be one tap, not a form.
     */
    public function setAvailability(Request $request, Product $product)
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']]);

        $product->update(['is_available' => $data['is_available']]);

        return response()->json(['data' => $this->row($product->fresh(['category']))]);
    }

    /** Mark several dishes at once — "the whole tandoor is off tonight". */
    public function bulkAvailability(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'is_available' => ['required', 'boolean'],
        ]);

        $updated = Product::query()
            ->whereIn('id', $data['product_ids'])
            ->update(['is_available' => $data['is_available']]);

        return response()->json(['message' => "{$updated} item(s) updated.", 'updated' => $updated]);
    }

    /**
     * The printable QR codes: one per table plus a shop-wide one.
     *
     * Tokens are minted on first request rather than at table creation, so tables
     * that existed before this feature get one the moment someone opens the
     * screen, and no migration had to guess them.
     */
    public function qrCodes(Request $request)
    {
        $base = rtrim((string) (config('payments.pay_page_base') ?: config('app.frontend_url') ?: config('app.url')), '/');

        $shopToken = Setting::get(self::MENU_TOKEN_KEY);
        if (! $shopToken) {
            $shopToken = self::mintToken('mn');
            Setting::set(self::MENU_TOKEN_KEY, $shopToken);
        }

        $tables = RestaurantTable::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'qr_token', 'capacity']);

        foreach ($tables as $table) {
            if (! $table->qr_token) {
                $table->forceFill(['qr_token' => self::mintToken('tb')])->save();
            }
        }

        $codes = [[
            'scope' => 'shop',
            'label' => 'Whole menu',
            'token' => $shopToken,
            'url' => $base.'/menu/'.$shopToken,
            'qr' => $this->barcodes->qrDataUri($base.'/menu/'.$shopToken),
        ]];

        foreach ($tables as $table) {
            $url = $base.'/menu/'.$table->qr_token;
            $codes[] = [
                'scope' => 'table',
                'table_id' => $table->id,
                'label' => 'Table '.$table->name,
                'token' => $table->qr_token,
                'url' => $url,
                'qr' => $this->barcodes->qrDataUri($url),
            ];
        }

        return response()->json(['data' => $codes]);
    }

    /**
     * A URL-safe token that is unique across every tenant, because the token is
     * the entire lookup on the public route — see PublicMenuController.
     */
    private static function mintToken(string $prefix): string
    {
        do {
            $token = $prefix.'_'.Str::lower(Str::random(32));
            $taken = RestaurantTable::withoutGlobalScopes()->where('qr_token', $token)->exists()
                || Setting::withoutGlobalScopes()
                    ->where('key', self::MENU_TOKEN_KEY)->where('value', $token)->exists();
        } while ($taken);

        return $token;
    }

    private function summary(): array
    {
        $dishes = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('is_service', false)->orWhereNull('is_service'));

        return [
            'items' => (clone $dishes)->count(),
            'sold_out' => (clone $dishes)->where('is_available', false)->count(),
            'unmarked' => (clone $dishes)->whereNull('food_type')->count(),
            'costed' => RestaurantRecipe::where('is_active', true)->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>|null  $recipeCosts
     */
    private function row(Product $p, $recipeCosts = null): array
    {
        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'category' => $p->relationLoaded('category') ? $p->category?->name : null,
            'category_id' => $p->category_id,
            'selling_price' => (float) $p->selling_price,
            'tax_percent' => (float) $p->tax_percent,
            'food_type' => $p->food_type,
            'kitchen_station' => $p->kitchen_station,
            'prep_minutes' => $p->prep_minutes,
            'is_available' => (bool) $p->is_available,
            'menu_sort_order' => (int) $p->menu_sort_order,
            'has_recipe' => $recipeCosts ? $recipeCosts->has($p->id) : null,
            'ingredient_cost' => $recipeCosts ? (float) ($recipeCosts[$p->id] ?? 0) : null,
        ];
    }
}
