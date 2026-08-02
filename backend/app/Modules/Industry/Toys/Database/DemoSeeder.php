<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Database;

use App\Models\Brand;
use App\Models\Product;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Toys\Models\AgeGrade;
use App\Modules\Industry\Toys\Models\SafetyCategory;
use App\Modules\Industry\Toys\Models\SafetyCertificate;

/**
 * Demo fixtures for the Toys & Games vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A demo toy shop with tidy paperwork demonstrates nothing, because tidy
 * paperwork is not what this vertical is for. What it is for is the awkward
 * cases, so those are what get seeded:
 *
 *  - a BIS licence that lapsed five weeks ago, on the brand whose battery
 *    ride-on carries the one safety class configured to *refuse* a sale. The
 *    demo shop is therefore stocking something its own till will not sell, which
 *    is exactly the situation no shop ever discovers on its own;
 *  - another licence expiring inside three weeks, so the expiring-soon list has
 *    something in it that can still be acted on;
 *  - classes that only warn sitting beside the one that blocks, so the
 *    difference between "tell the customer" and "refuse" is visible on one
 *    screen;
 *  - a product-specific test report underneath a brand licence, which is the
 *    case the narrowest-scope-wins rule exists for.
 *
 * Keyed on the module rather than the trade: this vertical has one trade today,
 * and the fixtures hold whether or not the workspace also runs the gift desk.
 *
 * Idempotent throughout — masters are matched on their code and certificates on
 * their number, and the grading is re-applied on every run so that a demo
 * workspace seeded before this vertical existed picks it up.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('toys', $modules, true)) {
            return;
        }

        $grades = $this->ageGrades();
        $categories = $this->safetyCategories();
        $brands = $this->brands();

        $products = $this->catalogue($grades, $categories, $brands);

        $this->certificates($products, $brands, $userId);
    }

    /**
     * The scale a toy shop lays its shelves out on. Months throughout, because
     * the bands that matter at the bottom are measured in them.
     *
     * @return array<string, AgeGrade>
     */
    private function ageGrades(): array
    {
        $rows = [
            'infant' => ['code' => '0-18M', 'label' => '0–18 months', 'min_months' => 0, 'max_months' => 18, 'warning' => 'No small parts. Wipe clean before first use.', 'sort_order' => 10],
            'toddler' => ['code' => '18M-3Y', 'label' => '18 months – 3 years', 'min_months' => 18, 'max_months' => 36, 'warning' => 'Adult supervision recommended.', 'requires_supervision' => true, 'sort_order' => 20],
            'preschool' => ['code' => '3PLUS', 'label' => '3 years and over', 'min_months' => 36, 'max_months' => 72, 'warning' => 'Not suitable for children under 3 years — small parts.', 'sort_order' => 30],
            'junior' => ['code' => '6-9Y', 'label' => '6 – 9 years', 'min_months' => 72, 'max_months' => 108, 'sort_order' => 40],
            'senior' => ['code' => '9-14Y', 'label' => '9 – 14 years', 'min_months' => 108, 'max_months' => 168, 'sort_order' => 50],
            'teen' => ['code' => '14PLUS', 'label' => '14 years and over', 'min_months' => 168, 'max_months' => null, 'sort_order' => 60],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = AgeGrade::firstOrCreate(['code' => $attrs['code']], $attrs);
        }

        return $out;
    }

    /**
     * The safety classes, including the one with teeth.
     *
     * `TOY-ELEC` is configured to stop the till rather than warn it: a ride-on
     * with a battery in it is mandatory-certification goods, and a shop selling
     * one on a lapsed licence is committing an offence rather than making a
     * filing error. Everything else warns.
     *
     * @return array<string, SafetyCategory>
     */
    private function safetyCategories(): array
    {
        $rows = [
            'safe' => [
                'code' => 'TOY-SAFE', 'label' => 'No specific hazard', 'standard' => 'bis',
                'hazards' => [], 'min_age_months' => null, 'sort_order' => 10,
            ],
            'small_parts' => [
                'code' => 'TOY-SMALL', 'label' => 'Contains small parts', 'standard' => 'bis',
                'hazards' => ['small_parts'], 'min_age_months' => 36,
                'warning_text' => 'Choking hazard — small parts. Not for children under 3 years.',
                'sort_order' => 20,
            ],
            'battery' => [
                'code' => 'TOY-ELEC', 'label' => 'Battery operated', 'standard' => 'bis',
                'hazards' => ['button_cell', 'electrical'], 'min_age_months' => 36,
                'warning_text' => 'Contains a button cell. Keep batteries away from children — swallowing one is a medical emergency.',
                'blocks_sale_without_certificate' => true,
                'sort_order' => 30,
            ],
            'projectile' => [
                'code' => 'TOY-PROJ', 'label' => 'Fires a projectile', 'standard' => 'bis',
                'hazards' => ['projectile'], 'min_age_months' => 96,
                'warning_text' => 'Do not aim at eyes or face. Use only the darts supplied.',
                'sort_order' => 40,
            ],
            'chemical' => [
                'code' => 'TOY-CHEM', 'label' => 'Chemicals or paints', 'standard' => 'en71',
                'hazards' => ['chemical'], 'min_age_months' => 120,
                'warning_text' => 'Contains chemicals. Adult supervision required. Read the instructions before use.',
                'sort_order' => 50,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = SafetyCategory::firstOrCreate(['code' => $attrs['code']], $attrs);
        }

        return $out;
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $out = [];
        foreach (['funskool' => 'Funskool', 'hotwheels' => 'Hot Wheels', 'kidzone' => 'KidZone'] as $key => $name) {
            $out[$key] = Brand::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        return $out;
    }

    /**
     * The range, graded and classified — because an ungraded toy shop is exactly
     * the thing this vertical exists to stop being.
     *
     * @param  array<string, AgeGrade>  $grades
     * @param  array<string, SafetyCategory>  $categories
     * @param  array<string, Brand>  $brands
     * @return array<string, Product>
     */
    private function catalogue(array $grades, array $categories, array $brands): array
    {
        $rows = [
            'rattle' => ['sku' => 'TOY-INF-RATL', 'name' => 'Soft Rattle Set (3 pc)', 'cost_price' => 120, 'selling_price' => 249, 'grade' => 'infant', 'category' => 'safe', 'brand' => 'kidzone'],
            'blocks' => ['sku' => 'TOY-TOD-BLOK', 'name' => 'Wooden Building Blocks (50 pc)', 'cost_price' => 340, 'selling_price' => 699, 'grade' => 'toddler', 'category' => 'safe', 'brand' => 'kidzone'],
            'puzzle' => ['sku' => 'TOY-PRE-PUZL', 'name' => 'Jigsaw Puzzle — Jungle (100 pc)', 'cost_price' => 150, 'selling_price' => 329, 'grade' => 'preschool', 'category' => 'small_parts', 'brand' => 'funskool'],
            'car' => ['sku' => 'TOY-PRE-CAR9', 'name' => 'Die-cast Car — 9 pack', 'cost_price' => 420, 'selling_price' => 899, 'grade' => 'preschool', 'category' => 'small_parts', 'brand' => 'hotwheels'],
            'track' => ['sku' => 'TOY-PRE-TRAK', 'name' => 'Loop Track Set', 'cost_price' => 610, 'selling_price' => 1299, 'grade' => 'preschool', 'category' => 'small_parts', 'brand' => 'hotwheels'],
            'garage' => ['sku' => 'TOY-PRE-GRGE', 'name' => 'Two-storey Toy Garage', 'cost_price' => 780, 'selling_price' => 1599, 'grade' => 'preschool', 'category' => 'small_parts', 'brand' => 'hotwheels'],
            // Battery goods: the class on these refuses a sale without a live
            // certificate, which is what makes the lapsed licence below bite.
            'rideon' => ['sku' => 'TOY-JUN-RIDE', 'name' => 'Battery Ride-on Jeep 12V', 'cost_price' => 5400, 'selling_price' => 9499, 'grade' => 'junior', 'category' => 'battery', 'brand' => 'kidzone'],
            'robot' => ['sku' => 'TOY-JUN-ROBO', 'name' => 'Remote Control Robot', 'cost_price' => 890, 'selling_price' => 1899, 'grade' => 'junior', 'category' => 'battery', 'brand' => 'funskool'],
            'blaster' => ['sku' => 'TOY-SEN-BLST', 'name' => 'Dart Blaster with 12 darts', 'cost_price' => 720, 'selling_price' => 1499, 'grade' => 'senior', 'category' => 'projectile', 'brand' => 'funskool'],
            'chem' => ['sku' => 'TOY-SEN-CHEM', 'name' => 'Junior Chemistry Lab', 'cost_price' => 980, 'selling_price' => 1999, 'grade' => 'senior', 'category' => 'chemical', 'brand' => 'funskool'],
            'board' => ['sku' => 'TOY-TEN-BORD', 'name' => 'Strategy Board Game', 'cost_price' => 560, 'selling_price' => 1199, 'grade' => 'teen', 'category' => 'safe', 'brand' => 'funskool'],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $product = Product::firstOrCreate(
                ['sku' => $attrs['sku']],
                [
                    'name' => $attrs['name'],
                    'cost_price' => $attrs['cost_price'],
                    'selling_price' => $attrs['selling_price'],
                    'tax_percent' => 18,
                    'is_active' => true,
                ],
            );

            /**
             * Grading is applied on every run, not only at creation. A demo
             * workspace seeded before this vertical existed has the articles but
             * none of the labelling, and the whole trade is invisible without it.
             */
            $product->forceFill([
                'brand_id' => $brands[$attrs['brand']]->id,
                'toy_age_grade_id' => $grades[$attrs['grade']]->id,
                'toy_safety_category_id' => $categories[$attrs['category']]->id,
            ])->save();

            $out[$key] = $product;
        }

        return $out;
    }

    /**
     * The paperwork, in the states a shop is actually in.
     *
     * @param  array<string, Product>  $products
     * @param  array<string, Brand>  $brands
     */
    private function certificates(array $products, array $brands, int $userId): void
    {
        $rows = [
            // Comfortably live, covering a whole brand — the ordinary case.
            [
                'certificate_number' => 'BIS-DEMO-FUN-01',
                'standard' => 'bis',
                'brand_id' => $brands['funskool']->id,
                'issued_by' => 'Bureau of Indian Standards',
                'licence_no' => 'CM/L-7712345',
                'issued_on' => now()->subMonths(14)->toDateString(),
                'expires_on' => now()->addMonths(10)->toDateString(),
            ],
            // Expiring inside three weeks: the row the expiring-soon list exists
            // for, and the one a shop still has time to do something about.
            [
                'certificate_number' => 'BIS-DEMO-HW-01',
                'standard' => 'bis',
                'brand_id' => $brands['hotwheels']->id,
                'issued_by' => 'Bureau of Indian Standards',
                'licence_no' => 'CM/L-7798110',
                'issued_on' => now()->subMonths(23)->toDateString(),
                'expires_on' => now()->addDays(19)->toDateString(),
            ],
            /**
             * Lapsed five weeks ago, on the brand whose battery ride-on carries
             * the blocking class. The demo shop is selling something its own till
             * will refuse — the case this register was built to surface.
             */
            [
                'certificate_number' => 'BIS-DEMO-KZ-01',
                'standard' => 'bis',
                'brand_id' => $brands['kidzone']->id,
                'issued_by' => 'Bureau of Indian Standards',
                'licence_no' => 'CM/L-7654321',
                'issued_on' => now()->subMonths(25)->toDateString(),
                'expires_on' => now()->subDays(34)->toDateString(),
            ],
            // A specific test report for one article, narrower than the brand
            // licence above it — the case the "narrowest wins" rule is for.
            [
                'certificate_number' => 'EN71-DEMO-CHEM',
                'standard' => 'en71',
                'product_id' => $products['chem']->id,
                'issued_by' => 'SGS India Pvt Ltd',
                'issued_on' => now()->subMonths(6)->toDateString(),
                'expires_on' => now()->addMonths(18)->toDateString(),
            ],
        ];

        foreach ($rows as $attrs) {
            SafetyCertificate::firstOrCreate(
                ['certificate_number' => $attrs['certificate_number']],
                $attrs + ['status' => 'active', 'created_by' => $userId],
            );
        }
    }
}
