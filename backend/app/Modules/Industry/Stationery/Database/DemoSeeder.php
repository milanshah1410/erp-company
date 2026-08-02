<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Database;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use App\Modules\Industry\Stationery\Models\ClassKit;
use App\Modules\Industry\Stationery\Models\Institution;
use App\Modules\Industry\Stationery\Services\ClassKitSupplyService;
use Throwable;

/**
 * Demo fixtures for the Stationery vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A stationery demo that opens on an empty institution list demonstrates nothing
 * about being a stationery shop — it looks like a shop that sells pens, which any
 * of the other twenty verticals could do. So this seeds the middle of a season:
 * three schools that are unalike in the ways that cost money, six class lists in
 * three different states, quantity slabs so the bulk rate is visibly not the
 * shelf rate, and one school order already raised with its purchase-order number
 * still missing.
 *
 * The awkward cases are the point:
 *
 *  - A school that will not pay without a purchase-order number, with an order
 *    already out that does not quote one. That row is the shop's September
 *    problem, visible in June.
 *  - A published list with a line that is short on the shelf, so the estimate
 *    shows a shortfall rather than a tidy total.
 *  - A draft list, so it is obvious that the counter cannot sell from it.
 *  - A generic list with no school behind it, because the walk-in whose school
 *    publishes nothing is a real customer.
 *
 * Keyed on modules rather than on the trade: the vertical has one trade today,
 * and the fixtures have to hold whether or not `logistics` and `loyalty` came
 * with it.
 *
 * Idempotent: institutions match on their code, lists on their kit number, and
 * the school order is only raised if that list has not already produced one.
 */
class DemoSeeder implements IndustryDemoFixtures
{
    /** The session everything below belongs to. */
    private const YEAR = '2026-27';

    /** @param  list<string>  $modules */
    /**
     * What this vertical contributes to the operator's Demo Data picker, one
     * chip per module it seeds, filed under the menu screen the chip fills.
     *
     * @return array<string, array{label: string, route: string, modules?: list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'stationery' => ['label' => 'Institutions, Booklists & School Order', 'route' => '/stationery/institutions'],
        ];
    }

    public function seed(array $modules, int $userId): void
    {
        if (! in_array('stationery', $modules, true)) {
            return;
        }

        $this->catalogue();
        $this->bulkSlabs();

        $customers = $this->customers();
        $institutions = $this->institutions($customers, $userId);
        $kits = $this->kits($institutions, $userId);

        $this->schoolOrder($kits, $userId);
    }

    /**
     * What a stationery shop actually stocks, at prices that make the slab table
     * below mean something.
     */
    private function catalogue(): void
    {
        $rows = [
            'STN-NB-172' => ['name' => 'Long Notebook — Ruled, 172 pages', 'cost_price' => 38, 'selling_price' => 55, 'tax_percent' => 12],
            'STN-NB-100' => ['name' => 'Small Notebook — Ruled, 100 pages', 'cost_price' => 20, 'selling_price' => 30, 'tax_percent' => 12],
            'STN-NB-GRA' => ['name' => 'Graph Book — 100 pages', 'cost_price' => 26, 'selling_price' => 40, 'tax_percent' => 12],
            'STN-DRW-BK' => ['name' => 'Drawing Book — A3, 30 sheets', 'cost_price' => 45, 'selling_price' => 70, 'tax_percent' => 12],
            'STN-GEO-BOX' => ['name' => 'Geometry Box — Camlin', 'cost_price' => 155, 'selling_price' => 210, 'tax_percent' => 18],
            'STN-PEN-BL' => ['name' => 'Ball Pen — Blue (pack of 5)', 'cost_price' => 32, 'selling_price' => 50, 'tax_percent' => 18],
            'STN-PCL-HB' => ['name' => 'Pencil HB (pack of 10)', 'cost_price' => 40, 'selling_price' => 60, 'tax_percent' => 12],
            'STN-CRY-24' => ['name' => 'Wax Crayons — 24 shades', 'cost_price' => 68, 'selling_price' => 95, 'tax_percent' => 12],
            'STN-SCH-BAG' => ['name' => 'School Bag — 18 inch', 'cost_price' => 520, 'selling_price' => 790, 'tax_percent' => 18],
            'STN-BK-M5' => ['name' => 'Mathematics Textbook — Class 5', 'cost_price' => 190, 'selling_price' => 245, 'tax_percent' => 0],
            'STN-BK-E5' => ['name' => 'English Reader — Class 5', 'cost_price' => 165, 'selling_price' => 215, 'tax_percent' => 0],
            'STN-BK-S5' => ['name' => 'Science Textbook — Class 5', 'cost_price' => 180, 'selling_price' => 235, 'tax_percent' => 0],
            'STN-BK-M8' => ['name' => 'Mathematics Textbook — Class 8', 'cost_price' => 240, 'selling_price' => 310, 'tax_percent' => 0],
            'STN-BK-S8' => ['name' => 'Science Textbook — Class 8', 'cost_price' => 255, 'selling_price' => 330, 'tax_percent' => 0],
            'STN-DIARY' => ['name' => 'School Diary — printed, hard cover', 'cost_price' => 78, 'selling_price' => 120, 'tax_percent' => 12],
            'STN-A4-REAM' => ['name' => 'A4 Copier Paper — 75 GSM ream', 'cost_price' => 245, 'selling_price' => 320, 'tax_percent' => 12],
        ];

        foreach ($rows as $sku => $attrs) {
            Product::firstOrCreate(['sku' => $sku], $attrs + ['is_active' => true]);
        }
    }

    /**
     * Quantity slabs, so "Bulk Sales" is visibly a thing the shop does rather
     * than a claim on a brochure.
     *
     * These are core `price_lists` rows on the wholesale tier — the same table
     * `Customer::priceFor()` reads at sale time. Seeding them here rather than
     * inventing a stationery slab table is the point: a school ordering four
     * hundred diaries gets 82 rupees through exactly the code path the counter
     * uses to charge a parent 120.
     */
    private function bulkSlabs(): void
    {
        $slabs = [
            'STN-DIARY' => [[50, 98], [200, 88], [500, 82]],
            'STN-A4-REAM' => [[10, 300], [50, 288], [100, 275]],
            'STN-NB-172' => [[100, 48], [500, 44]],
            'STN-PEN-BL' => [[50, 44], [250, 40]],
        ];

        foreach ($slabs as $sku => $rows) {
            $productId = Product::where('sku', $sku)->value('id');

            if (! $productId) {
                continue;
            }

            foreach ($rows as [$minQuantity, $price]) {
                PriceList::firstOrCreate(
                    [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'level' => 'wholesale',
                        'min_quantity' => $minQuantity,
                    ],
                    ['price' => $price],
                );
            }
        }
    }

    /**
     * The ledger parties behind the schools — trusts, not schools, which is the
     * whole reason the institution is a separate record.
     *
     * On the wholesale tier so the slabs above actually apply to them: an
     * institution buying at retail rates would make the bulk pricing invisible in
     * the one demo where it matters.
     *
     * @return array<string, Customer>
     */
    private function customers(): array
    {
        $rows = [
            'sunrise' => ['code' => 'STN-C01', 'name' => 'Shree Sunrise Kelavani Mandal', 'phone' => '9824033001'],
            'stannes' => ['code' => 'STN-C02', 'name' => 'St. Anne\'s Education Society', 'phone' => '9824033002'],
            'corporate' => ['code' => 'STN-C03', 'name' => 'Meridian Softech LLP', 'phone' => '9824033003'],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = Customer::firstOrCreate(
                ['code' => $attrs['code']],
                $attrs + [
                    'type' => 'wholesale',
                    'default_price_level' => 'wholesale',
                    'city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India',
                    'credit_limit' => 250000,
                    'is_active' => true,
                ],
            );
        }

        return $out;
    }

    /**
     * Three institutions that are unalike in the ways that cost money: a big CBSE
     * school the shop is the appointed vendor for, a convent that publishes a list
     * but buys nothing itself, and an office that is not a school at all.
     *
     * @param  array<string, Customer>  $customers
     * @return array<string, Institution>
     */
    private function institutions(array $customers, int $userId): array
    {
        $rows = [
            // The account the season is made or lost on. Demands a purchase-order
            // number, which is the flag the order below deliberately violates.
            'sunrise' => [
                'code' => 'SCH-DEMO-01',
                'name' => 'Sunrise English School',
                'customer' => 'sunrise',
                'type' => 'school',
                'board' => 'CBSE',
                'medium' => 'English',
                'session_starts_on' => now()->startOfYear()->addMonths(3)->toDateString(),
                'student_count' => 840,
                'contact_person' => 'Mrs. Bhavna Trivedi',
                'contact_phone' => '9824033011',
                'contact_email' => 'office@sunrise.demo',
                'address' => 'Bopal Cross Road, Ahmedabad 380058',
                'is_authorised_vendor' => true,
                'parent_discount_percent' => 8,
                'school_discount_percent' => 18,
                'credit_days' => 45,
                'requires_po' => true,
                'notes' => 'Appointed supplier for 2026-27. Booklists released class-wise from mid-March.',
            ],
            /**
             * Publishes a list and never buys anything: every rupee arrives from
             * parents at the counter. It has no billing customer at all, which is
             * a state the register has to hold — and the reason a school is not a
             * customer.
             */
            'stannes' => [
                'code' => 'SCH-DEMO-02',
                'name' => "St. Anne's Convent School",
                'type' => 'school',
                'board' => 'ICSE',
                'medium' => 'English',
                'student_count' => 620,
                'contact_person' => 'Sr. Maria',
                'contact_phone' => '9824033012',
                'is_authorised_vendor' => false,
                'parent_discount_percent' => 5,
                'school_discount_percent' => 0,
                'requires_po' => false,
                'notes' => 'Not an appointed vendor — parents buy at the counter with the school list.',
            ],
            // Not a school. An office that buys reams and pens by the box, which
            // is the other half of this trade's year.
            'meridian' => [
                'code' => 'SCH-DEMO-03',
                'name' => 'Meridian Softech LLP',
                'customer' => 'corporate',
                'type' => 'office',
                'student_count' => 0,
                'contact_person' => 'Admin Desk',
                'contact_phone' => '9824033013',
                'parent_discount_percent' => 0,
                'school_discount_percent' => 10,
                'credit_days' => 30,
                'requires_po' => true,
                'notes' => 'Monthly office stationery indent. Buys A4 reams and pens by the box.',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $customerKey = $attrs['customer'] ?? null;
            unset($attrs['customer']);

            $institution = Institution::firstOrNew(['code' => $attrs['code']]);

            $institution->fill($attrs + [
                'academic_year' => self::YEAR,
                'customer_id' => $customerKey ? $customers[$customerKey]->id : null,
                'is_active' => true,
                'created_by' => $userId,
            ]);

            $institution->save();

            $out[$key] = $institution;
        }

        return $out;
    }

    /**
     * Six lists in three states, because the states are what a demo has to show.
     *
     * Published lists the counter can sell from; a draft it cannot, so the reason
     * the status exists is visible; and a generic list with no school behind it,
     * for the walk-in whose school publishes nothing.
     *
     * @param  array<string, Institution>  $institutions
     * @return array<string, ClassKit>
     */
    private function kits(array $institutions, int $userId): array
    {
        $rows = [
            // The flagship list: a full Class 5 set, mandatory, published.
            'sunrise5' => [
                'kit_number' => 'KIT-DEMO-001',
                'institution' => 'sunrise',
                'class_label' => 'Class 5',
                'title' => 'Class 5 — complete set (books + stationery)',
                'status' => 'published',
                'discount_percent' => 2,
                'is_mandatory_set' => true,
                'expected_students' => 96,
                'items' => [
                    ['sku' => 'STN-BK-M5', 'qty' => 1, 'remark' => 'Mathematics — NCERT Class V'],
                    ['sku' => 'STN-BK-E5', 'qty' => 1, 'remark' => 'English Reader — Marigold V'],
                    ['sku' => 'STN-BK-S5', 'qty' => 1, 'remark' => 'Science — NCERT Class V'],
                    ['sku' => 'STN-NB-172', 'qty' => 6, 'remark' => 'Ruled long book, 172 pages'],
                    ['sku' => 'STN-NB-100', 'qty' => 4, 'remark' => 'Small ruled notebook'],
                    ['sku' => 'STN-DRW-BK', 'qty' => 1, 'remark' => 'Drawing book A3'],
                    ['sku' => 'STN-PCL-HB', 'qty' => 1],
                    ['sku' => 'STN-PEN-BL', 'qty' => 1],
                    ['sku' => 'STN-CRY-24', 'qty' => 1, 'remark' => '24-shade wax crayons'],
                    ['sku' => 'STN-DIARY', 'qty' => 1, 'remark' => 'School diary (compulsory)'],
                    // Optional, and on a mandatory set that makes it compulsory
                    // anyway — the case the requiredItems() rule exists for.
                    ['sku' => 'STN-SCH-BAG', 'qty' => 1, 'optional' => true, 'remark' => 'School bag — optional, school crest'],
                ],
            ],
            // Class 8, with a graph book that will be short on the shelf.
            'sunrise8' => [
                'kit_number' => 'KIT-DEMO-002',
                'institution' => 'sunrise',
                'class_label' => 'Class 8',
                'status' => 'published',
                'expected_students' => 88,
                'items' => [
                    ['sku' => 'STN-BK-M8', 'qty' => 1, 'remark' => 'Mathematics — NCERT Class VIII'],
                    ['sku' => 'STN-BK-S8', 'qty' => 1, 'remark' => 'Science — NCERT Class VIII'],
                    ['sku' => 'STN-NB-172', 'qty' => 8],
                    ['sku' => 'STN-GEO-BOX', 'qty' => 1, 'remark' => 'Geometry box with compass'],
                    ['sku' => 'STN-NB-GRA', 'qty' => 2, 'remark' => 'Graph book — 1 cm squares'],
                    ['sku' => 'STN-PEN-BL', 'qty' => 2],
                    ['sku' => 'STN-DIARY', 'qty' => 1],
                ],
            ],
            // Half-typed. The counter must not be able to sell this, and on this
            // screen it visibly cannot.
            'sunrise11' => [
                'kit_number' => 'KIT-DEMO-003',
                'institution' => 'sunrise',
                'class_label' => 'Class 11',
                'stream' => 'Science',
                'status' => 'draft',
                'expected_students' => 60,
                'notes' => 'Waiting on the physics practical book list from the school.',
                'items' => [
                    ['sku' => 'STN-NB-172', 'qty' => 10],
                    ['sku' => 'STN-NB-GRA', 'qty' => 3],
                ],
            ],
            'stannes3' => [
                'kit_number' => 'KIT-DEMO-004',
                'institution' => 'stannes',
                'class_label' => 'Class 3',
                'status' => 'published',
                'expected_students' => 72,
                'items' => [
                    ['sku' => 'STN-NB-100', 'qty' => 6, 'remark' => 'Four-line ruled book'],
                    ['sku' => 'STN-DRW-BK', 'qty' => 1],
                    ['sku' => 'STN-CRY-24', 'qty' => 1],
                    ['sku' => 'STN-PCL-HB', 'qty' => 2],
                ],
            ],
            // No school behind it. A real, sellable list for the walk-in whose
            // school publishes nothing.
            'generic5' => [
                'kit_number' => 'KIT-DEMO-005',
                'institution' => null,
                'class_label' => 'Class 5',
                'title' => 'Class 5 — standard stationery set (no school list)',
                'status' => 'published',
                'items' => [
                    ['sku' => 'STN-NB-172', 'qty' => 5],
                    ['sku' => 'STN-NB-100', 'qty' => 3],
                    ['sku' => 'STN-PCL-HB', 'qty' => 1],
                    ['sku' => 'STN-PEN-BL', 'qty' => 1],
                    ['sku' => 'STN-DRW-BK', 'qty' => 1, 'optional' => true],
                ],
            ],
            // The office indent — the other half of the year, and where the bulk
            // slabs bite hardest.
            'office' => [
                'kit_number' => 'KIT-DEMO-006',
                'institution' => 'meridian',
                'class_label' => 'Monthly office indent',
                'title' => 'Meridian Softech — monthly stationery indent',
                'status' => 'published',
                'expected_students' => 1,
                'items' => [
                    ['sku' => 'STN-A4-REAM', 'qty' => 40, 'remark' => 'A4 75 GSM'],
                    ['sku' => 'STN-PEN-BL', 'qty' => 20],
                    ['sku' => 'STN-NB-100', 'qty' => 25, 'remark' => 'Meeting notebooks'],
                ],
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $institutionKey = $attrs['institution'] ?? null;
            $items = $attrs['items'] ?? [];
            unset($attrs['institution'], $attrs['items']);

            $kit = ClassKit::firstOrNew(['kit_number' => $attrs['kit_number']]);

            $kit->fill($attrs + [
                'academic_year' => self::YEAR,
                'institution_id' => $institutionKey ? $institutions[$institutionKey]->id : null,
                'published_at' => ($attrs['status'] ?? 'draft') === 'published' ? now()->subWeeks(3) : null,
                'created_by' => $userId,
            ]);

            $kit->save();

            // Lines are rewritten wholesale on a re-seed. Safe because a kit is a
            // master list: nothing has been issued against these rows, and orders
            // raised from them carry their own snapshotted copies.
            $kit->items()->delete();

            foreach (array_values($items) as $index => $item) {
                $productId = Product::where('sku', $item['sku'])->value('id');

                if (! $productId) {
                    continue;
                }

                $kit->items()->create([
                    'product_id' => $productId,
                    'quantity' => $item['qty'],
                    'is_optional' => (bool) ($item['optional'] ?? false),
                    'remark' => $item['remark'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $out[$key] = $kit;
        }

        return $out;
    }

    /**
     * One school order already out — and deliberately without the purchase-order
     * number Sunrise demands.
     *
     * That is the row worth seeing on a fresh demo. It is a real, correctly
     * priced, four-hundred-diary order that cannot be paid, and it is sitting on
     * the board flagged in June rather than discovered in September. Every other
     * system in this trade discovers it in September.
     *
     * @param  array<string, ClassKit>  $kits
     */
    private function schoolOrder(array $kits, int $userId): void
    {
        $kit = $kits['sunrise5'] ?? null;
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');

        if (! $kit || ! $warehouseId || $kit->salesOrders()->exists()) {
            return;
        }

        try {
            /**
             * Raised through the service rather than by hand, so the demo proves
             * the path a shop will actually use — slab pricing, the school's
             * concession, the set discount and the GST all come out of the same
             * code the counter runs.
             *
             * The PO guard is honoured by passing one here; the *missing* case is
             * created by clearing it afterwards, which is what happens in life —
             * the order goes out and the paperwork does not follow.
             */
            $order = app(ClassKitSupplyService::class)->order($kit, [
                'warehouse_id' => (int) $warehouseId,
                'sets' => 96,
                'po_number' => 'PENDING',
                'notes' => 'Class 5 complete sets for 2026-27 admissions — first phase.',
            ], $userId);

            $order->forceFill(['stn_po_number' => null])->save();
        } catch (Throwable) {
            /**
             * A re-seed can legitimately find the list already spent, and the
             * service is right to refuse it. Anything else worth knowing about
             * surfaces through DemoShopSeeder's per-seeder warning.
             */
        }
    }
}
