<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\FoodBeverage\Http\Requests\MenuHappyHourRequest;
use App\Modules\Industry\FoodBeverage\Models\MenuHappyHour;
use App\Modules\Industry\FoodBeverage\Services\MenuOfferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Happy hours: the menu price on a clock.
 *
 * Every row carries `is_live_now`, computed the same way the till computes it. That
 * is the answer to the only question a manager actually asks of this screen — "is
 * my four-o'clock offer running?" — and answering it from the same code path the
 * pricing uses is what stops the screen from saying yes while the bills say no.
 */
class MenuHappyHourController extends Controller
{
    public function __construct(private readonly MenuOfferService $offers) {}

    public function index(Request $request)
    {
        $query = MenuHappyHour::query()
            ->with(['category:id,name', 'products:id,sku,name'])
            ->withCount('products');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('priority')->orderBy('start_time');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (MenuHappyHour $o) => $this->row($o))->toArray()
            + ['summary' => [
                'offers' => MenuHappyHour::count(),
                'active' => MenuHappyHour::where('is_active', true)->count(),
                'live_now' => $this->offers->liveOffers()->count(),
            ]],
        );
    }

    public function show(MenuHappyHour $menuHappyHour)
    {
        $menuHappyHour->load(['category:id,name', 'products:id,sku,name,selling_price']);

        return response()->json(['data' => $this->row($menuHappyHour, withProducts: true)]);
    }

    public function store(MenuHappyHourRequest $request)
    {
        $offer = DB::transaction(fn () => $this->persist(new MenuHappyHour, $request));

        return response()->json(['data' => $this->row($offer, withProducts: true)], 201);
    }

    public function update(MenuHappyHourRequest $request, MenuHappyHour $menuHappyHour)
    {
        $offer = DB::transaction(fn () => $this->persist($menuHappyHour, $request));

        return response()->json(['data' => $this->row($offer, withProducts: true)]);
    }

    public function destroy(MenuHappyHour $menuHappyHour)
    {
        /**
         * Deleting an offer does not rewrite the bills it priced: the line's
         * `menu_happy_hour_id` is nulled by the foreign key, the discount amount
         * that was actually charged stays exactly as it was.
         */
        $menuHappyHour->delete();

        return response()->json(['message' => 'Offer deleted.']);
    }

    /**
     * What is running right now — for the menu card, the POS grid and the QR menu.
     *
     * Deliberately not paginated: this is a short list read on every menu render.
     */
    public function live(Request $request)
    {
        $offers = $this->offers->liveOffers(null, $request->query('order_type'));

        return response()->json([
            'data' => $offers->map(fn (MenuHappyHour $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'scope' => $o->scope,
                'category_id' => $o->category_id,
                'product_ids' => $o->scope === 'products' ? $o->products->pluck('id')->values() : [],
                'discount_type' => $o->discount_type,
                'discount_value' => (float) $o->discount_value,
                'ends_at' => substr((string) $o->end_time, 0, 5),
            ])->values(),
        ]);
    }

    private function persist(MenuHappyHour $offer, MenuHappyHourRequest $request): MenuHappyHour
    {
        $data = $request->validated();

        $offer->fill([
            'name' => $data['name'],
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'scope' => $data['scope'],
            // Cleared when the scope moves off 'category', so a stale category
            // cannot start governing an offer that is now scoped to products.
            'category_id' => $data['scope'] === 'category' ? $data['category_id'] : null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'applies_to' => $data['applies_to'],
            'priority' => $data['priority'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (! $offer->exists) {
            $offer->created_by = $request->user()?->id;
        }

        $offer->save();

        // Same reasoning: a product list only means anything for a 'products' scope.
        $offer->products()->sync($data['scope'] === 'products' ? ($data['product_ids'] ?? []) : []);

        return $offer->fresh(['category', 'products']);
    }

    private function row(MenuHappyHour $o, bool $withProducts = false): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'days' => array_map('intval', $o->days ?? []),
            'start_time' => substr((string) $o->start_time, 0, 5),
            'end_time' => substr((string) $o->end_time, 0, 5),
            // A window whose end is not after its start runs through midnight.
            'crosses_midnight' => substr((string) $o->end_time, 0, 5) <= substr((string) $o->start_time, 0, 5),
            'valid_from' => $o->valid_from?->toDateString(),
            'valid_to' => $o->valid_to?->toDateString(),
            'scope' => $o->scope,
            'category_id' => $o->category_id,
            'category' => $o->category?->name,
            'discount_type' => $o->discount_type,
            'discount_value' => (float) $o->discount_value,
            'applies_to' => $o->applies_to,
            'priority' => (int) $o->priority,
            'is_active' => (bool) $o->is_active,
            'is_live_now' => $o->isLiveAt(now()),
            'products_count' => $o->products_count ?? $o->products->count(),
            'product_ids' => $o->scope === 'products' ? $o->products->pluck('id')->values() : [],
            'products' => $withProducts && $o->scope === 'products'
                ? $o->products->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name])->values()
                : null,
        ];
    }
}
