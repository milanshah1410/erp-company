<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Database;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Sports\Models\Rental;
use App\Modules\Industry\Sports\Models\RentalAsset;
use App\Modules\Industry\Sports\Services\RentalPricingService;
use Illuminate\Support\Carbon;

/**
 * Demo fixtures for the Sports & Fitness vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A demo hire desk with everything back on time demonstrates nothing, because
 * everything being back on time is not what a hire desk is for. What it is for is
 * the four situations that cost a shop money, so those are what get seeded:
 *
 *  - a hire that is **four days overdue**, so the board opens on something red and
 *    the late fee is visibly running;
 *  - a hire **due back this evening**, which is the row the counter is actually
 *    working on;
 *  - a **forward booking** for next weekend, holding kit that is therefore not
 *    available — the case that proves bookings and hires share one availability
 *    answer;
 *  - a **settled** hire where damage was charged and the deposit met the bill,
 *    which is the only way to see what settlement actually does to the money.
 *
 * Plus one bike on the bench and one written off, so the fleet board has a row
 * that cannot be hired for a reason that is not a contract.
 *
 * Keyed on the module rather than the trade: the vertical has one trade, and the
 * fixtures hold whichever other modules the workspace runs.
 *
 * Idempotent — assets are matched on their code, contracts on a demo note, and the
 * whole run bails once the contracts exist so a re-seed cannot double the fleet's
 * history.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('sports', $modules, true)) {
            return;
        }

        $brands = $this->brands();
        $assets = $this->fleet($brands, $userId);

        $this->contracts($assets, $userId);
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $out = [];
        foreach (['hero' => 'Hero Cycles', 'yonex' => 'Yonex', 'decathlon' => 'Quechua'] as $key => $name) {
            $out[$key] = Brand::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        return $out;
    }

    /**
     * The hire fleet, priced on the bases each thing is actually hired on.
     *
     * Note what is deliberately *not* offered: a treadmill has no hourly rate,
     * because nobody hires one for an afternoon, and the contract screen will
     * refuse to put it on an hourly hire rather than billing a plausible zero.
     *
     * @param  array<string, Brand>  $brands
     * @return array<string, RentalAsset>
     */
    private function fleet(array $brands, int $userId): array
    {
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');

        $rows = [
            'bike1' => [
                'asset_code' => 'SPB-01', 'name' => 'Hero Sprint 26" MTB', 'category' => 'Cycles',
                'brand' => 'hero', 'serial_no' => 'HRO-26-1188',
                'rate_hourly' => 60, 'rate_daily' => 300, 'rate_weekly' => 1500,
                'deposit_amount' => 2000, 'late_fee_percent' => 150, 'requires_id_proof' => true,
                'condition' => 'good', 'purchase_cost' => 12500, 'acquired_on' => '-14 months',
            ],
            'bike2' => [
                'asset_code' => 'SPB-02', 'name' => 'Hero Sprint 26" MTB', 'category' => 'Cycles',
                'brand' => 'hero', 'serial_no' => 'HRO-26-1189',
                'rate_hourly' => 60, 'rate_daily' => 300, 'rate_weekly' => 1500,
                'deposit_amount' => 2000, 'late_fee_percent' => 150, 'requires_id_proof' => true,
                'condition' => 'fair', 'purchase_cost' => 12500, 'acquired_on' => '-14 months',
            ],
            'bike3' => [
                'asset_code' => 'SPB-03', 'name' => 'Hero Ranger 24" Kids', 'category' => 'Cycles',
                'brand' => 'hero', 'serial_no' => 'HRO-24-0431',
                'rate_hourly' => 40, 'rate_daily' => 200, 'rate_weekly' => 1000,
                'deposit_amount' => 1500, 'late_fee_percent' => 150, 'requires_id_proof' => true,
                'condition' => 'good', 'purchase_cost' => 8900, 'acquired_on' => '-8 months',
            ],
            // On the bench: a fleet board with nothing withdrawn cannot show what
            // "withdrawn" looks like, and this is the commonest reason kit is not
            // hireable that has nothing to do with a contract.
            'bike4' => [
                'asset_code' => 'SPB-04', 'name' => 'Hero Ranger 24" Kids', 'category' => 'Cycles',
                'brand' => 'hero', 'serial_no' => 'HRO-24-0432',
                'rate_hourly' => 40, 'rate_daily' => 200, 'rate_weekly' => 1000,
                'deposit_amount' => 1500, 'late_fee_percent' => 150, 'requires_id_proof' => true,
                'condition' => 'damaged', 'status' => 'maintenance',
                'purchase_cost' => 8900, 'acquired_on' => '-8 months',
                'notes' => 'Buckled rear wheel — on the bench.',
            ],
            'racquet1' => [
                'asset_code' => 'SPR-01', 'name' => 'Yonex Nanoray Badminton Racquet', 'category' => 'Racquets',
                'brand' => 'yonex', 'rate_hourly' => 30, 'rate_daily' => 120, 'rate_weekly' => 600,
                'deposit_amount' => 800, 'condition' => 'good',
                'purchase_cost' => 3200, 'acquired_on' => '-6 months',
            ],
            'racquet2' => [
                'asset_code' => 'SPR-02', 'name' => 'Yonex Nanoray Badminton Racquet', 'category' => 'Racquets',
                'brand' => 'yonex', 'rate_hourly' => 30, 'rate_daily' => 120, 'rate_weekly' => 600,
                'deposit_amount' => 800, 'condition' => 'worn',
                'purchase_cost' => 3200, 'acquired_on' => '-6 months',
            ],
            'tent' => [
                'asset_code' => 'SPC-01', 'name' => '4-Person Dome Tent', 'category' => 'Camping',
                'brand' => 'decathlon', 'rate_daily' => 450, 'rate_weekly' => 2200,
                'deposit_amount' => 3000, 'requires_id_proof' => true, 'condition' => 'good',
                'purchase_cost' => 9800, 'acquired_on' => '-20 months',
            ],
            'sleeping' => [
                'asset_code' => 'SPC-02', 'name' => 'Sleeping Bag (-5°C)', 'category' => 'Camping',
                'brand' => 'decathlon', 'rate_daily' => 150, 'rate_weekly' => 700,
                'deposit_amount' => 900, 'condition' => 'good',
                'purchase_cost' => 2400, 'acquired_on' => '-20 months',
            ],
            /**
             * No hourly rate on purpose — nobody hires a treadmill for an
             * afternoon. The contract screen refuses to put it on an hourly hire
             * rather than billing a plausible zero, which is the whole reason a
             * null rate is a real answer.
             */
            'treadmill' => [
                'asset_code' => 'SPF-01', 'name' => 'Motorised Treadmill 2HP', 'category' => 'Fitness',
                'rate_weekly' => 1800, 'rate_monthly' => 5500,
                'deposit_amount' => 8000, 'requires_id_proof' => true, 'condition' => 'good',
                'purchase_cost' => 42000, 'acquired_on' => '-30 months',
            ],
            // Written off: somebody hired it and it never came back.
            'bike5' => [
                'asset_code' => 'SPB-05', 'name' => 'Hero Sprint 26" MTB', 'category' => 'Cycles',
                'brand' => 'hero', 'serial_no' => 'HRO-26-1190',
                'rate_hourly' => 60, 'rate_daily' => 300, 'rate_weekly' => 1500,
                'deposit_amount' => 2000, 'late_fee_percent' => 150, 'requires_id_proof' => true,
                'condition' => 'damaged', 'status' => 'retired',
                'purchase_cost' => 12500, 'acquired_on' => '-26 months',
                'notes' => 'Not returned — written off against the deposit.',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $brandKey = $attrs['brand'] ?? null;
            unset($attrs['brand']);

            if (isset($attrs['acquired_on'])) {
                $attrs['acquired_on'] = Carbon::parse($attrs['acquired_on'])->toDateString();
            }

            $out[$key] = RentalAsset::firstOrCreate(
                ['asset_code' => $attrs['asset_code']],
                $attrs + [
                    'brand_id' => $brandKey ? $brands[$brandKey]->id : null,
                    'warehouse_id' => $warehouseId,
                    'created_by' => $userId,
                ],
            );
        }

        return $out;
    }

    /**
     * Four contracts in the four states that matter.
     *
     * Written straight onto the models rather than through RentalService, because
     * the service quite correctly refuses to book kit into the past and to hand
     * over what is already out — and a demo that could only show today's bookings
     * would show none of the situations worth seeing.
     *
     * @param  array<string, RentalAsset>  $assets
     */
    private function contracts(array $assets, int $userId): void
    {
        if (Rental::query()->where('notes', 'like', 'Demo hire%')->exists()) {
            return;
        }

        $customers = $this->customers();
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');
        $pricing = new RentalPricingService;

        // 1. Four days overdue. The row the board opens on, in red, with the late
        //    fee running at one and a half times the daily rate.
        $this->contract(
            customer: $customers['tourist'],
            warehouseId: $warehouseId,
            userId: $userId,
            assets: [$assets['bike1'], $assets['bike2']],
            basis: 'daily',
            issuedAt: now()->subDays(6),
            dueAt: now()->subDays(4),
            status: 'out',
            note: 'Demo hire — weekend ride, not brought back',
        );

        // 2. Due back this evening: the contract the counter is working on now.
        $this->contract(
            customer: $customers['camper'],
            warehouseId: $warehouseId,
            userId: $userId,
            assets: [$assets['tent'], $assets['sleeping']],
            basis: 'daily',
            issuedAt: now()->subDays(2),
            dueAt: now()->setTime(19, 0),
            status: 'out',
            note: 'Demo hire — camping trip',
        );

        // 3. A forward booking, holding two racquets for next weekend. Proves that
        //    a booking takes kit out of availability exactly as a hire does.
        $this->contract(
            customer: $customers['club'],
            warehouseId: $warehouseId,
            userId: $userId,
            assets: [$assets['racquet1'], $assets['racquet2']],
            basis: 'daily',
            issuedAt: null,
            dueAt: now()->addDays(9)->setTime(20, 0),
            bookedFrom: now()->addDays(7)->setTime(8, 0),
            status: 'booked',
            note: 'Demo hire — club tournament, held for the weekend',
        );

        // 4. Settled, with damage charged and the deposit meeting the bill. The
        //    only way to see what settlement does to the money.
        $settled = $this->contract(
            customer: $customers['tourist'],
            warehouseId: $warehouseId,
            userId: $userId,
            assets: [$assets['bike3']],
            basis: 'daily',
            issuedAt: now()->subDays(20),
            dueAt: now()->subDays(18),
            status: 'out',
            note: 'Demo hire — settled with a damage charge',
        );

        $settled->load('items');
        $returnedAt = now()->subDays(17)->setTime(11, 0);

        foreach ($settled->items as $item) {
            $priced = $pricing->priceLine($settled, $item, $returnedAt);

            $item->forceFill([
                'returned_at' => $returnedAt,
                'condition_in' => 'worn',
                'hire_units' => $priced['hire_units'],
                'hire_charge' => $priced['hire_charge'],
                'late_units' => $priced['late_units'],
                'late_charge' => $priced['late_charge'],
                'damage_charge' => 350,
                'damage_note' => 'Torn saddle cover and a bent mudguard.',
            ])->save();
        }

        $settled->load('items');

        $hire = round($settled->items->sum(fn ($i) => (float) $i->hire_charge), 2);
        $late = round($settled->items->sum(fn ($i) => (float) $i->late_charge), 2);
        $damage = round($settled->items->sum(fn ($i) => (float) $i->damage_charge), 2);
        $total = round($hire + $late + $damage, 2);
        $deposit = (float) $settled->deposit_amount;
        $applied = min($deposit, $total);

        /**
         * Closed without raising an invoice. A demo workspace should show what the
         * settlement *looks* like without seeding a sales order and an invoice
         * that nothing else in the demo data accounts for — the live screen raises
         * both through RentalBillingService.
         */
        $settled->forceFill([
            'status' => 'closed',
            'returned_at' => $returnedAt,
            'hire_total' => $hire,
            'late_total' => $late,
            'damage_total' => $damage,
            'total' => $total,
            'deposit_applied' => $applied,
            'deposit_refunded' => round($deposit - $applied, 2),
        ])->save();
    }

    /**
     * The three hirers, created here rather than borrowed from whatever the
     * workspace happens to hold.
     *
     * A plugin's fixtures have to be self-sufficient: a demo tenant seeded before
     * this vertical existed has no customers at all, and a hire desk with a fleet
     * and no contracts on it demonstrates none of what the vertical is for.
     *
     * @return array<string, Customer>
     */
    private function customers(): array
    {
        $rows = [
            // A walk-in on a weekend — the one who does not bring the bikes back.
            'tourist' => ['code' => 'SPT-C01', 'name' => 'Rakesh Mehta', 'phone' => '9824022001'],
            'camper' => ['code' => 'SPT-C02', 'name' => 'Priya Shah', 'phone' => '9824022002'],
            // A club books ahead and books in quantity, which is what makes the
            // forward booking on the demo board look like the real thing.
            'club' => ['code' => 'SPT-C03', 'name' => 'Riverside Badminton Club', 'phone' => '9824022003', 'type' => 'wholesale'],
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

    /** @param  list<RentalAsset>  $assets */
    private function contract(
        Customer $customer,
        ?int $warehouseId,
        int $userId,
        array $assets,
        string $basis,
        ?Carbon $issuedAt,
        Carbon $dueAt,
        string $status,
        string $note,
        ?Carbon $bookedFrom = null,
    ): Rental {
        $rental = Rental::create([
            'rental_number' => Rental::nextNumber(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouseId,
            'status' => $status,
            'rate_basis' => $basis,
            'booked_from' => $bookedFrom ?? $issuedAt,
            'due_at' => $dueAt,
            'issued_at' => $issuedAt,
            'grace_hours' => 2,
            'id_proof_type' => 'Aadhaar',
            'id_proof_number' => 'XXXX-XXXX-'.str_pad((string) $customer->id, 4, '0', STR_PAD_LEFT),
            'notes' => $note,
            'created_by' => $userId,
        ]);

        foreach ($assets as $asset) {
            $rental->items()->create([
                'asset_id' => $asset->id,
                'rate' => $asset->rateFor($basis) ?? 0,
                'deposit' => $asset->deposit_amount,
                'late_fee_percent' => $asset->late_fee_percent,
                'condition_out' => $asset->condition === 'damaged' ? 'worn' : $asset->condition,
            ]);
        }

        $rental->load('items');
        $rental->forceFill([
            'deposit_amount' => round($rental->items->sum(fn ($i) => (float) $i->deposit), 2),
        ])->save();

        return $rental;
    }
}
