<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Database;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use App\Modules\Industry\Mobile\Models\Buyback;
use App\Modules\Industry\Mobile\Models\EmiPlan;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Models\InsurancePolicy;
use App\Modules\Industry\Mobile\Services\EmiScheduleService;
use Throwable;

/**
 * Demo fixtures for the Mobile vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * The point of a demo workspace is the *work*. A phone shop login that opens onto
 * an empty register demonstrates nothing about being a phone shop, so this seeds a
 * counter mid-morning: four handsets in genuinely different situations, cover in
 * the states that actually get argued about, a finance file with an instalment
 * already late, and a part-exchange the shop is holding and has not paid for yet.
 *
 * Three of the fixtures are chosen to put the register in the states a yes/no
 * screen gets wrong:
 *
 *  - A policy already claimed once, with a limit of one. The customer arrives
 *    certain of a second screen replacement and the answer is no — which is the
 *    single most common argument in this trade.
 *  - A handset still inside its manufacturer warranty *and* insured. The right
 *    answer is to use the warranty, because an insurance claim would spend a
 *    once-only policy on something the maker was going to fix free.
 *  - An in-house EMI file with an overdue instalment, so the collections list is
 *    not an empty screen on a fresh demo.
 *
 * Keyed on modules rather than on the trade: the vertical has one trade today, but
 * a workspace that hand-picked its modules and dropped Service has no job book to
 * put a repair in, and one that dropped Warranty has no card register.
 *
 * Idempotent: handsets are matched on their IMEI, documents on their number, and
 * the finance file's schedule is only built once — a re-seed refreshes the counter
 * rather than doubling it.
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
            'mobile' => ['label' => 'Handsets, Insurance, EMI & Part-Exchange', 'route' => '/mobile/handsets'],
            'warranty' => ['label' => 'Handset Warranty Cards', 'route' => '/warranties', 'modules' => ['mobile', 'warranty']],
            'service' => ['label' => 'Handset Repair Job', 'route' => '/service-jobs', 'modules' => ['mobile', 'service']],
        ];
    }

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('mobile', $modules, true)) {
            return;
        }

        $owners = $this->owners();
        $this->catalogue();

        $handsets = $this->handsets($owners, $userId);

        /**
         * Cards live in the core warranty register, so seeding them needs that
         * module on. The mobile_shop preset enables it; a hand-picked selection
         * that dropped it gets handsets with no cover rather than cards nobody has
         * a screen for.
         */
        if (in_array('warranty', $modules, true)) {
            $this->warrantyCards($handsets, $userId);
        }

        $policies = $this->policies($handsets, $userId);
        $this->financeFile($handsets, $owners, $userId);
        $this->partExchange($owners, $userId);

        // Only a shop with a bench can open a repair — and the repair is what
        // stamps the claim that makes the used-up policy believable.
        if (in_array('service', $modules, true)) {
            $this->repair($handsets, $policies, $userId);
        }
    }

    /**
     * The owners. Real customers rather than walk-ins, because cover and finance
     * both have to belong to somebody and the demo should show the whole chain.
     *
     * @return array<string, Customer>
     */
    private function owners(): array
    {
        // Matched and created on `code`, which the customer master requires and has
        // no default for — firstOrCreate on `name` throws. The MOB- prefix is also
        // what makes a re-seed find these three again rather than adding a second
        // Nikhil Shah.
        $rows = [
            'shah' => ['code' => 'MOB-C01', 'name' => 'Nikhil Shah', 'phone' => '9824022001', 'type' => 'retail'],
            'ansari' => ['code' => 'MOB-C02', 'name' => 'Farida Ansari', 'phone' => '9824022002', 'type' => 'retail'],
            'thakkar' => ['code' => 'MOB-C03', 'name' => 'Dhruv Thakkar', 'phone' => '9824022003', 'type' => 'retail'],
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
     * What a phone counter actually sells: handsets, the accessories that carry the
     * real margin, and the labour line a repair bills under.
     */
    private function catalogue(): void
    {
        $rows = [
            'MOB-SAM-A55' => [
                'name' => 'Samsung Galaxy A55 5G 8/128',
                'cost_price' => 27400, 'selling_price' => 31999, 'warranty_months' => 12,
            ],
            'MOB-APL-15' => [
                'name' => 'Apple iPhone 15 128GB',
                'cost_price' => 68000, 'selling_price' => 76900, 'warranty_months' => 12,
            ],
            'MOB-XIA-14C' => [
                'name' => 'Redmi 14C 6/128',
                'cost_price' => 8100, 'selling_price' => 9499, 'warranty_months' => 12,
            ],
            // The refurbished line a part-exchange is taken into stock as. Its own
            // SKU on purpose: a second-hand handset is not the new one, and putting
            // it into new-phone stock would wreck both the costing and the shelf.
            'MOB-APL-12R' => [
                'name' => 'Apple iPhone 12 128GB — Refurbished',
                'cost_price' => 0, 'selling_price' => 24500, 'warranty_months' => 3,
            ],
            'MOB-ACC-CHG' => ['name' => '25W Fast Charger', 'cost_price' => 780, 'selling_price' => 1299],
            'MOB-ACC-TG' => ['name' => 'Tempered Glass', 'cost_price' => 45, 'selling_price' => 249],
            'MOB-ACC-CASE' => ['name' => 'Silicone Back Cover', 'cost_price' => 90, 'selling_price' => 399],
            'MOB-SPR-DISP' => ['name' => 'Display Assembly (A55)', 'cost_price' => 5600, 'selling_price' => 7900],
            // Labour bills as an is_service product — the convention every other
            // revenue module uses, so it flows through the sales spine.
            'MOB-LAB-REP' => [
                'name' => 'Labour — Handset Repair',
                'cost_price' => 0, 'selling_price' => 500, 'is_service' => true,
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
     * Four handsets, deliberately unalike: one sold and comfortably in warranty,
     * one sold and insured with the policy already used up, one still on the shelf,
     * and one the shop never sold that came in for a repair.
     *
     * Every IMEI here passes its own Luhn check digit, so the register's
     * "IMEI looks wrong" tile reads zero on a fresh demo — it is a real work list,
     * and seeding it pre-filled would teach a demo user to ignore it.
     *
     * @param  array<string, Customer>  $owners
     * @return array<string, Handset>
     */
    private function handsets(array $owners, int $userId): array
    {
        $rows = [
            'a55' => [
                'imei_1' => '354013015678910',
                'imei_2' => '354013015678928',
                'sku' => 'MOB-SAM-A55',
                'customer' => 'shah',
                'brand' => 'Samsung', 'model_name' => 'Galaxy A55 5G', 'storage' => '128GB',
                'colour' => 'Awesome Navy',
                'status' => 'sold',
                'sold_at' => now()->subMonths(3)->toDateString(),
                'warranty_months' => 12,
            ],
            // The argument: insured, and the one claim it allows has been made.
            'iphone15' => [
                'imei_1' => '353928114567899',
                'sku' => 'MOB-APL-15',
                'customer' => 'ansari',
                'brand' => 'Apple', 'model_name' => 'iPhone 15', 'storage' => '128GB',
                'colour' => 'Blue',
                'status' => 'sold',
                'sold_at' => now()->subMonths(7)->toDateString(),
                'warranty_months' => 12,
            ],
            // On the shelf: booked in at goods receipt, no owner yet. That is the
            // ordinary state of a phone shop's stock and the reason the register's
            // customer column is nullable.
            'redmi' => [
                'imei_1' => '869123045678901',
                'imei_2' => '869123045678919',
                'sku' => 'MOB-XIA-14C',
                'brand' => 'Xiaomi', 'model_name' => 'Redmi 14C', 'storage' => '128GB',
                'colour' => 'Starry Blue',
                'status' => 'in_stock',
                'warranty_months' => 12,
            ],
            // Not ours. Bought elsewhere, in for a screen — which is most of the
            // repair trade, and the reason the register exists apart from stock.
            'foreign' => [
                'imei_1' => '351873094567893',
                'customer' => 'thakkar',
                'brand' => 'Vivo', 'model_name' => 'V29e', 'storage' => '256GB',
                'colour' => 'Artistic Red',
                'status' => 'sold',
                'is_third_party' => true,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $customer = isset($attrs['customer']) ? $owners[$attrs['customer']] ?? null : null;
            $productId = isset($attrs['sku']) ? Product::where('sku', $attrs['sku'])->value('id') : null;
            unset($attrs['customer'], $attrs['sku']);

            $handset = Handset::firstOrNew(['imei_1' => $attrs['imei_1']]);
            $handset->fill($attrs + [
                'customer_id' => $customer?->id,
                'product_id' => $productId,
                'created_by' => $userId,
                'is_active' => true,
            ]);
            $handset->save();

            $out[$key] = $handset;
        }

        return $out;
    }

    /**
     * Warranty cards on the core register — one comfortably live, one run out.
     *
     * The live one is deliberately on the same handset that is insured, because the
     * counter's right answer there is "use the warranty": an insurance claim would
     * spend a once-only policy on a fault the maker was going to fix free.
     *
     * @param  array<string, Handset>  $handsets
     */
    private function warrantyCards(array $handsets, int $userId): void
    {
        $rows = [
            'WC-MOB-DEMO-1' => [
                'handset' => 'a55',
                'source' => 'manufacturer',
                'start_date' => now()->subMonths(3)->toDateString(),
                'end_date' => now()->addMonths(9)->toDateString(),
                'coverage_months' => 12,
                'terms' => 'Manufacturer warranty. Excludes physical and liquid damage.',
            ],
            // Run out, on a handset whose owner still has live insurance — the pair
            // the demo exists to show.
            'WC-MOB-DEMO-2' => [
                'handset' => 'iphone15',
                'source' => 'manufacturer',
                'start_date' => now()->subMonths(7)->toDateString(),
                'end_date' => now()->addMonths(5)->toDateString(),
                'coverage_months' => 12,
                'terms' => 'Manufacturer warranty. Excludes physical and liquid damage.',
            ],
        ];

        foreach ($rows as $number => $attrs) {
            $handset = $handsets[$attrs['handset']] ?? null;
            if (! $handset) {
                continue;
            }

            unset($attrs['handset']);

            $card = ProductWarranty::firstOrNew(['warranty_number' => $number]);
            $isNew = ! $card->exists;

            // claims_count and status are deliberately not rewritten on a re-seed:
            // resetting them would make the register disagree with the claims that
            // have been recorded against it.
            $card->fill($attrs + [
                'handset_id' => $handset->id,
                'customer_id' => $handset->customer_id,
                'product_id' => $handset->product_id,
                'serial_no' => $handset->imei_1,
                'created_by' => $userId,
            ]);

            if ($isNew) {
                $card->status = 'active';
            }

            $card->save();
        }
    }

    /**
     * Insurance in the two states worth seeing: live on a handset that is also
     * under warranty, and a policy whose single claim has been used up.
     *
     * @param  array<string, Handset>  $handsets
     * @return array<string, InsurancePolicy>
     */
    private function policies(array $handsets, int $userId): array
    {
        $rows = [
            'INS-MOB-DEMO-1' => [
                'handset' => 'iphone15',
                'provider' => 'Acko',
                'provider_policy_no' => 'AK/2026/774120',
                'start_date' => now()->subMonths(7)->toDateString(),
                'end_date' => now()->addMonths(5)->toDateString(),
                'coverage_months' => 12,
                'premium' => 2499,
                'sum_insured' => 76900,
                'deductible' => 1500,
                'covers_accidental_damage' => true,
                'covers_liquid_damage' => true,
                'claim_limit' => 1,
                'terms' => 'One accidental or liquid damage claim in the term. Excess payable per claim.',
            ],
            'INS-MOB-DEMO-2' => [
                'handset' => 'a55',
                'provider' => 'OneAssist',
                'provider_policy_no' => 'OA/2026/113455',
                'start_date' => now()->subMonths(3)->toDateString(),
                'end_date' => now()->addMonths(21)->toDateString(),
                'coverage_months' => 24,
                'premium' => 1799,
                'sum_insured' => 31999,
                'deductible' => 750,
                'covers_accidental_damage' => true,
                'covers_theft' => true,
                'claim_limit' => 2,
                'terms' => 'Two claims in the term, including theft with an FIR.',
            ],
        ];

        $out = [];
        foreach ($rows as $number => $attrs) {
            $handset = $handsets[$attrs['handset']] ?? null;
            if (! $handset) {
                continue;
            }

            $key = $attrs['handset'];
            unset($attrs['handset']);

            $policy = InsurancePolicy::firstOrNew(['policy_number' => $number]);
            $isNew = ! $policy->exists;

            $policy->fill($attrs + [
                'handset_id' => $handset->id,
                'customer_id' => $handset->customer_id,
                'product_id' => $handset->product_id,
                'imei' => $handset->imei_1,
                'created_by' => $userId,
            ]);

            if ($isNew) {
                $policy->status = 'active';
            }

            $policy->save();

            $out[$key] = $policy;
        }

        return $out;
    }

    /**
     * An in-house finance file with an instalment already late.
     *
     * In-house rather than bank-financed on purpose: it is the case where the money
     * is genuinely the shop's, so the collections tile shows a real receivable and
     * collecting from the screen visibly moves the invoice. A bank file would look
     * identical and demonstrate nothing about the distinction the module turns on.
     *
     * @param  array<string, Handset>  $handsets
     * @param  array<string, Customer>  $owners
     */
    private function financeFile(array $handsets, array $owners, int $userId): void
    {
        $handset = $handsets['a55'] ?? null;
        $customer = $owners['shah'] ?? null;

        if (! $handset || ! $customer) {
            return;
        }

        $plan = EmiPlan::firstOrNew(['plan_number' => 'EMI-MOB-DEMO-1']);

        // Never rewritten once collections have started: the schedule below is only
        // built on the first run, and re-filling the terms would leave a file whose
        // instalments no longer add up to its own loan.
        if ($plan->exists) {
            return;
        }

        $plan->fill([
            'customer_id' => $customer->id,
            'financier' => 'in_house',
            'handset_id' => $handset->id,
            'product_id' => $handset->product_id,
            'imei' => $handset->imei_1,
            'asset_value' => 31999,
            'down_payment' => 6999,
            'financed_amount' => 25000,
            'processing_fee' => 499,
            'tenure_months' => 9,
            // Started three months ago, so two instalments have fallen due and one
            // of them is left unpaid below.
            'first_due_date' => now()->subMonths(3)->toDateString(),
            'status' => 'active',
            'notes' => 'Shop finance. Post-dated cheques held.',
            'created_by' => $userId,
        ])->save();

        try {
            $schedule = app(EmiScheduleService::class);
            $schedule->build($plan);

            /**
             * Collect only the first instalment. The second is left pending and
             * already past its date, which is what puts a row on the collections
             * list — and there is deliberately no invoice on this file, so
             * collecting writes no payment: a demo workspace must not conjure money
             * into a cash book that was never reconciled.
             */
            $first = $plan->instalments()->orderBy('sequence')->first();

            if ($first) {
                $schedule->collect($first, 'cash', now()->subMonths(3)->toDateString(), $userId);
            }
        } catch (Throwable) {
            // A demo fixture must never take the seeder down: a workspace with one
            // fewer finance file is a far smaller problem than a failed seed.
        }
    }

    /**
     * A part-exchange the shop is holding and has not settled — the open credit
     * that the register's headline number is about — plus a dead quote, because the
     * quote-to-acceptance rate is a figure an owner watches.
     *
     * Deliberately not settled against a bill and not taken into stock: both are
     * the acts being demonstrated, and a demo where they have already happened
     * shows a finished register instead of a working one.
     *
     * @param  array<string, Customer>  $owners
     */
    private function partExchange(array $owners, int $userId): void
    {
        $rows = [
            'BBK-MOB-DEMO-1' => [
                'customer' => 'thakkar',
                'brand' => 'Apple', 'model_name' => 'iPhone 12', 'storage' => '128GB',
                'colour' => 'Black',
                'imei' => '353082145678901',
                'age_months' => 38,
                'grade' => 'B',
                'defects' => 'Light scuffs on the frame. Battery health 84%.',
                'has_box' => true, 'has_charger' => false, 'has_bill' => true,
                'base_value' => 18000, 'deduction' => 2500, 'bonus_value' => 3000,
                'status' => 'accepted',
                'quoted_on' => now()->subDays(2)->toDateString(),
                'valid_until' => now()->addDays(5)->toDateString(),
                'accepted_on' => now()->subDays(2)->toDateString(),
                'notes' => 'Customer buying an A55. Handset in the drawer, credit not yet applied.',
            ],
            // Quoted and walked away — the grade D case, which is also the one the
            // valuation form refuses to accept without defects written down.
            'BBK-MOB-DEMO-2' => [
                'customer' => 'ansari',
                'brand' => 'Oppo', 'model_name' => 'A31', 'storage' => '64GB',
                'imei' => '866432015678905',
                'age_months' => 61,
                'grade' => 'D',
                'defects' => 'Does not charge. Screen delaminating at the bottom edge.',
                'base_value' => 1200, 'deduction' => 700, 'bonus_value' => 0,
                'status' => 'rejected',
                'quoted_on' => now()->subDays(9)->toDateString(),
                'valid_until' => now()->subDays(2)->toDateString(),
                'notes' => 'Rejected: customer expected more than the handset is worth.',
            ],
        ];

        foreach ($rows as $number => $attrs) {
            $customer = $owners[$attrs['customer']] ?? null;
            unset($attrs['customer']);

            $buyback = Buyback::firstOrNew(['buyback_number' => $number]);

            // A settled trade-in is never rewritten: it has a payment behind it, and
            // dragging it back to `accepted` would leave a tender on an invoice with
            // no open credit to explain it.
            if ($buyback->exists && $buyback->status === 'settled') {
                continue;
            }

            $buyback->fill($attrs + [
                'customer_id' => $customer?->id,
                'created_by' => $userId,
            ])->save();
        }
    }

    /**
     * The repair on the bench — and the claim that makes the used-up policy real.
     *
     * Booked against the insured iPhone with the policy on it, so the observer
     * stamps the claim and the insurance book shows a policy that is finished. That
     * is the state a demo needs: the register saying "no" to a customer who is
     * certain the answer is yes.
     *
     * @param  array<string, Handset>  $handsets
     * @param  array<string, InsurancePolicy>  $policies
     */
    private function repair(array $handsets, array $policies, int $userId): void
    {
        $handset = $handsets['iphone15'] ?? null;
        $policy = $policies['iphone15'] ?? null;

        if (! $handset || ! $policy) {
            return;
        }

        $job = ServiceJob::firstOrNew(['job_number' => 'SVC-MOB-DEMO-1']);

        // Only opened once. The observer stamps a claim when the job first names the
        // policy, and re-creating it on every seed would count a fresh claim each
        // time until the register said the policy had been claimed five times.
        if ($job->exists) {
            return;
        }

        try {
            $job->fill([
                'customer_id' => $handset->customer_id,
                'handset_id' => $handset->id,
                'insurance_policy_id' => $policy->id,
                'item_name' => 'Apple iPhone 15 — cracked screen',
                'item_serial' => $handset->imei_1,
                'status' => 'in_progress',
                'priority' => 'high',
                'complaint' => 'Dropped on a tiled floor. Display cracked, touch working.',
                'diagnosis' => 'Display assembly to be replaced. Frame straight, no board damage.',
                // Free to the customer beyond the excess — the insurer settles the
                // rest, which is the part that is not modelled yet.
                'is_warranty' => true,
                'received_at' => now()->subHours(4),
                'promised_at' => now()->addDay(),
                'started_at' => now()->subHours(2),
                'notes' => 'Insurance claim. Excess of ₹1,500 collected from the customer.',
                'created_by' => $userId,
            ])->save();

            $this->worksheet($job);
        } catch (Throwable) {
            // See financeFile(): a fixture must never take the seeder down.
        }
    }

    /** The parts and labour on the technician's worksheet. */
    private function worksheet(ServiceJob $job): void
    {
        if ($job->items()->exists()) {
            return;
        }

        foreach (['MOB-SPR-DISP', 'MOB-LAB-REP'] as $sku) {
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
        // workspace whose store was never receipted would go negative. Issuing them
        // from the screen is the act being demonstrated.
    }
}
