<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Database;

use App\Models\PosCounter;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use App\Modules\Industry\Grocery\Models\SelfCheckoutCart;
use App\Modules\Industry\Grocery\Models\ShelfPlacement;
use App\Services\PosCounterService;

/**
 * Demo fixtures for the Grocery vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * Keyed on modules rather than on the trade, because the two trades in this
 * vertical differ by exactly what they switched on: a kirana has no aisles, so
 * everything below is behind `supermarket`.
 *
 * A supermarket demo that opens onto a tidy shop demonstrates nothing about being
 * a supermarket. The interesting states are the point, and each is here on
 * purpose:
 *
 *  - **A gapped shelf and an empty one.** The replenishment round is the whole
 *    reason the planogram exists, and a floor where everything is full shows
 *    nothing but a list of products.
 *  - **A basket stopped at a lane.** The age check is the mechanism the trade
 *    turns on, and it is invisible until something has actually tripped it.
 *  - **An open till with money already through it.** The X report reads zero on
 *    a drawer nobody has traded against, which is the one number that proves
 *    nothing about cash-up.
 *
 * Idempotent — every row is matched on a prefixed key, so a re-seed refreshes the
 * shop rather than doubling it.
 */
class DemoSeeder implements IndustryDemoFixtures
{
    /**
     * What this vertical contributes to the operator's Demo Data picker, one
     * chip per module it seeds, filed under the menu screen the chip fills.
     *
     * @return array<string, array{label: string, route: string, modules?: list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'grocery' => ['label' => 'Combo Packs & Supplier Credit', 'route' => '/grocery/combo-packs'],
            'supermarket' => ['label' => 'Aisles, Planogram & Self-Checkout', 'route' => '/supermarket/shelves'],
        ];
    }

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        /**
         * The kirana half — combo packs and the distributor khata — is gated on
         * `grocery` and belongs to *both* trades in this vertical, so it runs
         * first and before the supermarket guard below. Kept in its own class
         * rather than in this one because the two halves are read by different
         * people: this file is the shop floor and the lane, that one is the
         * shelf label and the cheque book.
         */
        if (in_array('grocery', $modules, true)) {
            (new KiranaFixtures)->seed($userId);
        }

        if (! in_array('supermarket', $modules, true)) {
            return;
        }

        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $products = $this->products();
        $counters = $this->counters($warehouse, $userId);
        $locations = $this->floor($warehouse);

        $this->planogram($locations, $products);
        $this->baskets($counters, $warehouse, $products);
    }

    /**
     * A handful of lines a supermarket actually sells, including the one that
     * makes the lane stop.
     *
     * `firstOrCreate` on the SKU: the generic demo generator may already have
     * made products, and a second aisle full of near-duplicates would make the
     * floor screen useless.
     *
     * @return array<string, Product>
     */
    private function products(): array
    {
        $rows = [
            'atta' => ['sku' => 'SM-ATTA-5', 'name' => 'Whole Wheat Atta 5kg', 'cost_price' => 210, 'selling_price' => 265, 'tax_percent' => 0, 'hsn_code' => '1101'],
            'oil' => ['sku' => 'SM-OIL-1L', 'name' => 'Sunflower Oil 1L', 'cost_price' => 118, 'selling_price' => 145, 'tax_percent' => 5, 'hsn_code' => '1512'],
            'biscuit' => ['sku' => 'SM-BISC-200', 'name' => 'Marie Biscuits 200g', 'cost_price' => 22, 'selling_price' => 30, 'tax_percent' => 18, 'hsn_code' => '1905'],
            'soap' => ['sku' => 'SM-SOAP-4', 'name' => 'Bath Soap 4-pack', 'cost_price' => 132, 'selling_price' => 180, 'tax_percent' => 18, 'hsn_code' => '3401'],
            'milk' => ['sku' => 'SM-MILK-500', 'name' => 'Toned Milk 500ml', 'cost_price' => 25, 'selling_price' => 30, 'tax_percent' => 0, 'hsn_code' => '0401'],
            // The reason the lane has a supervisor.
            'beer' => ['sku' => 'SM-BEER-650', 'name' => 'Lager 650ml', 'cost_price' => 118, 'selling_price' => 160, 'tax_percent' => 18, 'hsn_code' => '2203', 'is_age_restricted' => true],
        ];

        $out = [];

        foreach ($rows as $key => $attrs) {
            $product = Product::firstOrCreate(
                ['sku' => $attrs['sku']],
                $attrs + ['is_active' => true, 'track_batch' => true, 'track_expiry' => true],
            );

            // A product the generic generator made first would not carry the
            // flag; set it rather than leaving a demo whose age check never fires.
            if (($attrs['is_age_restricted'] ?? false) && ! $product->is_age_restricted) {
                $product->forceFill(['is_age_restricted' => true])->save();
            }

            $out[$key] = $product;
        }

        return $out;
    }

    /**
     * Two attended tills and a self-checkout lane, with the first till open and
     * trading.
     *
     * @return array<string, PosCounter>
     */
    private function counters(Warehouse $warehouse, int $userId): array
    {
        $rows = [
            'till1' => ['code' => 'TILL-1', 'name' => 'Till 1', 'mode' => 'attended', 'location' => 'Front row, left', 'default_float' => 2000],
            'till2' => ['code' => 'TILL-2', 'name' => 'Till 2', 'mode' => 'attended', 'location' => 'Front row, centre', 'default_float' => 2000],
            'sco1' => ['code' => 'SCO-1', 'name' => 'Self-Checkout 1', 'mode' => 'self_checkout', 'location' => 'Near exit', 'default_float' => 500],
        ];

        $out = [];

        foreach ($rows as $key => $attrs) {
            $out[$key] = PosCounter::firstOrCreate(
                ['code' => $attrs['code']],
                $attrs + ['warehouse_id' => $warehouse->id, 'is_active' => true],
            );
        }

        /**
         * Open the attended till and the lane, but not Till 2 — a bank where
         * every drawer is open says nothing about the state, and the counters
         * screen is largely a picture of which lanes are running.
         */
        $sessions = app(PosCounterService::class);

        foreach (['till1', 'sco1'] as $key) {
            if (! $out[$key]->sessions()->where('status', 'open')->exists()) {
                $sessions->open($out[$key], $userId, (float) $out[$key]->default_float);
            }
        }

        return $out;
    }

    /**
     * The floor: a backroom to pick from, and three shelves to fill.
     *
     * Shelves are ordinary warehouse locations — the same tree Inventory shows —
     * flagged and given a shopper-facing address. The backroom is deliberately a
     * plain location with `is_shelf` false, because that flag is exactly what
     * makes it the source end of a replenishment.
     *
     * @return array<string, WarehouseLocation>
     */
    private function floor(Warehouse $warehouse): array
    {
        $rows = [
            'backroom' => ['code' => 'SM-BACK', 'name' => 'Backroom', 'type' => 'zone', 'is_shelf' => false],
            'a1' => ['code' => 'SM-A1-B1', 'name' => 'Staples', 'type' => 'shelf', 'is_shelf' => true, 'aisle' => '1', 'bay' => '1', 'shelf_level' => 2],
            'a2' => ['code' => 'SM-A2-B1', 'name' => 'Snacks', 'type' => 'shelf', 'is_shelf' => true, 'aisle' => '2', 'bay' => '1', 'shelf_level' => 3],
            'a3' => ['code' => 'SM-A3-B1', 'name' => 'Household & Beverages', 'type' => 'shelf', 'is_shelf' => true, 'aisle' => '3', 'bay' => '1', 'shelf_level' => 2],
        ];

        $out = [];

        foreach ($rows as $key => $attrs) {
            $out[$key] = WarehouseLocation::firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'code' => $attrs['code']],
                $attrs + ['is_active' => true],
            );
        }

        return $out;
    }

    /**
     * What belongs where, and what is actually there.
     *
     * The shelf quantities are the fixture's whole value. Read them against the
     * minimums: atta is comfortable, biscuits are below, soap is empty and milk is
     * below on a shelf that also holds the age-restricted line. That produces a
     * replenishment round with three entries in a sensible order rather than a
     * screen saying "nothing to do".
     *
     * @param  array<string, WarehouseLocation>  $locations
     * @param  array<string, Product>  $products
     */
    private function planogram(array $locations, array $products): void
    {
        // [shelf, product, facings, min, max, on shelf now, in the backroom]
        $plan = [
            ['a1', 'atta', 4, 12, 40, 26, 120],
            ['a1', 'oil', 6, 18, 60, 21, 200],
            ['a2', 'biscuit', 8, 24, 96, 9, 300],
            ['a3', 'soap', 4, 10, 36, 0, 80],
            ['a3', 'milk', 6, 20, 72, 14, 90],
            ['a3', 'beer', 3, 12, 36, 18, 60],
        ];

        $sequence = [];

        foreach ($plan as [$shelfKey, $productKey, $facings, $min, $max, $onShelf, $inBack]) {
            $shelf = $locations[$shelfKey];
            $product = $products[$productKey];

            $sequence[$shelfKey] = ($sequence[$shelfKey] ?? 0) + 10;

            ShelfPlacement::updateOrCreate(
                ['location_id' => $shelf->id, 'product_id' => $product->id, 'variant_id' => null],
                [
                    'facings' => $facings,
                    'min_qty' => $min,
                    'max_qty' => $max,
                    'sequence' => $sequence[$shelfKey],
                    'is_active' => true,
                ],
            );

            // Written directly rather than through ShelfService::replenish: that
            // method moves stock *between* two locations and there is nothing to
            // move from yet. This is the opening position, not a movement.
            StockLocation::updateOrCreate(
                ['location_id' => $shelf->id, 'product_id' => $product->id, 'variant_id' => null],
                ['quantity' => $onShelf],
            );

            StockLocation::updateOrCreate(
                ['location_id' => $locations['backroom']->id, 'product_id' => $product->id, 'variant_id' => null],
                ['quantity' => $inBack],
            );
        }
    }

    /**
     * Two baskets at the lane: one quietly scanning, one stopped on the beer.
     *
     * Built by hand rather than through SelfCheckoutService::scan, because that
     * method (rightly) refuses a line the warehouse cannot cover, and the demo's
     * warehouse stock is whatever the generic generator happened to leave. A
     * fixture that silently seeded nothing whenever stock was short would be worse
     * than one that states its own opening position.
     *
     * @param  array<string, PosCounter>  $counters
     * @param  array<string, Product>  $products
     */
    private function baskets(array $counters, Warehouse $warehouse, array $products): void
    {
        $lane = $counters['sco1'];

        $baskets = [
            'SC-DEMO01' => [
                'status' => 'scanning',
                'lines' => [
                    ['biscuit', 2, false],
                    ['milk', 1, false],
                ],
            ],
            'SC-DEMO02' => [
                'status' => 'awaiting_approval',
                'lines' => [
                    ['atta', 1, false],
                    ['beer', 2, true],
                ],
            ],
        ];

        foreach ($baskets as $number => $spec) {
            $cart = SelfCheckoutCart::firstOrCreate(
                ['cart_number' => $number],
                [
                    'counter_id' => $lane->id,
                    'warehouse_id' => $warehouse->id,
                    'status' => $spec['status'],
                ],
            );

            // Re-seeding refreshes the basket rather than appending to it, or a
            // second run leaves a demo customer holding twelve bottles.
            $cart->items()->delete();
            $cart->forceFill(['status' => $spec['status']])->save();

            foreach ($spec['lines'] as [$productKey, $qty, $flagged]) {
                $product = $products[$productKey];

                $cart->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => $qty,
                    'unit_price' => (float) $product->selling_price,
                    'tax_percent' => (float) $product->tax_percent,
                    'needs_approval' => $flagged,
                    'approval_reason' => $flagged ? 'Age-restricted item — assistant must verify' : null,
                    'is_voided' => false,
                ]);
            }
        }
    }
}
