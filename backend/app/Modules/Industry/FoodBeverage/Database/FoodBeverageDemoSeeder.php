<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Database;

use App\Models\Batch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Industry\FoodBeverage\Models\CakeOrder;
use App\Modules\Industry\FoodBeverage\Models\CustomerMembership;
use App\Modules\Industry\FoodBeverage\Models\MembershipPlan;
use App\Modules\Industry\FoodBeverage\Models\MenuCombo;
use App\Modules\Industry\FoodBeverage\Models\MenuHappyHour;
use App\Modules\Industry\FoodBeverage\Models\MilkRateChart;
use App\Modules\Industry\FoodBeverage\Models\MilkSubscription;
use App\Modules\Industry\FoodBeverage\Models\RestaurantRecipe;
use App\Modules\Industry\FoodBeverage\Services\MembershipService;
use App\Modules\Industry\FoodBeverage\Services\MilkCollectionService;
use App\Modules\Industry\FoodBeverage\Services\MilkSubscriptionService;
use App\Modules\Industry\FoodBeverage\Services\TokenQueueService;
use App\Support\TenantContext;
use Throwable;

/**
 * Demo fixtures for the Food & Beverage trades.
 *
 * Lives inside the plugin, next to its migrations and routes, for the same reason
 * those do: the vertical should be one folder you can drop in. DemoShopSeeder calls
 * this for any F&B trade and knows nothing about cake orders or fat percentages.
 *
 * The point is that each trade's demo login shows *its* screens with data in them.
 * A cafe demo with no happy hour and no members is a cafe demo that demonstrates
 * nothing — the whole reason these workspaces exist is the difference between them.
 *
 * Deliberately self-sufficient: it creates the handful of products, customers and
 * farmers its fixtures need rather than depending on DEMO_SHOP_DATA having run, so
 * a plain `db:seed --class=DemoShopSeeder` still produces a demo you can click
 * through. Everything is `firstOrCreate`/`updateOrCreate` on a `FNB-` prefixed key,
 * so a re-seed refreshes in place instead of stacking a second set.
 *
 * Must be called inside a TenantContext.
 */
class FoodBeverageDemoSeeder
{
    /** SKU/code prefix for everything this seeder owns, so a re-run is idempotent. */
    private const PREFIX = 'FNB';

    public function __construct(private readonly ?int $userId = null) {}

    /**
     * Seed the fixtures one trade needs.
     *
     * Unknown trades are a no-op rather than an error: DemoShopSeeder passes every
     * profile key it has, and a non-F&B trade simply has nothing here.
     */
    public function seed(string $trade): void
    {
        match ($trade) {
            'restaurant' => $this->restaurant(),
            'cafe' => $this->cafe(),
            'bakery' => $this->bakery(),
            'fast_food' => $this->fastFood(),
            'juice' => $this->juice(),
            'dairy' => $this->dairy(),
            'ice_cream' => $this->iceCream(),
            // sweet_mart's features (weight scale, batch, expiry) are all core
            // inventory and already demonstrated by the baseline masters.
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Trades
    |--------------------------------------------------------------------------
    */

    /** Recipes, so settling a bill visibly depletes ingredients. */
    private function restaurant(): void
    {
        $paneer = $this->ingredient('PANEER', 'Paneer', 320);
        $cream = $this->ingredient('CREAM', 'Fresh Cream', 180);

        $dish = $this->dish('TIKKA', 'Paneer Tikka Masala', 260, 'veg');

        $this->recipe($dish, [
            [$paneer, 0.15, 5],
            [$cream, 0.05, 0],
        ]);

        // One live offer, so the Happy Hours screen is not empty for the trade
        // that most often runs one.
        $this->happyHour('Weekday Lunch', [1, 2, 3, 4, 5], '12:00', '15:00', 15, 'dine_in');
    }

    /** A counter cafe: membership tiers, a live happy hour and a combo. */
    private function cafe(): void
    {
        $coffee = $this->dish('COFFEE', 'Filter Coffee', 90, 'veg');
        $sandwich = $this->dish('SANDWICH', 'Grilled Sandwich', 140, 'veg');
        $combo = $this->dish('COMBO-CAFE', 'Coffee + Sandwich Combo', 199, 'veg');

        $this->combo($combo, 'Coffee & Sandwich', [[$coffee, 1], [$sandwich, 1]]);

        // Runs every afternoon, so `is_live_now` is true for most of a working day
        // and the screen demonstrates a running offer rather than a dormant one.
        $this->happyHour('Afternoon Refill', [1, 2, 3, 4, 5, 6, 7], '14:00', '19:00', 25);

        $plan = MembershipPlan::updateOrCreate(
            ['code' => self::PREFIX.'-GOLD'],
            [
                'name' => 'Cafe Gold',
                'description' => 'A year of 10% off every bill, and double loyalty points.',
                'price' => 1200,
                'duration_days' => 365,
                'discount_percent' => 10,
                'loyalty_multiplier' => 2,
                'benefits' => "10% off every bill\nDouble loyalty points\nFree birthday cake",
                'is_active' => true,
            ],
        );

        MembershipPlan::updateOrCreate(
            ['code' => self::PREFIX.'-SILVER'],
            [
                'name' => 'Cafe Silver',
                'description' => 'A quarter of 5% off.',
                'price' => 400,
                'duration_days' => 90,
                'discount_percent' => 5,
                'loyalty_multiplier' => 1,
                'is_active' => true,
            ],
        );

        // One card actually sold, so the Members tab and the tier's revenue column
        // both have something in them. Comped (no invoice) to keep the demo's
        // sales figures honest about what was really billed.
        $member = $this->customer('MEMBER', 'Anita Deshpande', '9820011223');

        /**
         * Queried directly rather than through a relation: `memberships` is a
         * plugin concept and Customer is a core model, so the plugin reaches into
         * the join rather than making core carry a relation to one of its verticals.
         */
        $alreadyHolds = CustomerMembership::where('customer_id', $member->id)->exists();

        if (! $alreadyHolds) {
            $this->quietly(fn () => app(MembershipService::class)->sell(
                customer: $member,
                plan: $plan,
                raiseInvoice: false,
                userId: $this->userId,
                notes: 'Demo fixture.',
            ));
        }
    }

    /** A bakery's order book, spread across the board's states. */
    private function bakery(): void
    {
        $cake = $this->dish('CAKE-CHOC', 'Chocolate Truffle Cake', 750, 'egg');

        $orders = [
            [
                'code' => 'A',
                'name' => 'Rohan Mehta',
                'phone' => '9812300011',
                'flavour' => 'Chocolate Truffle',
                'weight' => 1,
                'message' => 'Happy Birthday Aarav',
                'hours' => 6,
                'status' => 'in_production',
                'price' => 850,
                'advance' => 300,
                'eggless' => false,
            ],
            [
                'code' => 'B',
                'name' => 'Sneha Kulkarni',
                'phone' => '9812300022',
                'flavour' => 'Butterscotch',
                'weight' => 2,
                'message' => 'Congratulations!',
                'hours' => 30,
                'status' => 'booked',
                'price' => 1600,
                'advance' => 500,
                'eggless' => true,
            ],
            [
                'code' => 'C',
                'name' => 'Imran Shaikh',
                'phone' => '9812300033',
                'flavour' => 'Red Velvet',
                'weight' => 1.5,
                'message' => 'Happy Anniversary',
                'hours' => 2,
                'status' => 'ready',
                'price' => 1350,
                'advance' => 1350,
                'eggless' => true,
            ],
        ];

        foreach ($orders as $o) {
            CakeOrder::updateOrCreate(
                ['order_number' => self::PREFIX.'-CAKE-'.$o['code']],
                [
                    'customer_name' => $o['name'],
                    'customer_phone' => $o['phone'],
                    'product_id' => $cake->id,
                    'flavour' => $o['flavour'],
                    'weight_kg' => $o['weight'],
                    'shape' => 'round',
                    'tiers' => 1,
                    'is_eggless' => $o['eggless'],
                    'message_on_cake' => $o['message'],
                    'required_at' => now()->addHours($o['hours']),
                    'fulfilment' => 'pickup',
                    'delivery_charge' => 0,
                    'price' => $o['price'],
                    'advance_amount' => $o['advance'],
                    'status' => $o['status'],
                    'created_by' => $this->userId,
                ],
            );
        }
    }

    /** A QSR: a combo, and a token queue mid-service. */
    private function fastFood(): void
    {
        $burger = $this->dish('BURGER', 'Veg Burger', 90, 'veg');
        $fries = $this->dish('FRIES', 'Salted Fries', 70, 'veg');
        $drink = $this->dish('COLA', 'Cola 300ml', 50, 'veg');
        $combo = $this->dish('COMBO-QSR', 'Burger Meal', 179, 'veg');

        $this->combo($combo, 'Burger + Fries + Cola', [[$burger, 1], [$fries, 1], [$drink, 1]]);
        $this->happyHour('Evening Snack Hour', [1, 2, 3, 4, 5], '16:00', '19:00', 20, 'takeaway');

        // Three tokens at three points in the flow, so the board shows a queue
        // rather than one lonely number.
        $tokens = app(TokenQueueService::class);

        foreach ([['waiting', $combo, 1], ['preparing', $burger, 2], ['ready', $fries, 1]] as $i => [$state, $product, $qty]) {
            $order = RestaurantOrder::firstOrCreate(
                ['order_number' => self::PREFIX.'-TOK-'.($i + 1)],
                [
                    'order_type' => 'takeaway',
                    'status' => 'open',
                    'guest_count' => 1,
                    'service_charge_percent' => 0,
                    'opened_at' => now()->subMinutes(15 - ($i * 5)),
                    'created_by' => $this->userId,
                ],
            );

            RestaurantOrderItem::firstOrCreate(
                ['restaurant_order_id' => $order->id, 'product_id' => $product->id],
                [
                    'quantity' => $qty,
                    'unit_price' => $product->selling_price,
                    'tax_percent' => $product->tax_percent,
                    'status' => 'pending',
                ],
            );

            // issue() is idempotent, and advance() only moves forwards, so a
            // re-seed leaves an already-collected token where it is.
            $this->quietly(function () use ($tokens, $order, $state) {
                $fresh = $tokens->issue($order->fresh());

                if ($state !== 'waiting') {
                    $tokens->advance($fresh->fresh(), $state);
                }
            });
        }
    }

    /** A juice bar: recipes over fruit stock. */
    private function juice(): void
    {
        $orange = $this->ingredient('ORANGE', 'Oranges', 90);
        $sugar = $this->ingredient('SUGAR', 'Sugar', 45);

        $juice = $this->dish('JUICE-ORANGE', 'Fresh Orange Juice', 120, 'vegan');

        $this->recipe($juice, [
            [$orange, 0.4, 12],
            [$sugar, 0.01, 0],
        ]);
    }

    /** A dairy: a rate chart, a fortnight of collections, and a live milk round. */
    private function dairy(): void
    {
        MilkRateChart::updateOrCreate(
            ['name' => 'Cow Milk — Fat Basis'],
            [
                'milk_type' => 'cow',
                'basis' => 'fat',
                'rate_per_fat_point' => 7.2,
                'min_fat' => 3.0,
                'max_fat' => 9.0,
                'effective_from' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'created_by' => $this->userId,
            ],
        );

        MilkRateChart::updateOrCreate(
            ['name' => 'Buffalo Milk — Fat + SNF'],
            [
                'milk_type' => 'buffalo',
                'basis' => 'fat_snf',
                'rate_per_fat_point' => 6.4,
                'rate_per_snf_point' => 2.1,
                'min_fat' => 5.0,
                'max_fat' => 12.0,
                'effective_from' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'created_by' => $this->userId,
            ],
        );

        $milk = $this->dish('MILK-TONED', 'Toned Milk 500ml', 30, null);

        // --- collections: three farmers over the last few days ---------------
        $farmers = [
            ['SHINDE', 'Vitthal Shinde', 12.5, 6.2],
            ['PATIL', 'Kisan Patil', 8.0, 5.4],
            ['JADHAV', 'Sarita Jadhav', 15.0, 6.8],
        ];

        $service = app(MilkCollectionService::class);

        foreach ($farmers as [$code, $name, $litres, $fat]) {
            $farmer = Supplier::firstOrCreate(
                ['code' => self::PREFIX.'-'.$code],
                ['name' => $name, 'is_active' => true],
            );

            for ($daysAgo = 2; $daysAgo >= 0; $daysAgo--) {
                $date = now()->subDays($daysAgo)->toDateString();

                // The unique (farmer, date, shift) index makes a re-seed throw
                // rather than duplicate — which is the behaviour we want, so it is
                // swallowed here instead of guarded with an existence check.
                $this->quietly(fn () => $service->record([
                    'supplier_id' => $farmer->id,
                    'collection_date' => $date,
                    'shift' => 'morning',
                    'milk_type' => 'cow',
                    // A little variation per day so the trend lines are not flat.
                    'quantity_litres' => round($litres + ($daysAgo * 0.4), 3),
                    'fat_percent' => round($fat - ($daysAgo * 0.1), 2),
                    'snf_percent' => 8.5,
                ], $this->userId));
            }
        }

        // --- household subscriptions + today's round -------------------------
        $households = [
            ['GOKHALE', 'Madhuri Gokhale', '9822011001', 1.0, 'morning', [1, 2, 3, 4, 5, 6, 7]],
            ['RANE', 'Prakash Rane', '9822011002', 0.5, 'morning', [1, 3, 5]],
            ['FERNANDES', 'Joel Fernandes', '9822011003', 1.5, 'both', [1, 2, 3, 4, 5, 6, 7]],
        ];

        foreach ($households as [$code, $name, $phone, $qty, $shift, $days]) {
            $customer = $this->customer($code, $name, $phone);

            MilkSubscription::updateOrCreate(
                ['subscription_number' => self::PREFIX.'-MS-'.$code],
                [
                    'customer_id' => $customer->id,
                    'product_id' => $milk->id,
                    'quantity_per_day' => $qty,
                    'rate' => 30,
                    'shift' => $shift,
                    'days' => $days,
                    'start_date' => now()->subMonth()->toDateString(),
                    'status' => 'active',
                    'created_by' => $this->userId,
                ],
            );
        }

        // Write today's sheet so the Milk Round screen opens with a round on it.
        $this->quietly(fn () => app(MilkSubscriptionService::class)
            ->generateRound(now()->toDateString(), null, $this->userId));
    }

    /** A parlour: flavours as variants, in freezers, with shelf life. */
    private function iceCream(): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $tub = $this->dish('ICE-TUB', 'Ice Cream Tub 500ml', 260, 'veg');
        $tub->update(['has_variants' => true, 'track_batch' => true, 'track_expiry' => true]);

        $freezers = [
            ['FRZ-1', 'Front Display Freezer', -18.0],
            ['FRZ-2', 'Back Store Freezer', -22.0],
        ];

        $locations = [];

        foreach ($freezers as [$code, $name, $temp]) {
            $locations[] = WarehouseLocation::updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'code' => self::PREFIX.'-'.$code],
                [
                    'name' => $name,
                    'type' => 'rack',
                    'is_active' => true,
                    'is_freezer' => true,
                    'target_temp_c' => $temp,
                ],
            );
        }

        /**
         * Flavours, with deliberately mixed shelf life: one already expired, one
         * inside the fortnight and two comfortable. The parlour screen sorts
         * soonest-first, so this is what makes that ordering mean something.
         */
        $flavours = [
            ['VANILLA', 'Vanilla', 0, 120],
            ['BUTTERSCOTCH', 'Butterscotch', 0, 9],
            ['CHOCOLATE', 'Chocolate', 1, 45],
            ['MANGO', 'Mango', 1, -3],
        ];

        foreach ($flavours as [$code, $label, $freezerIndex, $expiresInDays]) {
            $variant = ProductVariant::updateOrCreate(
                ['sku' => self::PREFIX.'-ICE-'.$code],
                [
                    'product_id' => $tub->id,
                    'name' => $label,
                    'attributes' => ['flavour' => $label],
                    'selling_price' => 260,
                    'cost_price' => 130,
                    'is_active' => true,
                ],
            );

            $location = $locations[$freezerIndex] ?? $locations[0];

            StockLocation::updateOrCreate(
                [
                    'location_id' => $location->id,
                    'product_id' => $tub->id,
                    'variant_id' => $variant->id,
                ],
                ['quantity' => 6 + ($freezerIndex * 4)],
            );

            Batch::updateOrCreate(
                [
                    'product_id' => $tub->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'batch_number' => self::PREFIX.'-B-'.$code,
                ],
                [
                    'expiry_date' => now()->addDays($expiresInDays)->toDateString(),
                    'quantity' => 6 + ($freezerIndex * 4),
                    'unit_cost' => 130,
                ],
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture helpers
    |--------------------------------------------------------------------------
    */

    /** A sellable menu item. */
    private function dish(string $code, string $name, float $price, ?string $foodType): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'fnb-menu'],
            ['name' => 'Menu'],
        );

        return Product::updateOrCreate(
            ['sku' => self::PREFIX.'-'.$code],
            [
                'name' => $name,
                'category_id' => $category->id,
                'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
                'selling_price' => $price,
                'cost_price' => round($price * 0.4, 2),
                'tax_percent' => 5,
                'food_type' => $foodType,
                'is_available' => true,
                'is_active' => true,
                'kitchen_station' => 'kitchen',
            ],
        );
    }

    /** A stocked ingredient, priced per kilo/litre. */
    private function ingredient(string $code, string $name, float $cost): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'fnb-ingredients'],
            ['name' => 'Ingredients'],
        );

        return Product::updateOrCreate(
            ['sku' => self::PREFIX.'-ING-'.$code],
            [
                'name' => $name,
                'category_id' => $category->id,
                'unit_id' => Unit::where('short_name', 'kg')->value('id'),
                'cost_price' => $cost,
                'selling_price' => 0,
                'tax_percent' => 0,
                'is_active' => true,
            ],
        );
    }

    private function customer(string $code, string $name, string $phone): Customer
    {
        return Customer::updateOrCreate(
            ['code' => self::PREFIX.'-'.$code],
            ['name' => $name, 'phone' => $phone, 'type' => 'retail', 'is_active' => true],
        );
    }

    /**
     * A dish's recipe.
     *
     * @param  list<array{0: Product, 1: float, 2: float}>  $lines  [ingredient, qty, wastage%]
     */
    private function recipe(Product $dish, array $lines): void
    {
        $recipe = RestaurantRecipe::updateOrCreate(
            ['product_id' => $dish->id],
            ['yield_quantity' => 1, 'is_active' => true, 'created_by' => $this->userId],
        );

        // Rewritten wholesale, the way the recipe editor does it, so a re-seed
        // cannot leave a stale ingredient behind.
        $recipe->items()->delete();

        foreach ($lines as [$ingredient, $quantity, $wastage]) {
            $recipe->items()->create([
                'product_id' => $ingredient->id,
                'quantity' => $quantity,
                'wastage_percent' => $wastage,
                'unit_cost' => $ingredient->cost_price,
                'total_cost' => round($quantity * (1 + $wastage / 100) * (float) $ingredient->cost_price, 2),
            ]);
        }

        $recipe->load('items');
        $recipe->recost();
    }

    /**
     * A combo and its components.
     *
     * @param  list<array{0: Product, 1: float}>  $components
     */
    private function combo(Product $product, string $name, array $components): void
    {
        $combo = MenuCombo::updateOrCreate(
            ['product_id' => $product->id],
            ['name' => $name, 'is_active' => true, 'created_by' => $this->userId],
        );

        $combo->items()->delete();

        foreach ($components as $i => [$component, $quantity]) {
            $combo->items()->create([
                'product_id' => $component->id,
                'quantity' => $quantity,
                'sort_order' => $i,
            ]);
        }

        $product->update(['is_combo' => true]);

        $combo->load('items.product');
        $combo->revalue();
    }

    /** @param  list<int>  $days */
    private function happyHour(
        string $name,
        array $days,
        string $start,
        string $end,
        float $percent,
        string $appliesTo = 'all',
    ): void {
        MenuHappyHour::updateOrCreate(
            ['name' => $name],
            [
                'days' => $days,
                'start_time' => $start,
                'end_time' => $end,
                // Scoped to the whole card: a demo offer that covered one category
                // would look like it was not working on every other dish.
                'scope' => 'all',
                'discount_type' => 'percent',
                'discount_value' => $percent,
                'applies_to' => $appliesTo,
                'priority' => 10,
                'is_active' => true,
                'created_by' => $this->userId,
            ],
        );
    }

    /**
     * Run a fixture step, swallowing a failure.
     *
     * Demo fixtures must never break a seed. Several of these call real services
     * whose guards are the point of the feature — one active card per customer, one
     * collection per farmer per shift, forward-only tokens — so on a re-seed they
     * are *supposed* to refuse. Catching that is how this stays idempotent without
     * re-implementing each guard as an existence check.
     */
    private function quietly(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // Already seeded, or a guard did its job. Either way, nothing to do.
        }
    }

    /** Convenience for callers outside a tenant context. */
    public static function forTenant(int $tenantId, string $trade, ?int $userId = null): void
    {
        app(TenantContext::class)->runWith(
            $tenantId,
            fn () => (new self($userId))->seed($trade),
        );
    }
}
