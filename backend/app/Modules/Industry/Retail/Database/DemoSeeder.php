<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Database;

use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Models\Product;
use App\Models\TailorAlteration;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Retail\Models\GarmentColour;
use App\Modules\Industry\Retail\Models\GarmentFabric;
use App\Modules\Industry\Retail\Models\GarmentSizeSet;
use App\Modules\Industry\Retail\Models\GarmentStyle;
use App\Modules\Industry\Retail\Models\GarmentTrial;
use App\Modules\Industry\Retail\Services\TrialService;
use App\Modules\Industry\Retail\Services\VariantMatrixService;
use App\Services\GiftVoucherService;

/**
 * Demo fixtures for the Retail vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A garment showroom login that opens onto an empty style master demonstrates
 * nothing about being a garment showroom, so this seeds a shop mid-season: three
 * fabrics, the standard size runs and a working palette, three styles each cut
 * into a real size × colour grid, a trial book with one slip settled and one
 * running late, two gift vouchers (one part-spent), and an alteration promised
 * for tomorrow.
 *
 * The interesting states are the point:
 *
 *  - One **overdue trial**, because a trial room that is all tidy shows nothing
 *    about the only thing a trial room is for — chasing what has not come back.
 *  - One **part-spent voucher**, because the balance is the whole design and a
 *    pair of untouched cards would never exercise it.
 *  - Grids that are genuinely **incomplete** (not every colour in every size),
 *    since a full rectangle is exactly what a real apparel stock file is not.
 *
 * Keyed on modules rather than on the trade: the five retail trades differ by
 * what they switched on, and a kirana counter has no style master to fill.
 * Idempotent — every row is matched on a prefixed key, so a re-seed refreshes
 * the shop rather than doubling it.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        // Vouchers are a Loyalty & Offers feature, so a grocery or optical demo
        // that switched loyalty on gets them even though it has no styles.
        if (in_array('loyalty', $modules, true)) {
            $this->vouchers($userId);
        }

        if (! in_array('garment', $modules, true)) {
            return;
        }

        $fabrics = $this->fabrics();
        $sizeSets = $this->sizeSets();
        $this->colours();

        $styles = $this->styles($fabrics, $sizeSets, $userId);
        $products = $this->productsAndGrids($styles);

        $this->trials($products, $userId);

        // The darji book only exists where the workroom module is on — which is
        // the garment preset, but not footwear.
        if (in_array('tailoring', $modules, true)) {
            $this->alteration($products, $userId);
        }
    }

    /** @return array<string, GarmentFabric> */
    private function fabrics(): array
    {
        $rows = [
            'cotton' => [
                'code' => 'GRM-F01', 'name' => 'Combed Cotton Poplin',
                'composition' => '100% Cotton', 'weave' => 'Poplin', 'gsm' => 120,
                'width_inch' => 58, 'shrinkage_percent' => 3,
                'care' => 'Machine wash cold, warm iron', 'rate' => 180,
            ],
            'denim' => [
                'code' => 'GRM-F02', 'name' => 'Stretch Denim 11oz',
                'composition' => '98% Cotton 2% Elastane', 'weave' => 'Twill', 'gsm' => 340,
                'width_inch' => 60, 'shrinkage_percent' => 2,
                'care' => 'Wash inside out, do not bleach', 'rate' => 420,
            ],
            'rayon' => [
                'code' => 'GRM-F03', 'name' => 'Viscose Rayon',
                'composition' => '100% Viscose', 'weave' => 'Plain', 'gsm' => 95,
                'width_inch' => 44, 'shrinkage_percent' => 5,
                'care' => 'Hand wash, do not tumble dry', 'rate' => 240,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = GarmentFabric::firstOrCreate(['code' => $attrs['code']], $attrs + ['is_active' => true]);
        }

        return $out;
    }

    /** @return array<string, GarmentSizeSet> */
    private function sizeSets(): array
    {
        $out = [];

        // Straight from the model's starter list rather than a second copy of
        // the same runs — the demo and the "add the basics" button must agree.
        foreach (GarmentSizeSet::STARTERS as $name => $sizes) {
            $out[$name] = GarmentSizeSet::firstOrCreate(['name' => $name], ['sizes' => $sizes, 'is_active' => true]);
        }

        return $out;
    }

    private function colours(): void
    {
        $sort = 0;
        foreach (GarmentColour::STARTERS as $code => [$name, $hex]) {
            $sort += 10;
            GarmentColour::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'hex' => $hex, 'sort_order' => $sort, 'is_active' => true],
            );
        }
    }

    /** @return array<string, GarmentStyle> */
    private function styles(array $fabrics, array $sizeSets, int $userId): array
    {
        $rows = [
            'shirt' => [
                'code' => 'SS-2214', 'name' => 'Slim Fit Casual Shirt',
                'fabric_id' => $fabrics['cotton']->id,
                'size_set_id' => $sizeSets['Standard (XS-XXXL)']->id,
                'gender' => 'mens', 'season' => 'SS-26', 'fit' => 'Slim',
                'sleeve' => 'Full', 'neck' => 'Spread Collar', 'pattern' => 'Solid',
                'mrp' => 1499, 'cost_price' => 620,
            ],
            'jeans' => [
                'code' => 'SS-2231', 'name' => 'Mid-Rise Stretch Jeans',
                'fabric_id' => $fabrics['denim']->id,
                'size_set_id' => $sizeSets['Numeric Waist']->id,
                'gender' => 'mens', 'season' => 'SS-26', 'fit' => 'Regular',
                'pattern' => 'Solid', 'mrp' => 2299, 'cost_price' => 980,
            ],
            'kurti' => [
                'code' => 'SS-2247', 'name' => 'Printed A-Line Kurti',
                'fabric_id' => $fabrics['rayon']->id,
                'size_set_id' => $sizeSets['Standard (XS-XXXL)']->id,
                'gender' => 'womens', 'season' => 'SS-26', 'fit' => 'A-Line',
                'sleeve' => '3/4', 'pattern' => 'Printed',
                'mrp' => 1199, 'cost_price' => 430,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = GarmentStyle::firstOrCreate(
                ['code' => $attrs['code']],
                $attrs + ['is_active' => true, 'created_by' => $userId],
            );
        }

        return $out;
    }

    /**
     * One product per style, each with a grid behind it.
     *
     * Grids are deliberately ragged — the shirt runs five sizes in three
     * colours, the jeans four waists in two washes, the kurti three sizes in one
     * colour. A neat rectangle across every style is exactly what a real apparel
     * stock file never looks like, and it would hide the thing the matrix screen
     * exists to show: the holes.
     *
     * @return array<string, Product>
     */
    private function productsAndGrids(array $styles): array
    {
        $matrix = app(VariantMatrixService::class);

        $plan = [
            'shirt' => [
                'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
                'colours' => ['WHT', 'NVY', 'SKY'],
            ],
            'jeans' => [
                'sizes' => ['30', '32', '34', '36'],
                'colours' => ['NVY', 'BLK'],
            ],
            'kurti' => [
                'sizes' => ['S', 'M', 'L'],
                'colours' => ['MRN'],
            ],
        ];

        $palette = GarmentColour::pluck('hex', 'code');
        $names = GarmentColour::pluck('name', 'code');

        $out = [];

        foreach ($plan as $key => $grid) {
            $style = $styles[$key];

            $product = Product::firstOrCreate(
                ['sku' => $style->code],
                [
                    'name' => $style->name,
                    'style_id' => $style->id,
                    'cost_price' => $style->cost_price,
                    'selling_price' => $style->mrp,
                    'tax_percent' => 5,
                    'hsn_code' => '6205',
                    'is_active' => true,
                ],
            );

            // A product created by an earlier run of the generic demo generator
            // would not carry the style; attach it rather than skipping.
            if (! $product->style_id) {
                $product->forceFill(['style_id' => $style->id])->save();
            }

            $matrix->generate(
                $product,
                $grid['sizes'],
                array_map(static fn (string $code) => [
                    'name' => $names[$code] ?? $code,
                    'code' => $code,
                    'hex' => $palette[$code] ?? null,
                ], $grid['colours']),
                ['cost_price' => (float) $style->cost_price, 'selling_price' => (float) $style->mrp],
            );

            $out[$key] = $product->fresh();
        }

        return $out;
    }

    /**
     * The trial book: one slip settled (a shirt kept, a kurti returned) and one
     * still out and three days overdue.
     *
     * Issued through TrialService rather than by writing rows, so the demo also
     * exercises the reservation the service takes — a trial room whose stock
     * figures do not move would misrepresent how the screen behaves.
     */
    private function trials(array $products, int $userId): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $trials = app(TrialService::class);

        $anita = $this->customer('GRM-C01', 'Anita Shah', '9824022001');
        $vikram = $this->customer('GRM-C02', 'Vikram Desai', '9824022002');

        // Settled: went out on Monday, came back on Wednesday with the shirt kept.
        if (! GarmentTrial::where('trial_number', 'DEMO-TRIAL-1')->exists()) {
            $trial = GarmentTrial::create([
                'trial_number' => 'DEMO-TRIAL-1',
                'customer_id' => $anita->id,
                'warehouse_id' => $warehouse->id,
                'issued_at' => now()->subDays(6),
                'due_at' => now()->subDays(4)->toDateString(),
                'status' => 'open',
                'deposit_amount' => 1000,
                'deposit_mode' => 'cash',
                'notes' => 'Regular customer — two pieces to try at home.',
                'created_by' => $userId,
            ]);

            $trials->issue($trial, [
                ['product_id' => $products['shirt']->id, 'quantity' => 1, 'unit_price' => (float) $products['shirt']->selling_price],
                ['product_id' => $products['kurti']->id, 'quantity' => 1, 'unit_price' => (float) $products['kurti']->selling_price],
            ]);

            $items = $trial->fresh('items')->items;
            $trials->settle($trial->fresh(), [
                ['item_id' => $items[0]->id, 'sold_quantity' => 1, 'remarks' => 'Kept — billed at the counter'],
                ['item_id' => $items[1]->id, 'returned_quantity' => 1, 'remarks' => 'Colour did not suit'],
            ]);
        }

        // Still out, and late. The row this screen exists for.
        if (! GarmentTrial::where('trial_number', 'DEMO-TRIAL-2')->exists()) {
            $trial = GarmentTrial::create([
                'trial_number' => 'DEMO-TRIAL-2',
                'customer_id' => $vikram->id,
                'warehouse_id' => $warehouse->id,
                'issued_at' => now()->subDays(5),
                'due_at' => now()->subDays(3)->toDateString(),
                'status' => 'open',
                'deposit_amount' => 0,
                'notes' => 'Promised to return by Saturday — not yet back.',
                'created_by' => $userId,
            ]);

            $trials->issue($trial, [
                ['product_id' => $products['jeans']->id, 'quantity' => 2, 'unit_price' => (float) $products['jeans']->selling_price],
            ]);
        }
    }

    /**
     * Two gift cards: one untouched, one part-spent so the balance is visibly
     * doing its job.
     *
     * The part-spent one is written as a plain redemption with no invoice behind
     * it. That is honest for a fixture — the demo has no counter sale to point
     * at — and it is the one place a redemption row legitimately carries no
     * invoice id.
     */
    private function vouchers(int $userId): void
    {
        $service = app(GiftVoucherService::class);

        if (! GiftVoucher::where('code', 'GIFT-DEMO-2000')->exists()) {
            $service->issue([
                'code' => 'GIFT-DEMO-2000',
                'customer_id' => $this->customer('GRM-C01', 'Anita Shah', '9824022001')->id,
                'recipient_name' => 'Meera Shah',
                'face_value' => 2000,
                'issued_on' => now()->subMonth()->toDateString(),
                'expires_on' => now()->addMonths(11)->toDateString(),
                'notes' => 'Birthday gift card sold at the counter.',
            ], $userId);
        }

        if (! GiftVoucher::where('code', 'GIFT-DEMO-5000')->exists()) {
            $voucher = $service->issue([
                'code' => 'GIFT-DEMO-5000',
                'recipient_name' => 'Bearer',
                'face_value' => 5000,
                'issued_on' => now()->subMonths(2)->toDateString(),
                'expires_on' => now()->addMonths(10)->toDateString(),
                'notes' => 'Corporate gifting — partially used.',
            ], $userId);

            $voucher->redemptions()->create([
                'amount' => 1750,
                'redeemed_at' => now()->subWeeks(3),
                'reference' => 'Demo redemption',
                'created_by' => $userId,
            ]);
            $voucher->balance = 3250;
            $voucher->save();
            $voucher->refreshStatus();
        }
    }

    /** One alteration, promised for tomorrow — the counter's working row. */
    private function alteration(array $products, int $userId): void
    {
        if (TailorAlteration::where('alteration_number', 'DEMO-ALT-1')->exists()) {
            return;
        }

        $product = $products['jeans'];

        TailorAlteration::create([
            'alteration_number' => 'DEMO-ALT-1',
            'customer_id' => $this->customer('GRM-C02', 'Vikram Desai', '9824022002')->id,
            'product_id' => $product->id,
            'variant_id' => $product->variants()->orderBy('id')->value('id'),
            'qty' => 1,
            'reason' => 'Shorten length by 2 inches',
            'status' => 'in_progress',
            'charge' => 150,
            'reported_date' => now()->subDay()->toDateString(),
            'promised_date' => now()->addDay()->toDateString(),
            'notes' => 'Customer collecting Saturday evening.',
            'created_by' => $userId,
        ]);
    }

    /**
     * Matched and created on `code`, which the customer master requires and has
     * no default for — and which is what makes a re-seed find the same Anita
     * Shah rather than adding a second one.
     */
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
