<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Modules\Industry\FoodBeverage\Models\MenuCombo;
use App\Modules\Industry\FoodBeverage\Models\MenuHappyHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The two ways a café's menu price is not the product's price: a combo and a clock.
 *
 * Both end up as the same thing — an adjustment made while a ticket is being
 * priced — so both are resolved here, and every screen that needs to know what an
 * item costs *right now* asks this one service. The alternative was the same "is
 * it Tuesday between four and seven" test written into the restaurant ticket, the
 * POS grid and the public QR menu, where the three copies would drift.
 *
 * Exactly one offer applies to a line. Stacking discounts is how a shop
 * accidentally sells at cost, so the highest-priority live offer wins and the
 * rest are ignored.
 */
class MenuOfferService
{
    /**
     * Apply live happy hours to an open ticket's lines.
     *
     * Called from RestaurantService::reprice(), before the pricing pass, so the
     * offer is folded into the same per-line discount machinery every other
     * discount in the system goes through.
     *
     * A manual discount always wins. An item carrying a discount that no offer
     * put there is a manager's decision, and software that quietly overwrote it
     * mid-service would be worse than no offers at all — so only lines that are
     * undiscounted, or were discounted by an offer previously, are touched.
     *
     * @return int Lines whose discount changed.
     */
    public function applyToTicket(RestaurantOrder $order, ?CarbonInterface $at = null): int
    {
        $at ??= now();

        $items = $order->billableItems()->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $offers = $this->liveOffers($at, $order->order_type);

        // No offers running: clear any that were applied earlier in the meal. A
        // ticket opened at 18:55 and settled at 19:10 keeps the price it was
        // quoted for the round that was ordered inside the window — which is why
        // this only clears lines whose offer is no longer live, and leaves the
        // amount alone otherwise.
        $categories = $this->categoriesFor($items->pluck('product_id')->unique()->all());
        $scoped = $this->scopedProductIds($offers);

        $changed = 0;

        foreach ($items as $item) {
            if (! $this->isOfferPriced($item)) {
                continue;
            }

            $winner = $this->bestOffer(
                $offers,
                (int) $item->product_id,
                $categories[$item->product_id] ?? null,
                $scoped,
            );

            $changed += $this->writeOffer($item, $winner) ? 1 : 0;
        }

        return $changed;
    }

    /**
     * The offer that should price one dish right now, or null.
     *
     * Public because the menu card and the POS grid both want to show a struck-out
     * price without having a ticket to price.
     */
    public function offerFor(
        int $productId,
        ?int $categoryId = null,
        ?string $orderType = null,
        ?CarbonInterface $at = null,
    ): ?MenuHappyHour {
        $at ??= now();
        $offers = $this->liveOffers($at, $orderType);

        return $this->bestOffer($offers, $productId, $categoryId, $this->scopedProductIds($offers));
    }

    /**
     * Every offer running at this moment for this order type, best first.
     *
     * @return Collection<int, MenuHappyHour>
     */
    public function liveOffers(?CarbonInterface $at = null, ?string $orderType = null): Collection
    {
        $at ??= now();

        return MenuHappyHour::query()
            ->live()
            ->with('products:id')
            ->get()
            ->filter(fn (MenuHappyHour $o) => $o->isLiveAt($at) && $o->appliesToOrderType($orderType))
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Combos
    |--------------------------------------------------------------------------
    */

    /**
     * Expand a combo into the dishes it is made of.
     *
     * Used by RecipeConsumptionService at settle time: a combo has no recipe of
     * its own, so what it consumed is what its components consumed. Returns an
     * empty list for anything that is not a combo, which is the answer that lets
     * the caller treat every line the same way.
     *
     * @return list<array{product_id: int, variant_id: int|null, quantity: float}>
     */
    public function expandCombo(int $productId, float $quantity = 1.0): array
    {
        $combo = MenuCombo::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->with('items')
            ->first();

        if (! $combo) {
            return [];
        }

        $out = [];

        foreach ($combo->items as $item) {
            $line = round((float) $item->quantity * $quantity, 4);

            if ($line <= 0) {
                continue;
            }

            $out[] = [
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
                'quantity' => $line,
            ];
        }

        return $out;
    }

    /** Product ids that are sellable combos, for a menu screen's badge. @return list<int> */
    public function comboProductIds(): array
    {
        return MenuCombo::query()->where('is_active', true)->pluck('product_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Is this line's discount ours to manage?
     *
     * True when the line carries no discount at all, or carries one an offer put
     * there. False for a manual discount — see applyToTicket().
     */
    private function isOfferPriced(RestaurantOrderItem $item): bool
    {
        return $item->menu_happy_hour_id !== null || (float) $item->discount_value <= 0;
    }

    /**
     * @param  Collection<int, MenuHappyHour>  $offers
     * @param  array<int, list<int>>  $scoped  product ids of each 'products'-scoped offer, keyed by offer id
     */
    private function bestOffer(
        Collection $offers,
        int $productId,
        ?int $categoryId,
        array $scoped,
    ): ?MenuHappyHour {
        foreach ($offers as $offer) {
            if ($offer->coversProduct($productId, $categoryId, $scoped[$offer->id] ?? [])) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Write the winning offer onto the line, or clear a lapsed one.
     *
     * The discount is stored as the offer's own type and value rather than a
     * resolved rupee amount, so the pricing service does the arithmetic exactly
     * once and a line's discount reads the same whoever set it.
     *
     * @return bool Whether anything changed.
     */
    private function writeOffer(RestaurantOrderItem $item, ?MenuHappyHour $offer): bool
    {
        $current = [
            'menu_happy_hour_id' => $item->menu_happy_hour_id ? (int) $item->menu_happy_hour_id : null,
            'discount_type' => $item->discount_type,
            'discount_value' => round((float) $item->discount_value, 2),
        ];

        $next = $offer
            ? [
                'menu_happy_hour_id' => (int) $offer->id,
                'discount_type' => $offer->discount_type,
                'discount_value' => round((float) $offer->discount_value, 2),
            ]
            : ['menu_happy_hour_id' => null, 'discount_type' => null, 'discount_value' => 0.0];

        if ($current === $next) {
            return false;
        }

        $item->forceFill($next)->save();

        return true;
    }

    /**
     * Category of each product in one query — this runs per line, per reprice.
     *
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    private function categoriesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('category_id', 'id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();
    }

    /**
     * Product ids each 'products'-scoped offer covers, keyed by offer id.
     *
     * @param  Collection<int, MenuHappyHour>  $offers
     * @return array<int, list<int>>
     */
    private function scopedProductIds(Collection $offers): array
    {
        $out = [];

        foreach ($offers as $offer) {
            $out[$offer->id] = $offer->scope === 'products'
                ? $offer->products->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return $out;
    }
}
