<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Database;

use App\Models\Category;
use App\Models\Department;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryTradeDemoSeeder;
use App\Modules\Industry\Healthcare\Models\HospitalIndent;
use App\Modules\Industry\Healthcare\Models\LabOrder;
use App\Modules\Industry\Healthcare\Models\LabTest;
use App\Modules\Industry\Healthcare\Services\HospitalStoreService;
use App\Modules\Industry\Healthcare\Services\LabOrderService;
use App\Services\StockService;
use Throwable;

/**
 * Demo fixtures for the Healthcare trades.
 *
 * Lives inside the plugin, next to its migrations and routes, for the same
 * reason those do — the vertical should be one folder you can drop in.
 * DemoShopSeeder calls this for any Healthcare trade and knows nothing about
 * reference ranges or ward indents.
 *
 * A lab demo with an empty worklist demonstrates nothing about being a lab, so
 * the orders below are deliberately spread across the workflow: one awaiting
 * collection, one collected with nothing entered, one reported with an abnormal
 * value, one reported and billed. That is what the status filter is *for*, and
 * a single order in a single state hides it.
 *
 * Walked through the real services rather than inserted, so the fixtures
 * exercise the same pricing, flagging and stock posting a user would — a demo
 * that looks right is then evidence the workflow is right.
 *
 * Self-sufficient: it creates the handful of products, wards and stock its
 * fixtures need rather than depending on DEMO_SHOP_DATA having run. Everything
 * is keyed on an `HC-` prefix so a re-seed refreshes in place.
 *
 * Must be called inside a TenantContext.
 */
class HealthcareDemoSeeder implements IndustryTradeDemoSeeder
{
    /** SKU/code prefix for everything this seeder owns, so a re-run is idempotent. */
    private const PREFIX = 'HC';

    /**
     * The test master. Ordinary adult ranges — a demo report should read like a
     * real one — plus one deliberately qualitative test, which is the case that
     * proves a result with no numeric range is never flagged red.
     *
     * @var list<array<string, mixed>>
     */
    private const TESTS = [
        [
            'code' => 'HB', 'name' => 'Haemoglobin', 'category' => 'Haematology',
            'sample_type' => 'blood', 'container' => 'EDTA (lavender top)', 'unit' => 'g/dL',
            'reference_range' => '13.0 – 17.0', 'ref_low' => 13, 'ref_high' => 17,
            'price' => 150, 'tat_hours' => 4,
        ],
        [
            'code' => 'CHOL', 'name' => 'Total Cholesterol', 'category' => 'Biochemistry',
            'sample_type' => 'serum', 'container' => 'Plain (red top)', 'unit' => 'mg/dL',
            'reference_range' => '< 200', 'ref_high' => 200,
            'price' => 250, 'tat_hours' => 8,
        ],
        [
            'code' => 'TRIG', 'name' => 'Triglycerides', 'category' => 'Biochemistry',
            'sample_type' => 'serum', 'container' => 'Plain (red top)', 'unit' => 'mg/dL',
            'reference_range' => '< 150', 'ref_high' => 150,
            'price' => 250, 'tat_hours' => 8,
        ],
        [
            'code' => 'CREA', 'name' => 'Serum Creatinine', 'category' => 'Biochemistry',
            'sample_type' => 'serum', 'container' => 'Plain (red top)', 'unit' => 'mg/dL',
            'reference_range' => '0.7 – 1.3', 'ref_low' => 0.7, 'ref_high' => 1.3,
            'price' => 200, 'tat_hours' => 6,
        ],
        [
            'code' => 'DENG', 'name' => 'Dengue NS1 Antigen', 'category' => 'Serology',
            'sample_type' => 'serum', 'method' => 'Rapid card', 'reference_range' => 'Non-reactive',
            'price' => 700, 'tat_hours' => 6,
        ],
    ];

    /** Consumables a ward actually draws, as sku suffix => [name, cost]. */
    private const CONSUMABLES = [
        'GLOVE' => ['Examination Gloves (box of 100)', 320.0],
        'SYRINGE' => ['Disposable Syringe 5ml', 6.5],
        'IVSET' => ['IV Infusion Set', 24.0],
    ];

    public function __construct(private readonly ?int $userId = null) {}

    /**
     * What this vertical contributes to the operator's Demo Data picker, one
     * chip per trade, filed under the menu screen the chip fills.
     *
     * @return array<string, array{label: string, route: string, modules?: list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'diagnostic_lab' => ['label' => 'Pathology Worklist (test master → report)', 'route' => '/lab-orders', 'modules' => ['diagnostics']],
            'clinic' => ['label' => 'Clinic Test Orders (referred out)', 'route' => '/lab-tests', 'modules' => ['diagnostics']],
            'hospital' => ['label' => 'Ward Store Indents & Consumption', 'route' => '/hospital-indents', 'modules' => ['hospital']],
        ];
    }

    /**
     * Seed the fixtures one trade needs.
     *
     * Unknown trades are a no-op rather than an error: DemoShopSeeder passes
     * every profile key it has, and a non-healthcare trade simply has nothing
     * here.
     */
    public function seed(string $trade): void
    {
        match ($trade) {
            // A standalone pathology lab: the test master and a full worklist.
            'diagnostic_lab' => $this->lab(),
            // A nursing home runs both — its own lab and a central store.
            'hospital' => $this->hospital(),
            // A clinic refers tests out but writes the orders, so it gets the
            // master and the front half of the workflow, not a ward store.
            'clinic' => $this->clinic(),
            // medical / pharma_distributor / surgical are batch, expiry,
            // prescriptions and serials — all core, already demonstrated by the
            // baseline masters and the Pharmacy screens.
            default => null,
        };
    }

    // ---- Trades ------------------------------------------------------------

    private function lab(): void
    {
        $tests = $this->testMaster();
        $this->worklist($tests, billLast: true);
    }

    private function clinic(): void
    {
        $tests = $this->testMaster();
        // No verified-and-billed tail: a clinic's own book usually ends at
        // "sent to the lab", and pretending otherwise would show a consulting
        // room issuing pathology reports.
        $this->worklist($tests, billLast: false);
    }

    private function hospital(): void
    {
        $tests = $this->testMaster();
        $this->worklist($tests, billLast: true);
        $this->wardStore();
    }

    // ---- Diagnostic lab ------------------------------------------------------

    /**
     * The test master plus the Lipid Profile panel built from two of its rows.
     *
     * @return array<string, LabTest> code => test
     */
    private function testMaster(): array
    {
        $tests = [];

        foreach (self::TESTS as $definition) {
            $tests[$definition['code']] = LabTest::updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['is_active' => true],
            );
        }

        $panel = LabTest::updateOrCreate(
            ['code' => 'LIPID'],
            [
                'name' => 'Lipid Profile',
                'category' => 'Biochemistry',
                'sample_type' => 'serum',
                'container' => 'Plain (red top)',
                // Priced below the sum of its parts, which is the whole reason a
                // panel exists commercially.
                'price' => 450,
                'tat_hours' => 12,
                'is_panel' => true,
                'is_active' => true,
            ],
        );

        $panel->components()->sync([
            $tests['CHOL']->id => ['sort_order' => 0],
            $tests['TRIG']->id => ['sort_order' => 1],
        ]);

        $tests['LIPID'] = $panel;

        return $tests;
    }

    /**
     * Four orders, one in each state the worklist filters by.
     *
     * @param  array<string, LabTest>  $tests
     */
    private function worklist(array $tests, bool $billLast): void
    {
        // Re-running refreshes the master above but must not stack a second set
        // of orders on top of the first.
        if (LabOrder::query()->where('patient_name', 'like', self::PREFIX.' %')->exists()) {
            return;
        }

        $lab = app(LabOrderService::class);

        // 1. Registered — the collection queue, and the only urgent one so the
        //    worklist's priority sort has something to prove.
        $this->order($lab, 'Ramesh Patel', 44, 'male', [$tests['HB']->id], priority: 'urgent');

        // 2. Collected, nothing entered — the bench's to-do.
        $awaiting = $this->order($lab, 'Sunita Shah', 31, 'female', [$tests['LIPID']->id]);
        $lab->collect($awaiting);

        // 3. Reported, with one value out of range so the abnormal marking on
        //    the report is visible rather than theoretical.
        $reported = $this->order(
            $lab, 'Imran Qureshi', 58, 'male',
            [$tests['LIPID']->id, $tests['CREA']->id],
        );
        $lab->collect($reported);
        $lab->saveResults($reported, $reported->results()->get()->map(fn ($r) => [
            'id' => $r->id,
            'result_value' => match ($r->parameter) {
                'Total Cholesterol' => '243',   // high
                'Triglycerides' => '138',
                default => '1.1',
            },
        ])->all());
        $this->try(fn () => $lab->verify($reported->fresh(), $this->userId));

        // 4. Reported and billed — so the worklist's "unbilled value" tile means
        //    something rather than covering the whole list.
        $billed = $this->order($lab, 'Anita Verma', 27, 'female', [$tests['DENG']->id]);
        $lab->collect($billed);
        $row = $billed->results()->first();
        $lab->saveResults($billed, [['id' => $row->id, 'result_value' => 'Non-reactive']]);
        $this->try(fn () => $lab->verify($billed->fresh(), $this->userId));

        if ($billLast) {
            // Needs a warehouse and the walk-in account; both exist in a demo
            // workspace, but a fixture must not take the seeder down if one was
            // trimmed by hand.
            $this->try(fn () => $lab->bill($billed->fresh(), $this->userId));
        }
    }

    /** @param  list<int>  $testIds */
    private function order(
        LabOrderService $lab,
        string $patient,
        int $age,
        string $gender,
        array $testIds,
        string $priority = 'routine',
    ): LabOrder {
        $order = LabOrder::create([
            'order_number' => LabOrder::nextNumber(),
            // Prefixed so the idempotency check above can find them again.
            'patient_name' => self::PREFIX.' '.$patient,
            'patient_age' => $age,
            'patient_gender' => $gender,
            'patient_phone' => '98'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'referring_doctor' => 'Dr. A. Mehta',
            'doctor_registration_no' => 'GMC/2011/'.random_int(1000, 9999),
            'order_date' => now()->toDateString(),
            'priority' => $priority,
            'status' => 'registered',
            'created_by' => $this->userId,
        ]);

        $lab->syncTests($order, array_map(static fn (int $id) => ['lab_test_id' => $id], $testIds));

        return $order->fresh();
    }

    // ---- Hospital store --------------------------------------------------------

    /**
     * Two wards, stock on the shelf, and one part-issued indent — so the
     * consumption report opens with a figure on it instead of "nothing was
     * issued in this period".
     */
    private function wardStore(): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        $icu = Department::updateOrCreate(
            ['code' => 'ICU'],
            ['name' => 'Intensive Care Unit', 'is_active' => true],
        );
        Department::updateOrCreate(
            ['code' => 'OT'],
            ['name' => 'Operation Theatre', 'is_active' => true],
        );

        $products = $this->consumables($warehouse->id);

        if (HospitalIndent::query()->where('department_id', $icu->id)->exists()) {
            return;
        }

        $indent = HospitalIndent::create([
            'indent_number' => HospitalIndent::nextNumber(),
            'department_id' => $icu->id,
            'warehouse_id' => $warehouse->id,
            'indent_date' => now()->subDays(2)->toDateString(),
            'priority' => 'urgent',
            'status' => 'approved',
            'requested_by' => $this->userId,
            'approved_by' => $this->userId,
            'approved_at' => now()->subDays(2),
            'notes' => 'Weekly consumables top-up.',
            'created_by' => $this->userId,
        ]);

        $lines = [];
        foreach ($products as $product) {
            $item = $indent->items()->create([
                'product_id' => $product->id,
                // Asked 40, allowed 25: the gap between the two is the number a
                // store is judged on, and a demo where they match hides it.
                'requested_quantity' => 40,
                'approved_quantity' => 25,
            ]);
            // Part-issued on purpose, so the board shows the resting state that
            // only exists because partial issues are real.
            $lines[] = ['id' => $item->id, 'quantity' => 15];
        }

        $this->try(fn () => app(HospitalStoreService::class)->issue(
            $indent->fresh('items'),
            $lines,
            $this->userId,
        ));
    }

    /**
     * The consumables the ward draws, with opening stock behind them.
     *
     * Created here rather than borrowed from the generic demo products because
     * a hospital store issuing "Cotton Shirt 1" reads as a bug, and because the
     * store has to actually hold something before it can issue it.
     *
     * @return list<Product>
     */
    private function consumables(int $warehouseId): array
    {
        $category = Category::firstOrCreate(
            ['name' => 'Medical Consumables'],
            ['is_active' => true],
        );
        $unit = Unit::firstOrCreate(['name' => 'Piece'], ['short_name' => 'pc', 'is_active' => true]);

        $stock = app(StockService::class);
        $out = [];

        foreach (self::CONSUMABLES as $suffix => [$name, $cost]) {
            $product = Product::updateOrCreate(
                ['sku' => self::PREFIX.'-'.$suffix],
                [
                    'name' => $name,
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'cost_price' => $cost,
                    'selling_price' => round($cost * 1.2, 2),
                    'tax_percent' => 12,
                    'is_active' => true,
                    'is_service' => false,
                ],
            );

            // Only on first creation: a re-seed must not keep piling opening
            // stock onto a workspace that already has it.
            if ($product->wasRecentlyCreated) {
                $this->try(fn () => $stock->inward(
                    warehouseId: $warehouseId,
                    productId: $product->id,
                    variantId: null,
                    quantity: 200,
                    unitCost: $cost,
                    type: 'opening',
                    userId: $this->userId,
                ));
            }

            $out[] = $product;
        }

        return $out;
    }

    /**
     * Run a fixture step, swallowing a failure.
     *
     * A demo fixture is never worth failing a nineteen-workspace seed over —
     * DemoShopSeeder already catches at the trade level, and this keeps one bad
     * step from costing the rest of the same trade's data.
     */
    private function try(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // Intentionally ignored; see the note above.
        }
    }
}
