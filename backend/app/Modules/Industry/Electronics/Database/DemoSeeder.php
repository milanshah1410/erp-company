<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Database;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use App\Modules\Industry\Electronics\Models\ServiceRequest;
use App\Modules\Industry\Electronics\Services\RequestConversionService;
use Throwable;

/**
 * Demo fixtures for the Electronics & Appliances vertical, found by convention
 * from IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * The point of a demo workspace is the *work*. An appliance dealer's login that
 * opens onto an empty request desk demonstrates nothing about being an appliance
 * dealer, so this seeds a shop mid-morning: four units with genuinely different
 * aftersales situations, cover in the three states that actually get argued
 * about, a desk with today's visits on it, and one job card already on the bench.
 *
 * The warranty spread is the part worth having. Cover that is *live but
 * parts-only* is the case this trade argues about, and the case a yes/no register
 * gets wrong — the customer hears "covered" and expects a free visit, and the
 * shop is owed the labour. The demo puts a washing machine in exactly that state.
 *
 * Keyed on modules rather than on the trade: the five electronics trades differ
 * by what they switched on, and an electrical goods counter that never enabled
 * Service has no job book to put a job card in.
 *
 * Idempotent: units are matched on their identifier, documents on their number,
 * and the job card is only opened if the request has not already produced one —
 * a re-seed refreshes the shop rather than doubling it.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('electronics', $modules, true)) {
            return;
        }

        $owners = $this->owners();
        $this->catalogue();

        $units = $this->units($owners, $userId);

        // Cards live in the core warranty register, so seeding them needs that
        // module on. Every electronics trade preset enables it; a hand-picked
        // selection that dropped it gets units with no cover rather than cards
        // nobody has a screen for.
        if (in_array('warranty', $modules, true)) {
            $this->warranties($units, $userId);
        }

        $requests = $this->requests($units, $userId);

        // Only a shop with a workshop can open a job card — an electrical
        // counter that never switched Service on has no job book to put one in.
        if (in_array('service', $modules, true)) {
            $this->jobCard($requests, $userId);
        }
    }

    /**
     * The owners. Real customers rather than walk-ins, because a job card has to
     * be billable to someone and the demo should show the whole chain.
     *
     * @return array<string, Customer>
     */
    private function owners(): array
    {
        // Matched and created on `code`, which the customer master requires and
        // has no default for. The ELEC- prefix is also what makes a re-seed find
        // these three again rather than adding a second Farida Shaikh.
        $rows = [
            'shaikh' => ['code' => 'ELEC-C01', 'name' => 'Farida Shaikh', 'phone' => '9824022001', 'type' => 'retail'],
            'joshi' => ['code' => 'ELEC-C02', 'name' => 'Nikhil Joshi', 'phone' => '9824022002', 'type' => 'retail'],
            // A small office: the laptop below is theirs, which is what makes the
            // AMC and the out-of-warranty repair believable.
            'infotech' => ['code' => 'ELEC-C03', 'name' => 'Sunrise Infotech LLP', 'phone' => '9824022003', 'type' => 'wholesale'],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = Customer::firstOrCreate(
                ['code' => $attrs['code']],
                $attrs + ['city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India', 'is_active' => true],
            );
        }

        return $out;
    }

    /**
     * The goods, the spares and the labour line, so the unit register has
     * products behind it and a job card has something to fit.
     */
    private function catalogue(): void
    {
        $rows = [
            'ELEC-TV-55U' => ['name' => 'Smart LED TV 55" 4K', 'cost_price' => 34500, 'selling_price' => 46990],
            'ELEC-WM-70F' => ['name' => 'Front Load Washing Machine 7 kg', 'cost_price' => 24800, 'selling_price' => 32990],
            'ELEC-MOB-M35' => ['name' => 'Smartphone 6GB / 128GB', 'cost_price' => 14200, 'selling_price' => 17499],
            'ELEC-LAP-I5' => ['name' => 'Laptop 15.6" Core i5 / 16GB', 'cost_price' => 48000, 'selling_price' => 58990],
            // Spares a bench actually consumes.
            'ELEC-SP-PANEL' => ['name' => 'LED Panel — 55" Replacement', 'cost_price' => 18500, 'selling_price' => 24500],
            'ELEC-SP-PUMP' => ['name' => 'Drain Pump Assembly', 'cost_price' => 1150, 'selling_price' => 1850],
            'ELEC-SP-KBD' => ['name' => 'Laptop Keyboard Module', 'cost_price' => 1400, 'selling_price' => 2400],
            'ELEC-SP-BRKT' => ['name' => 'TV Wall Mount Bracket (Tilt)', 'cost_price' => 620, 'selling_price' => 1150],
            // Labour bills as an is_service product — the same convention every
            // other revenue module uses, so it flows through the sales spine.
            'ELEC-LAB-VISIT' => [
                'name' => 'Service Visit — Onsite Call-out',
                'cost_price' => 0, 'selling_price' => 450, 'is_service' => true,
            ],
            'ELEC-LAB-INST' => [
                'name' => 'Installation & Demo',
                'cost_price' => 0, 'selling_price' => 800, 'is_service' => true,
            ],
        ];

        foreach ($rows as $sku => $attrs) {
            Product::firstOrCreate(
                ['sku' => $sku],
                $attrs + ['tax_percent' => 18, 'is_active' => true],
            );
        }
    }

    /**
     * Four units, deliberately unalike: a television sold last week and still
     * waiting for the fitter, a washing machine in its second year with
     * parts-only cover left, an office laptop the shop never sold, and a handset
     * on the shelf with nothing but an IMEI to its name.
     *
     * @param  array<string, Customer>  $owners
     * @return array<string, DeviceUnit>
     */
    private function units(array $owners, int $userId): array
    {
        $rows = [
            // Sold, delivered, not yet installed — the outstanding-fitters list.
            'tv' => [
                'serial_no' => 'TVX55U-2026-004182',
                'sku' => 'ELEC-TV-55U',
                'customer' => 'shaikh',
                'brand' => 'Soniq', 'model_name' => 'Smart LED TV 55" 4K',
                'status' => 'sold',
                'sold_at' => now()->subDays(6)->toDateString(),
                'delivered_at' => now()->subDays(2)->toDateString(),
                'installation_required' => true,
                'warranty_months' => 24,
            ],
            // Installed eighteen months ago: inside its dates, past the year of
            // free labour. The argument at the counter.
            'washer' => [
                'serial_no' => 'WMF70-2024-118803',
                'sku' => 'ELEC-WM-70F',
                'customer' => 'joshi',
                'brand' => 'Elektra', 'model_name' => 'Front Load 7 kg',
                'status' => 'sold',
                'sold_at' => now()->subMonths(18)->toDateString(),
                'delivered_at' => now()->subMonths(18)->toDateString(),
                'installation_required' => true,
                'installed_at' => now()->subMonths(18)->addDays(2)->toDateString(),
                'warranty_months' => 24,
            ],
            // A machine the shop never sold, brought in for repair. Most of the
            // repair trade looks like this, and it is why the register has to
            // accept units with no invoice behind them.
            'laptop' => [
                'serial_no' => 'LTP15-8842-KQ',
                'sku' => 'ELEC-LAP-I5',
                'customer' => 'infotech',
                'brand' => 'Nexbook', 'model_name' => '15.6" Core i5',
                'status' => 'sold',
                'is_third_party' => true,
                'sold_at' => now()->subMonths(40)->toDateString(),
            ],
            // On the shelf: an IMEI booked in at goods receipt, no owner yet.
            'phone' => [
                'imei_1' => '358240051111110',
                'imei_2' => '358240051111128',
                'serial_no' => 'MOB-M35-99120',
                'sku' => 'ELEC-MOB-M35',
                'brand' => 'Aveon', 'model_name' => 'M35 6/128',
                'status' => 'in_stock',
                'warranty_months' => 12,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $sku = $attrs['sku'] ?? null;
            $ownerKey = $attrs['customer'] ?? null;
            unset($attrs['sku'], $attrs['customer']);

            if ($sku) {
                $attrs['product_id'] = Product::where('sku', $sku)->value('id');
            }
            if ($ownerKey) {
                $attrs['customer_id'] = $owners[$ownerKey]->id;
            }

            // Matched on whichever identifier the unit actually has — every one
            // of these has a serial, but a handset-only shop would match on IMEI.
            $match = isset($attrs['serial_no'])
                ? ['serial_no' => $attrs['serial_no']]
                : ['imei_1' => $attrs['imei_1']];

            $unit = DeviceUnit::firstOrNew($match);
            $unit->fill($attrs + ['created_by' => $userId, 'is_active' => true]);
            $unit->save();

            $out[$key] = $unit;
        }

        return $out;
    }

    /**
     * Cover in the three states worth seeing: full and live, live but parts-only,
     * and simply expired.
     *
     * Written onto core's `product_warranties` rather than a register of this
     * plugin's own, because that is the register the shop actually settles claims
     * from. The plugin's contribution is the `unit_id` link and the scope pair,
     * and seeding through them is the only way the demo shows what they are for.
     *
     * Only seeded when the workspace has the `warranty` module: an electronics
     * trade always does, but the seeder is handed the module list precisely so a
     * hand-picked selection that dropped it does not end up with cards nobody can
     * open.
     *
     * @param  array<string, DeviceUnit>  $units
     */
    private function warranties(array $units, int $userId): void
    {
        $rows = [
            // Brand new television: everything covered, onsite.
            'DWTY-DEMO-001' => [
                'unit' => 'tv',
                'source' => 'manufacturer',
                'start_date' => now()->subDays(6)->toDateString(),
                'end_date' => now()->addMonths(24)->subDays(6)->toDateString(),
                'coverage_months' => 24,
                'covers_parts' => true, 'covers_labour' => true, 'is_onsite' => true,
                'terms' => 'Manufacturer warranty. Panel covered for 24 months; physical damage and power surge excluded.',
            ],
            /**
             * The washing machine: inside its two years, past the twelve months
             * of free labour. This is the case a flat yes/no register gets wrong
             * — the customer hears "covered", the shop is owed the call-out.
             */
            'DWTY-DEMO-002' => [
                'unit' => 'washer',
                'source' => 'manufacturer',
                'start_date' => now()->subMonths(18)->toDateString(),
                'end_date' => now()->addMonths(6)->toDateString(),
                'coverage_months' => 24,
                'covers_parts' => true, 'covers_labour' => false, 'is_onsite' => true,
                'terms' => 'Parts and labour for the first 12 months; parts only thereafter. Call-out charged after year one.',
            ],
            // The shop's own extended plan on the same machine, sold at the
            // counter and now run out — the renewal the expiring list exists for.
            'DWTY-DEMO-003' => [
                'unit' => 'washer',
                'source' => 'extended',
                'start_date' => now()->subMonths(18)->toDateString(),
                'end_date' => now()->subDays(20)->toDateString(),
                'coverage_months' => 17,
                'covers_parts' => true, 'covers_labour' => true, 'is_onsite' => true,
                'terms' => 'Extended plan sold at the counter. Lapsed — offer renewal.',
            ],
        ];

        foreach ($rows as $number => $attrs) {
            $unit = $units[$attrs['unit']] ?? null;
            if (! $unit) {
                continue;
            }

            unset($attrs['unit']);

            $warranty = ProductWarranty::firstOrNew(['warranty_number' => $number]);
            $isNew = ! $warranty->exists;

            // claims_count and status are deliberately not rewritten on a
            // re-seed: the job card below stamps a claim, and resetting the
            // counter would make the register disagree with the job book it is
            // meant to summarise.
            $warranty->fill($attrs + [
                'unit_id' => $unit->id,
                'customer_id' => $unit->customer_id,
                'product_id' => $unit->product_id,
                'serial_no' => $unit->identifier(),
                'created_by' => $userId,
            ]);

            if ($isNew) {
                $warranty->status = 'active';
            }

            $warranty->save();
        }
    }

    /**
     * A day on the desk: an installation the fitter is going to this afternoon,
     * a fault logged overnight that nobody has slotted yet, a delivery for
     * tomorrow, and a demo already closed on the phone — because the ratio of
     * calls answered to visits made is the number this desk exists to produce.
     *
     * @param  array<string, DeviceUnit>  $units
     * @return array<string, ServiceRequest>
     */
    private function requests(array $units, int $userId): array
    {
        $rows = [
            'SRQ-DEMO-001' => [
                'unit' => 'tv',
                'type' => 'installation', 'source' => 'phone', 'status' => 'scheduled',
                'priority' => 'normal',
                'scheduled_at' => now()->setTime(15, 0),
                'promised_at' => now()->setTime(18, 0),
                'is_onsite' => true,
                'address' => 'B-402, Sahajanand Heights, Vastrapur, Ahmedabad 380015',
                'complaint' => 'Wall mount and demo for the 55" delivered on Saturday. Customer wants it on the bedroom wall, not the living room.',
                'estimated_cost' => 800,
            ],
            /**
             * Unscheduled and already past the time it was promised — the state
             * the overdue tile is counting, and the reason the list sorts
             * unslotted work to the top.
             */
            'SRQ-DEMO-002' => [
                'unit' => 'washer',
                'type' => 'repair', 'source' => 'whatsapp', 'status' => 'logged',
                'priority' => 'high',
                'promised_at' => now()->subHours(3),
                'is_onsite' => true,
                'address' => '17, Aangan Bungalows, Bopal, Ahmedabad 380058',
                'complaint' => 'Not draining. Water standing in the drum after the cycle finishes.',
                'estimated_cost' => 2300,
            ],
            'SRQ-DEMO-003' => [
                'unit' => 'laptop',
                'type' => 'repair', 'source' => 'walk_in', 'status' => 'logged',
                'priority' => 'normal',
                'is_onsite' => false,
                'complaint' => 'Half the keyboard dead after a spill. Machine is out of warranty — quote before proceeding.',
                'estimated_cost' => 3200,
            ],
            // Closed on the phone: no job card, no visit, and the honest record
            // of a call that was answered rather than attended.
            'SRQ-DEMO-004' => [
                'type' => 'demo', 'source' => 'online', 'status' => 'closed',
                'priority' => 'low',
                'is_onsite' => false,
                'contact_phone' => '9824022099',
                'complaint' => 'Asked whether the 7 kg front load fits a 60 cm alcove.',
                'closed_at' => now()->subDay(),
                'closure_note' => 'Dimensions confirmed on the call. Customer will visit the showroom on Sunday.',
            ],
        ];

        $out = [];
        foreach ($rows as $number => $attrs) {
            $unitKey = $attrs['unit'] ?? null;
            unset($attrs['unit']);

            $unit = $unitKey ? ($units[$unitKey] ?? null) : null;

            if ($unitKey && ! $unit) {
                continue;
            }

            $request = ServiceRequest::firstOrNew(['request_number' => $number]);

            // Never rewrite a converted request: the first run opened a job card
            // against it, and dragging it back to 'logged' would leave the desk
            // still chasing a visit that is on the bench.
            if ($request->exists && $request->status === 'converted') {
                $out[$number] = $request;

                continue;
            }

            $request->fill($attrs + [
                'unit_id' => $unit?->id,
                'customer_id' => $unit?->customer_id,
                'product_id' => $unit?->product_id,
                'created_by' => $userId,
            ]);
            $request->save();

            $out[$number] = $request;
        }

        return $out;
    }

    /**
     * Put the overdue washing machine on the bench, so the demo has a live job
     * card with a unit, a parts-only warranty behind it and a spare on the
     * worksheet.
     *
     * Goes through the real conversion service rather than writing a ServiceJob
     * by hand: the whole point of the fixture is to show what converting actually
     * does — pick up the cover, link both rows, and decide from the cover's scope
     * whether the customer pays. Parts-only cover means this job stays billable,
     * which is exactly the behaviour worth demonstrating.
     *
     * @param  array<string, ServiceRequest>  $requests
     */
    private function jobCard(array $requests, int $userId): void
    {
        $request = $requests['SRQ-DEMO-002'] ?? null;

        if (! $request || $request->service_job_id) {
            return;
        }

        try {
            $job = app(RequestConversionService::class)->convert($request, $userId);
        } catch (Throwable) {
            // A demo fixture must never take the seeder down: a workspace with
            // one fewer job card is a far smaller problem than a failed seed.
            return;
        }

        $job->forceFill([
            'diagnosis' => 'Drain pump seized — impeller jammed with lint and a coin. Pump assembly to be replaced.',
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ])->save();

        $this->worksheet($job);
    }

    /** The spares and labour the technician has put on the worksheet. */
    private function worksheet(ServiceJob $job): void
    {
        if ($job->items()->exists()) {
            return;
        }

        // The pump is covered (parts), the call-out is not (labour) — the whole
        // reason the warranty carries two scope flags rather than one.
        foreach (['ELEC-SP-PUMP', 'ELEC-LAB-VISIT'] as $sku) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) {
                continue;
            }

            $job->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $product->selling_price,
                'tax_percent' => $product->tax_percent ?? 18,
            ]);
        }

        // Lines are left un-issued on purpose: issuing moves stock, and a demo
        // workspace whose store was never receipted would go negative. Issuing
        // them from the screen is the act being demonstrated.
    }
}
