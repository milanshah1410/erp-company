<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Food & Beverage — the first industry plugin.
 *
 * Eight trades sit under this vertical and they are genuinely different shops:
 * a fine-dine restaurant bills a table an hour after seating it, a fast-food
 * counter calls a token number in ninety seconds, a dairy runs a milk round
 * before dawn. What they share is that the thing sold is *made*, so the ERP has
 * to know a dish is not a stock item — it is paneer, cream and gas — which is
 * why Recipe appears in four of the eight trades below.
 *
 * Feature statuses are load-bearing. Restaurant is fully built; the other seven
 * are declared so the trade picker is complete and their module presets are
 * right from day one, with their trade-specific screens marked 'planned' rather
 * than pretended into existence.
 */
class FoodBeveragePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'food_beverage';
    }

    public function label(): string
    {
        return 'Food & Beverage';
    }

    public function icon(): string
    {
        return 'pi pi-shop';
    }

    public function description(): string
    {
        return 'Restaurants, cafes, bakeries, sweet marts, fast food, juice bars, dairies and ice-cream parlours.';
    }

    /**
     * Modules every F&B trade runs on regardless of counter style: it sells
     * (sales/pos), it buys perishables (purchase/inventory), it keeps books
     * under GST, and it gets the common core (crm, ai).
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'crm', 'ai'];
    }

    public function trades(): array
    {
        return [
            'restaurant' => [
                'label' => 'Restaurant / Fine Dine',
                'icon' => 'pi pi-shop',
                'description' => 'Tables, kitchen order tickets, running bills, parcel and delivery',
                'modules' => [...$this->base(), 'restaurant', 'loyalty', 'hrms'],
                'features' => [
                    'pos' => 'available',
                    'table' => 'available',
                    'kot' => 'available',
                    'kds' => 'available',
                    'menu' => 'available',
                    'recipe' => 'available',
                    'qr_menu' => 'available',
                    'parcel' => 'available',
                    'delivery' => 'available',
                    'waiter' => 'available',
                ],
            ],

            'cafe' => [
                'label' => 'Cafe / Coffee Shop',
                'icon' => 'pi pi-star',
                'description' => 'Counter service with memberships, happy hours and combo pricing',
                'modules' => [...$this->base(), 'restaurant', 'loyalty'],
                'features' => [
                    'pos' => 'available',
                    'table' => 'available',
                    'kot' => 'available',
                    'menu' => 'available',
                    'recipe' => 'available',
                    'qr_menu' => 'available',
                    // Points and coupons ship in the Loyalty module; a paid
                    // membership tier with its own pricing does not yet.
                    'membership' => 'planned',
                    'happy_hours' => 'planned',
                    'combo' => 'planned',
                ],
            ],

            'bakery' => [
                'label' => 'Bakery',
                'icon' => 'pi pi-box',
                'description' => 'Recipes, production batches, shelf-life expiry and cake orders',
                // Manufacturing: a bakery genuinely produces, so BOMs and
                // production orders are its shop floor, not an optional extra.
                'modules' => [...$this->base(), 'manufacturing', 'loyalty', 'devices'],
                'features' => [
                    'pos' => 'available',
                    // Reached through Manufacturing → Production → Recipes, not
                    // through the Restaurant section: a bakery works to recipes
                    // but has no tables, so it does not run the Restaurant module.
                    'recipe' => 'available',
                    // Batch + expiry already exist in advanced inventory.
                    'batch' => 'available',
                    'expiry' => 'available',
                    // A dated, part-paid, decorated-to-order cake is its own
                    // document — closest existing shape is a sales order.
                    'cake_orders' => 'planned',
                    // The menu card (veg marks, sold-out, QR) belongs to the
                    // Restaurant module. A counter bakery prices from the product
                    // master instead, so this is not reachable for this trade.
                    'menu' => 'planned',
                ],
            ],

            'sweet_mart' => [
                'label' => 'Sweet Mart / Namkeen',
                'icon' => 'pi pi-gift',
                'description' => 'Weight-based billing, production batches and shelf life',
                'modules' => [...$this->base(), 'manufacturing', 'loyalty', 'devices'],
                'features' => [
                    'pos' => 'available',
                    // Via Manufacturing → Production → Recipes; see Bakery above.
                    'recipe' => 'available',
                    // Counter scales are Device Manager adapters.
                    'weight_scale' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                ],
            ],

            'fast_food' => [
                'label' => 'Fast Food / QSR',
                'icon' => 'pi pi-bolt',
                'description' => 'Token-number counter service, kitchen dockets and combos',
                'modules' => [...$this->base(), 'restaurant', 'loyalty'],
                'features' => [
                    'pos' => 'available',
                    'kot' => 'available',
                    'kds' => 'available',
                    'menu' => 'available',
                    'recipe' => 'available',
                    'qr_menu' => 'available',
                    'parcel' => 'available',
                    'delivery' => 'available',
                    // A called-out token queue is not a table plan; it needs its
                    // own display and numbering.
                    'token' => 'planned',
                    'combo' => 'planned',
                ],
            ],

            'juice' => [
                'label' => 'Juice / Beverage Bar',
                'icon' => 'pi pi-filter',
                'description' => 'Recipe-driven consumption of fruit and dairy stock',
                'modules' => [...$this->base(), 'restaurant'],
                'features' => [
                    'pos' => 'available',
                    'menu' => 'available',
                    'recipe' => 'available',
                    'qr_menu' => 'available',
                ],
            ],

            'dairy' => [
                'label' => 'Dairy / Milk Distribution',
                'icon' => 'pi pi-truck',
                'description' => 'Milk collection, delivery routes and household subscriptions',
                // Field sales carries the beat/route plumbing a milk round needs.
                'modules' => [...$this->base(), 'field_sales', 'logistics', 'devices'],
                'features' => [
                    'pos' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    'route' => 'available',
                    // Fat/SNF-graded collection from farmers, and a standing
                    // daily order that bills monthly, are both their own thing.
                    'milk_collection' => 'planned',
                    'subscription' => 'planned',
                ],
            ],

            'ice_cream' => [
                'label' => 'Ice Cream Parlour',
                'icon' => 'pi pi-sun',
                'description' => 'Flavour-wise stock, freezer locations and batch shelf life',
                // Manufacturing: a parlour that churns its own flavours produces,
                // and it is what puts the Recipes screen within reach.
                'modules' => [...$this->base(), 'manufacturing', 'loyalty', 'devices'],
                'features' => [
                    'pos' => 'available',
                    'recipe' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // As for Bakery: the menu card belongs to the Restaurant module.
                    'menu' => 'planned',
                    // A flavour is a product variant; a freezer is a warehouse
                    // location. Both exist — what is missing is the parlour-shaped
                    // screen over them.
                    'flavor' => 'planned',
                    'freezer_stock' => 'planned',
                ],
            ],
        ];
    }
}
