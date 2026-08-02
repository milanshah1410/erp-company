<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Database;

use App\Models\Batch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Cosmetics\Models\CosmeticShade;
use App\Modules\Industry\Cosmetics\Models\OpenUnit;
use App\Modules\Industry\Cosmetics\Services\ShadeCardService;

/**
 * Demo fixtures for the Beauty & Personal Care vertical, found by convention
 * from IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A demo counter where everything is in date demonstrates nothing, because being
 * in date is not what this vertical is for. What it is for is the awkward cases,
 * so those are what get seeded:
 *
 *  - a **foundation range of twelve shades** with four of them out of stock,
 *    which is what a shade card actually looks like and the reason the screen
 *    exists;
 *  - a **batch that expired last month** sitting on the shelf, so the demo shop
 *    is stocking something its own till will refuse — exactly the situation no
 *    shop discovers on its own;
 *  - a **short-dated batch** three weeks out, so the difference between "refuse"
 *    and "mark it down" is visible on one screen;
 *  - a **mascara tester opened nine months ago on a six-month PAO**, which is the
 *    row nothing else in the ERP can hold and the one a counter manager should
 *    have binned in the spring.
 *
 * Keyed on the module rather than the trade: this vertical has one trade today,
 * and the fixtures hold whether or not the workspace also runs the loyalty desk.
 *
 * Idempotent throughout — shades are matched on brand + code, products on SKU,
 * batches on their number and testers on the article they were opened from.
 *
 * One deliberate departure: the testers are written straight to the register
 * instead of going through OpenUnitService. Opening a unit for real posts a stock
 * write-off, and a demo seeder that moves stock every time it is re-run would
 * drift the very figures the rest of the demo workspace is built on. The row is
 * what the screens read; the ledger entry is what a real counter would add.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('cosmetics', $modules, true)) {
            return;
        }

        $brands = $this->brands();
        $shades = $this->shades($brands);
        $products = $this->catalogue($brands);

        $this->shadeCard($products, $shades);
        $this->batches($products);
        $this->testers($products, $userId);
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $out = [];
        foreach (['glowline' => 'Glowline', 'bellezza' => 'Bellezza', 'auraderm' => 'AuraDerm'] as $key => $name) {
            $out[$key] = Brand::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        return $out;
    }

    /**
     * The shade card, in the two shapes a beauty counter actually holds one.
     *
     * The foundation ladder runs light to deep with real undertones on it,
     * because the depth axis is the whole reason base make-up needs a card at
     * all. The lipstick wall has no depth and is grouped by family instead — the
     * same master serving two quite different displays, which is the point of
     * making family a free string and depth nullable.
     *
     * @param  array<string, Brand>  $brands
     * @return array<string, CosmeticShade>
     */
    private function shades(array $brands): array
    {
        $rows = [
            // Foundation ladder — Glowline, depth 1 to 12.
            'f01' => ['code' => 'N10', 'name' => 'Porcelain', 'family' => 'Fair', 'finish' => 'matte', 'undertone' => 'neutral', 'depth_level' => 1, 'hex' => '#F3DCC9', 'brand' => 'glowline'],
            'f02' => ['code' => 'W15', 'name' => 'Ivory Warm', 'family' => 'Ivory', 'finish' => 'matte', 'undertone' => 'warm', 'depth_level' => 2, 'hex' => '#EFD2B6', 'brand' => 'glowline'],
            'f03' => ['code' => 'C20', 'name' => 'Shell Cool', 'family' => 'Ivory', 'finish' => 'matte', 'undertone' => 'cool', 'depth_level' => 3, 'hex' => '#E8C6AC', 'brand' => 'glowline'],
            'f04' => ['code' => 'W25', 'name' => 'Sand', 'family' => 'Beige', 'finish' => 'satin', 'undertone' => 'warm', 'depth_level' => 4, 'hex' => '#E0B694', 'brand' => 'glowline'],
            'f05' => ['code' => 'N30', 'name' => 'Natural Beige', 'family' => 'Beige', 'finish' => 'satin', 'undertone' => 'neutral', 'depth_level' => 5, 'hex' => '#D6A87F', 'brand' => 'glowline'],
            'f06' => ['code' => 'O35', 'name' => 'Golden Olive', 'family' => 'Beige', 'finish' => 'satin', 'undertone' => 'olive', 'depth_level' => 6, 'hex' => '#C99B6B', 'brand' => 'glowline'],
            'f07' => ['code' => 'W40', 'name' => 'Honey', 'family' => 'Tan', 'finish' => 'matte', 'undertone' => 'warm', 'depth_level' => 7, 'hex' => '#BC8B5C', 'brand' => 'glowline'],
            'f08' => ['code' => 'N45', 'name' => 'Caramel', 'family' => 'Tan', 'finish' => 'matte', 'undertone' => 'neutral', 'depth_level' => 8, 'hex' => '#AA774B', 'brand' => 'glowline'],
            'f09' => ['code' => 'W50', 'name' => 'Amber', 'family' => 'Deep', 'finish' => 'satin', 'undertone' => 'warm', 'depth_level' => 9, 'hex' => '#96633D', 'brand' => 'glowline'],
            'f10' => ['code' => 'C55', 'name' => 'Chestnut', 'family' => 'Deep', 'finish' => 'matte', 'undertone' => 'cool', 'depth_level' => 10, 'hex' => '#7E5133', 'brand' => 'glowline'],
            'f11' => ['code' => 'N60', 'name' => 'Espresso', 'family' => 'Deep', 'finish' => 'matte', 'undertone' => 'neutral', 'depth_level' => 11, 'hex' => '#66402A', 'brand' => 'glowline'],
            'f12' => ['code' => 'W65', 'name' => 'Mocha Rich', 'family' => 'Deep', 'finish' => 'satin', 'undertone' => 'warm', 'depth_level' => 12, 'hex' => '#4F3021', 'brand' => 'glowline'],

            // Lipstick wall — Bellezza, no depth, grouped by family.
            'l01' => ['code' => '015', 'name' => 'Nude Blush', 'family' => 'Nude', 'finish' => 'matte', 'undertone' => 'warm', 'hex' => '#C08774', 'brand' => 'bellezza', 'sort_order' => 10],
            'l02' => ['code' => '022', 'name' => 'Rose Petal', 'family' => 'Pink', 'finish' => 'satin', 'undertone' => 'cool', 'hex' => '#C86B84', 'brand' => 'bellezza', 'sort_order' => 20],
            'l03' => ['code' => '031', 'name' => 'Classic Red', 'family' => 'Red', 'finish' => 'matte', 'undertone' => 'neutral', 'hex' => '#B3121D', 'brand' => 'bellezza', 'sort_order' => 30],
            'l04' => ['code' => '038', 'name' => 'Berry Crush', 'family' => 'Berry', 'finish' => 'cream', 'undertone' => 'cool', 'hex' => '#7E2B4C', 'brand' => 'bellezza', 'sort_order' => 40],
            'l05' => ['code' => '044', 'name' => 'Coral Sunset', 'family' => 'Coral', 'finish' => 'gloss', 'undertone' => 'warm', 'hex' => '#E4654A', 'brand' => 'bellezza', 'sort_order' => 50],
            'l06' => ['code' => '051', 'name' => 'Mauve Dusk', 'family' => 'Mauve', 'finish' => 'shimmer', 'undertone' => 'cool', 'hex' => '#8E5F79', 'brand' => 'bellezza', 'sort_order' => 60],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $brand = $attrs['brand'];
            unset($attrs['brand']);

            $out[$key] = CosmeticShade::firstOrCreate(
                ['code' => $attrs['code'], 'brand_id' => $brands[$brand]->id],
                $attrs + ['is_active' => true],
            );
        }

        return $out;
    }

    /**
     * The range. Dated goods throughout, because in this trade almost everything
     * is: the PAO on each is the real one printed on that kind of pack, and the
     * mascara's six months is what makes the overdue tester below overdue.
     *
     * @param  array<string, Brand>  $brands
     * @return array<string, Product>
     */
    private function catalogue(array $brands): array
    {
        $rows = [
            'foundation' => ['sku' => 'CSM-FDN-24H', 'name' => '24H Stay Foundation 30ml', 'cost_price' => 340, 'selling_price' => 749, 'pao_months' => 12, 'brand' => 'glowline'],
            'lipstick' => ['sku' => 'CSM-LIP-MTE', 'name' => 'Velvet Matte Lipstick 3.8g', 'cost_price' => 165, 'selling_price' => 399, 'pao_months' => 18, 'brand' => 'bellezza'],
            // Six months, and the shortest clock on the counter. It is why eye
            // make-up is the thing a tester register is really for.
            'mascara' => ['sku' => 'CSM-EYE-MSC', 'name' => 'Volume Lash Mascara 9ml', 'cost_price' => 210, 'selling_price' => 499, 'pao_months' => 6, 'brand' => 'bellezza'],
            'kajal' => ['sku' => 'CSM-EYE-KJL', 'name' => 'Intense Kajal Pencil', 'cost_price' => 60, 'selling_price' => 149, 'pao_months' => 12, 'brand' => 'bellezza'],
            'sunscreen' => ['sku' => 'CSM-SKN-SPF', 'name' => 'Daily Sunscreen SPF 50 50g', 'cost_price' => 240, 'selling_price' => 549, 'pao_months' => 12, 'brand' => 'auraderm'],
            'serum' => ['sku' => 'CSM-SKN-VTC', 'name' => 'Vitamin C Serum 20ml', 'cost_price' => 390, 'selling_price' => 899, 'pao_months' => 3, 'brand' => 'auraderm'],
            'micellar' => ['sku' => 'CSM-SKN-MIC', 'name' => 'Micellar Cleansing Water 200ml', 'cost_price' => 150, 'selling_price' => 349, 'pao_months' => 12, 'brand' => 'auraderm'],
            // No PAO at all: a brush does not go off, and it is here so the
            // register is not the only thing on the shelf.
            'brush' => ['sku' => 'CSM-ACC-BRS', 'name' => 'Blending Brush Set (5 pc)', 'cost_price' => 280, 'selling_price' => 699, 'pao_months' => null, 'brand' => 'glowline'],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $product = Product::firstOrCreate(
                ['sku' => $attrs['sku']],
                [
                    'name' => $attrs['name'],
                    'brand_id' => $brands[$attrs['brand']]->id,
                    'cost_price' => $attrs['cost_price'],
                    'selling_price' => $attrs['selling_price'],
                    'tax_percent' => 18,
                    'is_active' => true,
                ],
            );

            /**
             * Dating is applied on every run, not only at creation. A demo
             * workspace seeded before this vertical existed has the articles but
             * no clock on any of them, and the whole trade is invisible without
             * it. The brush stays undated by design — `pao_months` null is a
             * fact about it, not a gap.
             */
            $product->forceFill([
                'pao_months' => $attrs['pao_months'],
                'track_batch' => $attrs['pao_months'] !== null,
                'track_expiry' => $attrs['pao_months'] !== null,
            ])->save();

            $out[$key] = $product;
        }

        return $out;
    }

    /**
     * Put the twelve shades on the foundation and the six on the lipstick.
     *
     * Through ShadeCardService rather than writing variants by hand, because the
     * generator is the thing being demonstrated and a seeder that quietly
     * produced different rows than the button does would be a demo of something
     * that does not exist.
     *
     * @param  array<string, Product>  $products
     * @param  array<string, CosmeticShade>  $shades
     */
    private function shadeCard(array $products, array $shades): void
    {
        $cards = app(ShadeCardService::class);

        $foundation = array_map(
            static fn (string $k) => $shades[$k]->id,
            ['f01', 'f02', 'f03', 'f04', 'f05', 'f06', 'f07', 'f08', 'f09', 'f10', 'f11', 'f12'],
        );
        $lipstick = array_map(
            static fn (string $k) => $shades[$k]->id,
            ['l01', 'l02', 'l03', 'l04', 'l05', 'l06'],
        );

        // Additive and idempotent by design (see ShadeCardService), so a re-run
        // adds nothing and the barcodes already printed survive.
        $cards->generate($products['foundation'], $foundation);
        $cards->generate($products['lipstick'], $lipstick);

        $this->shadeStock($products['foundation']);
    }

    /**
     * Stock the foundation range unevenly, which is the only way it is ever
     * stocked.
     *
     * Four shades at zero, and deliberately the four in the middle of the depth
     * ladder — that is where a range actually goes out, and a demo card with a
     * tidy holding in every cell would show a screen no beauty buyer has ever
     * seen. The gaps are the reason the card is worth opening.
     */
    private function shadeStock(Product $foundation): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        // Depth 5, 6, 7 and 8 — the middle of the ladder, and the four every
        // counter is perpetually chasing.
        $outOfStock = ['N30', 'O35', 'W40', 'N45'];

        foreach ($foundation->variants()->with('cosmeticShade')->get() as $variant) {
            $code = $variant->cosmeticShade?->code;

            WarehouseStock::updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $foundation->id,
                    'variant_id' => $variant->id,
                ],
                [
                    'quantity' => in_array($code, $outOfStock, true) ? 0 : 4,
                    'average_cost' => (float) $foundation->cost_price,
                ],
            );
        }
    }

    /**
     * Batches, dated so that all three states a shelf can be in are on screen at
     * once: gone off, going off, and fine.
     *
     * @param  array<string, Product>  $products
     */
    private function batches(array $products): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        // Nothing to date without somewhere to hold it. A demo workspace always
        // has a warehouse; this is a guard, not a branch worth designing around.
        if (! $warehouse) {
            return;
        }

        $rows = [
            // Expired last month, and the reason the demo till refuses a sale.
            ['product' => 'serum', 'number' => 'VTC-2409', 'expiry' => now()->subDays(34), 'quantity' => 6, 'cost' => 390],
            ['product' => 'mascara', 'number' => 'MSC-2411', 'expiry' => now()->subDays(9), 'quantity' => 3, 'cost' => 210],
            // Three weeks out: legal to sell, and the one a shop marks down
            // rather than bins.
            ['product' => 'sunscreen', 'number' => 'SPF-2503', 'expiry' => now()->addDays(21), 'quantity' => 14, 'cost' => 240],
            ['product' => 'micellar', 'number' => 'MIC-2506', 'expiry' => now()->addDays(75), 'quantity' => 22, 'cost' => 150],
            // Comfortably in date, so the board is not a wall of red.
            ['product' => 'foundation', 'number' => 'FDN-2601', 'expiry' => now()->addMonths(14), 'quantity' => 30, 'cost' => 340],
            ['product' => 'lipstick', 'number' => 'LIP-2602', 'expiry' => now()->addMonths(20), 'quantity' => 48, 'cost' => 165],
        ];

        foreach ($rows as $row) {
            $product = $products[$row['product']];

            Batch::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'warehouse_id' => $warehouse->id,
                    'batch_number' => $row['number'],
                ],
                [
                    'expiry_date' => $row['expiry']->toDateString(),
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['cost'],
                ],
            );
        }
    }

    /**
     * The testers on the counter — the register nothing else in the ERP can hold.
     *
     * @param  array<string, Product>  $products
     */
    private function testers(array $products, int $userId): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $rows = [
            /**
             * Nine months open on a six-month PAO. The row this whole register
             * exists for: the carton's printed date is still a year away, nothing
             * about the tube looks any different, and it should have gone in the
             * bin in the spring.
             */
            ['product' => 'mascara', 'purpose' => 'tester', 'opened' => now()->subMonths(9), 'pao' => 6],
            // Due in a fortnight — still fine, and the one to order a replacement
            // for before it is not.
            ['product' => 'kajal', 'purpose' => 'tester', 'opened' => now()->subMonths(12)->addDays(14), 'pao' => 12],
            ['product' => 'foundation', 'purpose' => 'tester', 'opened' => now()->subMonths(2), 'pao' => 12],
            ['product' => 'lipstick', 'purpose' => 'tester', 'opened' => now()->subMonths(1), 'pao' => 18],
            // Not a tester: a staff sample, which leaves stock the same way and
            // is a different number at the end of the quarter.
            ['product' => 'sunscreen', 'purpose' => 'staff', 'opened' => now()->subDays(20), 'pao' => 12],
        ];

        foreach ($rows as $row) {
            $product = $products[$row['product']];
            $openedOn = $row['opened']->toDateString();

            OpenUnit::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'purpose' => $row['purpose'],
                    'opened_on' => $openedOn,
                ],
                [
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                    'pao_months' => $row['pao'],
                    'expires_on' => OpenUnit::expiryFrom($openedOn, $row['pao']),
                    'status' => 'open',
                    'opened_by' => $userId,
                ],
            );
        }
    }
}
