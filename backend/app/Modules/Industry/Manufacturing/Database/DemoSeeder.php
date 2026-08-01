<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Database;

use App\Models\Category;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Manufacturing\Models\Machine;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use App\Modules\Industry\Manufacturing\Services\ShopFloorService;
use App\Modules\Industry\Support\IndustryRegistry;

/**
 * Demo fixtures for the Manufacturing trades.
 *
 * Lives inside the plugin, next to its migrations and routes, for the same
 * reason those do — the vertical should be one folder you can drop in.
 * DemoShopSeeder discovers it by convention and knows nothing about machine-hour
 * rates or defect codes.
 *
 * The fixtures are deliberately spread across states, because every screen in
 * this module is a filter over one:
 *
 *  - machines running, idle, broken down *now* and overdue for service, so the
 *    status tiles are not four copies of the same number and the utilisation
 *    report has downtime to divide by;
 *  - inspections that pass, fail and pass under deviation, so the rejection rate
 *    is a real figure and the defect Pareto has more than one bar;
 *  - scrap across several reasons, some with a recovery value, so net loss is
 *    visibly not the same number as gross.
 *
 * A factory demo where nothing has ever broken, failed or been thrown away
 * demonstrates nothing about running a factory.
 *
 * Self-sufficient: it creates the products, warehouse and production run its
 * fixtures need rather than depending on DEMO_SHOP_DATA having run. Everything
 * is keyed on an `MF-` prefix so a re-seed refreshes in place.
 *
 * Must be called inside a TenantContext.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** Code/SKU prefix for everything this seeder owns, so a re-run is idempotent. */
    private const PREFIX = 'MF';

    /**
     * The plant, one row per state worth showing.
     *
     * `down` means the machine is stopped right now — an open downtime row with
     * no end time, which is what marks it unavailable to a planner.
     *
     * @var list<array<string, mixed>>
     */
    private const MACHINES = [
        [
            'code' => 'CUT-01', 'name' => 'Cutting Table 1', 'type' => 'Cutting',
            'maker' => 'Bullmer', 'capacity' => 240, 'uom' => 'pcs', 'rate' => 320,
            'kw' => 7.5, 'status' => 'running', 'down' => false, 'service_overdue' => false,
        ],
        [
            'code' => 'PRESS-2', 'name' => 'Offset Press 2', 'type' => 'Printing Press',
            'maker' => 'Heidelberg', 'capacity' => 4000, 'uom' => 'sheets', 'rate' => 1450,
            'kw' => 45, 'status' => 'running', 'down' => false, 'service_overdue' => true,
        ],
        [
            'code' => 'MOULD-1', 'name' => 'Injection Moulding 120T', 'type' => 'Injection Moulding',
            'maker' => 'Haitian', 'capacity' => 180, 'uom' => 'pcs', 'rate' => 890,
            'kw' => 30, 'status' => 'breakdown', 'down' => true, 'service_overdue' => false,
        ],
        [
            'code' => 'PACK-1', 'name' => 'Packing Line 1', 'type' => 'Packing',
            'maker' => 'Bosch', 'capacity' => 600, 'uom' => 'pcs', 'rate' => 210,
            'kw' => 5.5, 'status' => 'idle', 'down' => false, 'service_overdue' => false,
        ],
    ];

    /**
     * Closed stops, spread over the reason codes.
     *
     * The mix is the point: a breakdown, a material shortage and a changeover
     * are three different people's problems, and a downtime log that only ever
     * says "breakdown" makes the reason column look decorative.
     *
     * machine code => [days ago, hour, minutes lost, reason]
     *
     * @var list<array<int, mixed>>
     */
    private const STOPS = [
        ['PRESS-2', 12, 10, 95, 'changeover'],
        ['PRESS-2', 5, 14, 40, 'no_material'],
        ['CUT-01', 9, 11, 55, 'breakdown'],
        ['MOULD-1', 21, 9, 210, 'breakdown'],
        ['MOULD-1', 3, 16, 60, 'power_failure'],
        ['PACK-1', 7, 15, 35, 'no_operator'],
    ];

    /** Set for the duration of one seed() call; every write is attributed to it. */
    private int $userId = 0;

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        /**
         * Two gates, and both are needed.
         *
         * The module, because every screen these fixtures fill lives behind
         * `module:manufacturing` — seeding a workspace that has it switched off
         * leaves rows nobody has a menu to reach.
         *
         * And the vertical, because the module alone is not specific enough: a
         * bakery, a sweet mart and an ice-cream parlour all run Manufacturing
         * for their recipes, and handing one of them an offset printing run with
         * a make-ready waste note would be worse than an empty screen. Those
         * trades get the Food & Beverage plugin's fixtures instead.
         */
        if (! in_array('manufacturing', $modules, true)) {
            return;
        }

        $trade = (string) Setting::get('shop_profile', '');
        if ((IndustryRegistry::trades()[$trade]['industry'] ?? null) !== 'manufacturing') {
            return;
        }

        $warehouse = Warehouse::query()->orderBy('id')->first();
        if (! $warehouse) {
            return;
        }

        $this->userId = $userId;

        $machines = $this->machines($warehouse);
        $this->downtime($machines);

        [$finished, $material] = $this->products();
        $order = $this->productionRun($warehouse, $finished, $material, $machines['PRESS-2'] ?? null);

        $this->inspections($order, $finished);
        $this->scrap($warehouse, $order, $finished, $material);
    }

    // ---- Plant -------------------------------------------------------------

    /** @return array<string, Machine> keyed by the short code, without the prefix */
    private function machines(Warehouse $warehouse): array
    {
        $out = [];

        foreach (self::MACHINES as $spec) {
            $machine = Machine::updateOrCreate(
                ['code' => self::PREFIX.'-'.$spec['code']],
                [
                    'name' => $spec['name'],
                    'machine_type' => $spec['type'],
                    'warehouse_id' => $warehouse->id,
                    'manufacturer' => $spec['maker'],
                    'model_number' => 'M-'.$spec['code'],
                    'serial_number' => 'SN-'.$spec['code'],
                    'installed_on' => now()->subYears(3)->toDateString(),
                    'capacity_per_hour' => $spec['capacity'],
                    'capacity_uom' => $spec['uom'],
                    'hourly_cost' => $spec['rate'],
                    'power_kw' => $spec['kw'],
                    'status' => $spec['status'],
                    'last_service_on' => now()->subDays(120)->toDateString(),
                    // One machine is deliberately past due, so the "service
                    // overdue" tile is not a permanent zero.
                    'next_service_due' => $spec['service_overdue']
                        ? now()->subDays(9)->toDateString()
                        : now()->addDays(35)->toDateString(),
                    'is_active' => true,
                    'notes' => 'Demo machine',
                    'created_by' => $this->userId,
                ],
            );

            $out[$spec['code']] = $machine;
        }

        return $out;
    }

    /** @param  array<string, Machine>  $machines */
    private function downtime(array $machines): void
    {
        foreach ($machines as $machine) {
            // Rewritten wholesale so a re-seed does not stack duplicate stops on
            // top of the ones it wrote last time.
            $machine->downtimes()->delete();
        }

        foreach (self::STOPS as [$code, $daysAgo, $hour, $minutes, $reason]) {
            $machine = $machines[$code] ?? null;
            if (! $machine) {
                continue;
            }

            $started = now()->subDays($daysAgo)->setTime($hour, 0);
            $machine->downtimes()->create([
                'started_at' => $started,
                'ended_at' => $started->copy()->addMinutes($minutes),
                'minutes' => $minutes,
                'reason' => $reason,
                'remarks' => 'Demo stop',
                'reported_by' => $this->userId,
            ]);
        }

        // The open stop, for whichever machine is marked down. No end time is
        // what makes it "still down" — and what keeps it out of the utilisation
        // sum until somebody closes it.
        foreach (self::MACHINES as $spec) {
            if (! $spec['down'] || ! isset($machines[$spec['code']])) {
                continue;
            }

            $machines[$spec['code']]->downtimes()->create([
                'started_at' => now()->subHours(4),
                'reason' => 'breakdown',
                'remarks' => 'Feed screw jammed — awaiting spare',
                'reported_by' => $this->userId,
            ]);
        }
    }

    // ---- What is being made ------------------------------------------------

    /** @return array{0: Product, 1: Product} finished good, raw material */
    private function products(): array
    {
        $category = Category::firstOrCreate(['name' => 'Production'], ['is_active' => true]);
        $unit = Unit::firstOrCreate(['name' => 'Piece'], ['short_name' => 'pc', 'is_active' => true]);

        $finished = Product::updateOrCreate(
            ['sku' => self::PREFIX.'-FG-001'],
            [
                'name' => 'Printed Carton — 250gsm',
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'cost_price' => 42,
                'selling_price' => 68,
                'is_active' => true,
            ],
        );

        $material = Product::updateOrCreate(
            ['sku' => self::PREFIX.'-RM-001'],
            [
                'name' => 'Duplex Board 250gsm',
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'cost_price' => 26,
                'selling_price' => 0,
                'is_active' => true,
            ],
        );

        return [$finished, $material];
    }

    /**
     * One completed run, loaded on a machine and costed through every bucket.
     *
     * Costed rather than left at material-only because the finished-cost
     * breakdown is what a factory owner opens this module for, and a run showing
     * raw material and nothing else says the shop floor is free.
     */
    private function productionRun(Warehouse $warehouse, Product $finished, Product $material, ?Machine $machine): ManufacturingOrder
    {
        $quantity = 5000;
        $materialQty = 5200; // includes the trim a converter always loses
        $materialCost = round($materialQty * (float) $material->cost_price, 2);

        $order = ManufacturingOrder::updateOrCreate(
            ['mo_number' => self::PREFIX.'-MO-0001'],
            [
                'product_id' => $finished->id,
                'warehouse_id' => $warehouse->id,
                'machine_id' => $machine?->id,
                'quantity_to_produce' => $quantity,
                'quantity_produced' => $quantity,
                'status' => 'completed',
                'order_date' => now()->subDays(14)->toDateString(),
                'expected_completion_date' => now()->subDays(4)->toDateString(),
                'material_cost' => $materialCost,
                // 6.5 hours on the press at its own rate — the same arithmetic
                // the "charge machine time" action performs.
                'machine_cost' => round(6.5 * (float) ($machine?->hourly_cost ?? 0), 2),
                'labour_cost' => 4200,
                'printing_cost' => 3100,
                'packing_cost' => 1400,
                'transport_cost' => 900,
                'materials_consumed' => true,
                'finished_received' => true,
                'started_at' => now()->subDays(13),
                'completed_at' => now()->subDays(4),
                'notes' => 'Demo production run',
                'created_by' => $this->userId,
            ],
        );

        $order->materials()->delete();
        $order->materials()->create([
            'product_id' => $material->id,
            'quantity' => $materialQty,
            'unit_cost' => (float) $material->cost_price,
            'total_cost' => $materialCost,
            'consumed_quantity' => $materialQty,
            'remarks' => 'Demo material line',
        ]);

        // Through the model's own roll-up, so the demo's finished cost is
        // produced the same way a real one is.
        $order->recalcCosts();

        return $order->fresh();
    }

    // ---- Quality and loss --------------------------------------------------

    /**
     * Three inspections on one run: incoming board, in-process on the press, and
     * the final check that rejects.
     *
     * Defect quantities add up to exactly what was marked down — the rule the
     * inspection form enforces. Demo data a screen would reject is worse than
     * no demo data, because the first thing anyone does is open it and save it.
     */
    private function inspections(ManufacturingOrder $order, Product $finished): void
    {
        QualityInspection::where('manufacturing_order_id', $order->id)
            ->where('inspection_number', 'like', self::PREFIX.'-%')
            ->delete();

        $incoming = QualityInspection::create([
            'inspection_number' => self::PREFIX.'-QC-0001',
            'stage' => 'incoming',
            'inspected_on' => now()->subDays(15)->toDateString(),
            'manufacturing_order_id' => $order->id,
            'product_id' => $finished->id,
            'lot_quantity' => 5200,
            'sample_size' => 200,
            'accepted_quantity' => 5200,
            'result' => 'passed',
            'remarks' => 'Board received to specification.',
            'created_by' => $this->userId,
        ]);

        // Accepted under deviation: the shade is off but the customer signed a
        // waiver. Neither a pass nor a rejection — which is the whole reason
        // 'conditional' exists.
        $inProcess = QualityInspection::create([
            'inspection_number' => self::PREFIX.'-QC-0002',
            'stage' => 'in_process',
            'inspected_on' => now()->subDays(9)->toDateString(),
            'manufacturing_order_id' => $order->id,
            'product_id' => $finished->id,
            'lot_quantity' => 5000,
            'sample_size' => 150,
            'accepted_quantity' => 4880,
            'rework_quantity' => 120,
            'result' => 'conditional',
            'remarks' => 'Shade accepted against customer waiver; 120 pulled for re-print.',
            'created_by' => $this->userId,
        ]);
        $inProcess->defects()->create([
            'defect' => 'shade variation', 'quantity' => 120, 'severity' => 'minor',
        ]);

        $final = QualityInspection::create([
            'inspection_number' => self::PREFIX.'-QC-0003',
            'stage' => 'final',
            'inspected_on' => now()->subDays(4)->toDateString(),
            'manufacturing_order_id' => $order->id,
            'product_id' => $finished->id,
            'lot_quantity' => 5000,
            'sample_size' => 200,
            'accepted_quantity' => 4820,
            'rejected_quantity' => 180,
            'result' => 'failed',
            'remarks' => 'Registration drift on the second colour.',
            'created_by' => $this->userId,
        ]);
        $final->defects()->createMany([
            ['defect' => 'print misregistration', 'quantity' => 110, 'severity' => 'major'],
            ['defect' => 'creasing off-line', 'quantity' => 50, 'severity' => 'minor'],
            ['defect' => 'ink smear', 'quantity' => 20, 'severity' => 'minor'],
        ]);

        unset($incoming);

        // Through the service the API uses, so the run's rejected quantity is
        // derived rather than typed — and agrees with its inspections.
        app(ShopFloorService::class)->syncOrderRejections($order);
    }

    /**
     * Scrap across three reasons, two of them with a recovery value.
     *
     * All `finished` scrap, so none of it posts a stock movement. That is
     * correct rather than convenient: output rejected on the line was never
     * received into stock, and a fixture that wrote material scrap would drive a
     * demo warehouse negative for board it never held.
     */
    private function scrap(Warehouse $warehouse, ManufacturingOrder $order, Product $finished, Product $material): void
    {
        ProductionScrap::where('scrap_number', 'like', self::PREFIX.'-SC-%')->delete();

        $rows = [
            // The QC rejection above, written off. No recovery — printed cartons
            // with the wrong registration are waste paper.
            ['SC-0001', 4, 180, (float) $finished->cost_price, 'rejection', 0.0,
                'Rejected at final inspection — registration drift.'],
            // Make-ready waste. Every press loses sheets getting the colour
            // right, and the trim is sold to a waste-paper merchant.
            ['SC-0002', 13, 320, (float) $material->cost_price, 'setup_waste', 2100.0,
                'Make-ready sheets; trim sold as waste paper.'],
            // The moulding machine's bad shift.
            ['SC-0003', 21, 95, (float) $finished->cost_price, 'machine_defect', 900.0,
                'Feed screw fault before the breakdown was called.'],
        ];

        foreach ($rows as [$suffix, $daysAgo, $quantity, $unitCost, $reason, $recovery, $remarks]) {
            $value = round($quantity * $unitCost, 2);

            ProductionScrap::create([
                'scrap_number' => self::PREFIX.'-'.$suffix,
                'scrap_date' => now()->subDays($daysAgo)->toDateString(),
                'manufacturing_order_id' => $order->id,
                'product_id' => $finished->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'scrap_type' => 'finished',
                'reason' => $reason,
                'unit_cost' => $unitCost,
                'total_value' => $value,
                // Never more than the value written off — the same rule the
                // form enforces.
                'recoverable_value' => min($recovery, $value),
                'affects_stock' => false,
                'is_posted' => false,
                'remarks' => $remarks,
                'created_by' => $this->userId,
            ]);
        }
    }
}
