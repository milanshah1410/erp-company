<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Database;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Gift\Models\GiftOccasion;
use App\Modules\Industry\Gift\Models\GiftPack;
use App\Modules\Industry\Gift\Models\GiftPackBuild;
use App\Modules\Industry\Gift\Models\GiftWrap;
use App\Modules\Industry\Gift\Models\GiftWrapOption;
use App\Modules\Industry\Gift\Services\GiftPackService;
use App\Services\StockService;

/**
 * Demo fixtures for the Gift & Novelty vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A gift-shop login that opens onto an empty wrap desk demonstrates nothing about
 * being a gift shop, so this seeds a shop in the week before Diwali: three
 * wrapping options with real material behind them, a desk with one parcel late and
 * one waiting, two hamper packs — one already built into stock and one short of a
 * component — and an occasion board with a call owed today.
 *
 * The awkward states are the point:
 *
 *  - **A late parcel.** A wrap desk that is all tidy shows nothing about the only
 *    thing a wrap desk is for: knowing what was promised for six o'clock.
 *  - **A pack that cannot be built.** `buildable: 0` on a live pack is the whole
 *    reason the screen carries that number, and a shop where everything is in
 *    stock would never show it.
 *  - **An occasion owed a reminder today**, one three weeks out, and one one-off
 *    wedding — so the board demonstrates lead time rather than a list of dates.
 *
 * Keyed on the module, not the trade: the vertical has one trade, and a workspace
 * that ticked `gift` on top of another preset should get these fixtures too.
 * Idempotent — every row is matched on a prefixed key, so a re-seed refreshes the
 * shop rather than doubling it.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('gift', $modules, true)) {
            return;
        }

        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $options = $this->wrapOptions($warehouse);
        $occasions = $this->occasions($userId);
        $this->parcels($options, $occasions, $userId);
        $this->packs($warehouse, $options, $userId);
    }

    /**
     * Three ways to wrap something, and the material behind two of them.
     *
     * The charge is billed as a service product so it reaches a bill through the
     * ordinary sales spine; the material is a stocked product so a parcel depletes
     * something. Both are created here — a fixture that depends on a shop already
     * having "Gift Wrapping" in its product master would seed nothing on most
     * workspaces.
     *
     * @return array<string, GiftWrapOption>
     */
    private function wrapOptions(Warehouse $warehouse): array
    {
        $service = $this->product('GFT-SVC-WRAP', 'Gift Wrapping Charge', selling: 30, cost: 0, service: true);

        $paper = $this->stockedProduct('GFT-MAT-PPR', 'Kraft Wrapping Sheet', selling: 15, cost: 6, warehouse: $warehouse, quantity: 400);
        $box = $this->stockedProduct('GFT-MAT-BOX', 'Rigid Gift Box (Medium)', selling: 90, cost: 44, warehouse: $warehouse, quantity: 120);
        $basket = $this->stockedProduct('GFT-MAT-BSK', 'Cane Hamper Basket', selling: 220, cost: 130, warehouse: $warehouse, quantity: 60);

        $rows = [
            'kraft' => [
                'code' => 'WRP-KRAFT', 'name' => 'Kraft paper & jute twine', 'kind' => 'paper',
                'charge' => 30, 'product_id' => $service->id,
                'consumes_product_id' => $paper->id, 'consume_quantity' => 1,
                'turnaround_minutes' => 10,
            ],
            'box' => [
                'code' => 'WRP-BOX', 'name' => 'Rigid box with ribbon', 'kind' => 'box',
                'charge' => 120, 'product_id' => $service->id,
                'consumes_product_id' => $box->id, 'consume_quantity' => 1,
                'turnaround_minutes' => 25,
            ],
            /**
             * Free, and the basket is charged inside the hamper's own price rather
             * than as a wrapping line. Demonstrates that "wrapping" and "charging
             * for wrapping" are separate decisions.
             */
            'basket' => [
                'code' => 'WRP-BSKT', 'name' => 'Hamper basket & cellophane', 'kind' => 'basket',
                'charge' => 0, 'product_id' => null,
                'consumes_product_id' => $basket->id, 'consume_quantity' => 1,
                'turnaround_minutes' => 40,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = GiftWrapOption::firstOrCreate(
                ['code' => $attrs['code']],
                $attrs + ['is_active' => true],
            );
        }

        return $out;
    }

    /**
     * The board: a call owed today, one due next week, one a fortnight out, and a
     * wedding that happens once.
     *
     * @return array<string, GiftOccasion>
     */
    private function occasions(int $userId): array
    {
        $meera = $this->customer('GFT-C01', 'Meera Trivedi', '9824055001');
        $rahul = $this->customer('GFT-C02', 'Rahul Bhatt', '9824055002');
        $nikhil = $this->customer('GFT-C03', 'Nikhil Joshi', '9824055003');

        /**
         * Dates are anchored to *today* so the demo stays interesting whenever it
         * is seeded — a fixture with a hard-coded 12 November demonstrates nothing
         * in December. The stored year is a real one (people are born in a year);
         * what the board shows is derived from the day and month.
         */
        $rows = [
            // Reminder owed right now: five days out against a seven-day lead time.
            'wife' => [
                'customer_id' => $meera->id,
                'person_name' => 'Anjali Trivedi', 'relation' => 'Wife',
                'occasion' => 'birthday',
                'occasion_date' => today()->addDays(5)->subYears(34),
                'remind_days_before' => 7,
                'budget' => 3000,
                'preferences' => 'Likes perfume and silver. No chocolates — allergic.',
                'last_gift' => 'Silver photo frame (last year)',
                'last_gifted_on' => today()->addDays(5)->subYear(),
            ],
            // A fortnight out: on the board, not yet a to-do.
            'mother' => [
                'customer_id' => $rahul->id,
                'person_name' => 'Sudha Bhatt', 'relation' => 'Mother',
                'occasion' => 'birthday',
                'occasion_date' => today()->addDays(19)->subYears(61),
                'remind_days_before' => 10,
                'budget' => 1500,
                'preferences' => 'Prefers something for the house.',
            ],
            // Today. The one the counter should already have called about.
            'anniversary' => [
                'customer_id' => $nikhil->id,
                'person_name' => null, 'relation' => null,
                'occasion' => 'anniversary',
                'occasion_date' => today()->subYears(9),
                'remind_days_before' => 7,
                'budget' => 5000,
                'last_gift' => 'Crystal vase (last year)',
                // Reminded when the window opened, which is why it is not on the
                // "owed" list even though it falls today — exactly the behaviour
                // last_reminded_on exists for.
                'last_reminded_on' => today()->subDays(6),
            ],
            // Once only: a wedding does not come round again, and must drop off the
            // board afterwards rather than reappearing next year.
            'wedding' => [
                'customer_id' => $rahul->id,
                'person_name' => 'Kavita & Sameer', 'relation' => 'Niece',
                'occasion' => 'wedding', 'title' => 'Kavita\'s wedding',
                'occasion_date' => today()->addDays(26),
                'is_recurring' => false,
                'remind_days_before' => 14,
                'budget' => 11000,
                'notify_customer' => false,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = GiftOccasion::firstOrCreate(
                [
                    'customer_id' => $attrs['customer_id'],
                    'occasion' => $attrs['occasion'],
                    'person_name' => $attrs['person_name'] ?? null,
                ],
                $attrs + ['is_active' => true, 'created_by' => $userId],
            );
        }

        return $out;
    }

    /**
     * A desk mid-evening: one parcel late, one waiting, one already wrapped and
     * waiting to be collected.
     *
     * @param  array<string, GiftWrapOption>  $options
     * @param  array<string, GiftOccasion>  $occasions
     */
    private function parcels(array $options, array $occasions, int $userId): void
    {
        $rows = [
            // Late: promised for yesterday afternoon and still on the bench.
            'DEMO-GW-1' => [
                'option' => $options['box'],
                'customer_id' => $occasions['wife']->customer_id,
                'occasion_id' => $occasions['wife']->id,
                'recipient_name' => 'Anjali Trivedi',
                'sender_name' => 'Meera',
                'message' => 'Happy birthday! With all our love.',
                'promised_at' => now()->subDay()->setTime(18, 0),
                'status' => 'pending',
            ],
            // Waiting, promised for later today.
            'DEMO-GW-2' => [
                'option' => $options['kraft'],
                'customer_id' => $occasions['anniversary']->customer_id,
                'occasion_id' => $occasions['anniversary']->id,
                'recipient_name' => 'Priya Joshi',
                'sender_name' => 'Nikhil',
                'message' => 'Nine years. Thank you for all of them.',
                'promised_at' => now()->addHours(3),
                'status' => 'pending',
            ],
            // Done, on the shelf behind the counter.
            'DEMO-GW-3' => [
                'option' => $options['kraft'],
                'customer_id' => $occasions['mother']->customer_id,
                'recipient_name' => 'Sudha Bhatt',
                'sender_name' => 'Rahul',
                'promised_at' => now()->subHours(2),
                'status' => 'wrapped',
                'wrapped_at' => now()->subHours(3),
            ],
        ];

        foreach ($rows as $number => $attrs) {
            if (GiftWrap::where('wrap_number', $number)->exists()) {
                continue;
            }

            $option = $attrs['option'];
            unset($attrs['option']);

            /**
             * Created directly rather than through GiftWrapService, deliberately:
             * the wrapped fixture must *not* deplete material, because a demo that
             * consumes three sheets of paper every time it is re-seeded slowly
             * drives its own stock negative. `material_posted_at` is left null so
             * the first real "wrap" in the demo still demonstrates the movement.
             */
            GiftWrap::create($attrs + [
                'wrap_number' => $number,
                'wrap_option_id' => $option->id,
                'wrap_label' => $option->name,
                'charge' => (float) $option->charge,
                'hide_prices' => true,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Two hampers: one already built and sitting on the shelf, one that cannot be
     * built because a component is short.
     *
     * @param  array<string, GiftWrapOption>  $options
     */
    private function packs(Warehouse $warehouse, array $options, int $userId): void
    {
        // Contents. Deliberately uneven stock: the dry fruit is plentiful, the
        // scented candle is nearly gone.
        $sweets = $this->stockedProduct('GFT-SWT-01', 'Kaju Katli Box 500g', selling: 690, cost: 470, warehouse: $warehouse, quantity: 90);
        $dryFruit = $this->stockedProduct('GFT-DRY-01', 'Assorted Dry Fruit Pack 400g', selling: 540, cost: 360, warehouse: $warehouse, quantity: 120);
        $chocolate = $this->stockedProduct('GFT-CHO-01', 'Imported Chocolate Assortment', selling: 450, cost: 280, warehouse: $warehouse, quantity: 60);
        $candle = $this->stockedProduct('GFT-CND-01', 'Scented Candle Trio', selling: 380, cost: 210, warehouse: $warehouse, quantity: 4);
        $diya = $this->stockedProduct('GFT-DIY-01', 'Brass Diya Set of 4', selling: 620, cost: 390, warehouse: $warehouse, quantity: 35);

        // The hampers themselves. Priced at what the shop asks, which is what the
        // till reads — the pack holds contents, never a second price.
        $deluxeProduct = $this->product('GFT-HMP-DLX', 'Diwali Deluxe Hamper', selling: 2450, cost: 0);
        $premiumProduct = $this->product('GFT-HMP-PRM', 'Festive Premium Hamper', selling: 3200, cost: 0);

        $deluxe = GiftPack::firstOrCreate(
            ['code' => 'HMP-DLX'],
            [
                'name' => 'Diwali Deluxe Hamper',
                'product_id' => $deluxeProduct->id,
                'occasion_tag' => 'diwali',
                'wrap_option_id' => $options['basket']->id,
                'notes' => 'The volume seller. Priced under the sum of its parts on purpose.',
                'is_active' => true,
                'created_by' => $userId,
            ],
        );

        if ($deluxe->items()->count() === 0) {
            $this->items($deluxe, [
                [$sweets->id, 1],
                [$dryFruit->id, 1],
                [$diya->id, 1],
            ]);
        }

        $premium = GiftPack::firstOrCreate(
            ['code' => 'HMP-PRM'],
            [
                'name' => 'Festive Premium Hamper',
                'product_id' => $premiumProduct->id,
                'occasion_tag' => 'diwali',
                'wrap_option_id' => $options['basket']->id,
                'notes' => 'Cannot be built today — the candle trio is short.',
                'is_active' => true,
                'created_by' => $userId,
            ],
        );

        if ($premium->items()->count() === 0) {
            $this->items($premium, [
                [$sweets->id, 1],
                [$chocolate->id, 1],
                // Two per hamper against four in stock: the pack builds two and
                // then stops, which is what the screen's `buildable` column is for.
                [$candle->id, 2],
                [$diya->id, 1],
            ]);
        }

        // One batch actually assembled, so the shelf holds hampers and the ledger
        // explains where they came from.
        if (! GiftPackBuild::where('build_number', 'DEMO-HMP-1')->exists()) {
            $build = GiftPackBuild::create([
                'build_number' => 'DEMO-HMP-1',
                'pack_id' => $deluxe->id,
                'quantity' => 12,
                'warehouse_id' => $warehouse->id,
                'build_date' => today()->subDays(2),
                'notes' => 'Built for the festival counter display.',
                'created_by' => $userId,
            ]);

            $build->setRelation('pack', $deluxe->fresh('items'));
            app(GiftPackService::class)->post($build, $userId);
        }
    }

    /** @param  list<array{0: int, 1: float}>  $rows */
    private function items(GiftPack $pack, array $rows): void
    {
        foreach (array_values($rows) as $index => [$productId, $quantity]) {
            $pack->items()->create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'sort_order' => $index,
            ]);
        }
    }

    private function product(
        string $sku,
        string $name,
        float $selling,
        float $cost,
        bool $service = false,
    ): Product {
        return Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'selling_price' => $selling,
                'cost_price' => $cost,
                'tax_percent' => $service ? 18 : 5,
                'is_service' => $service,
                'is_active' => true,
            ],
        );
    }

    /**
     * A product with an opening balance behind it.
     *
     * Posted as `opening` rather than an adjustment, because that is what it is —
     * and only on first creation, so re-seeding a demo does not keep topping the
     * shelves up.
     */
    private function stockedProduct(
        string $sku,
        string $name,
        float $selling,
        float $cost,
        Warehouse $warehouse,
        float $quantity,
    ): Product {
        $existing = Product::where('sku', $sku)->first();

        if ($existing) {
            return $existing;
        }

        $product = $this->product($sku, $name, $selling, $cost);

        app(StockService::class)->post(
            warehouseId: (int) $warehouse->id,
            productId: (int) $product->id,
            variantId: null,
            type: 'opening',
            quantityIn: $quantity,
            quantityOut: 0,
            unitCost: $cost,
            referenceType: Product::class,
            referenceId: (int) $product->id,
            notes: 'Gift shop demo opening stock',
        );

        return $product;
    }

    private function customer(string $code, string $name, string $phone): Customer
    {
        return Customer::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'phone' => $phone,
                'type' => 'retail',
                'city' => 'Ahmedabad',
                'state' => 'Gujarat',
                'country' => 'India',
                'is_active' => true,
            ],
        );
    }
}
