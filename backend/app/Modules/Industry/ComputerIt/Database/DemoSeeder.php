<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Database;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceContract;
use App\Modules\Industry\ComputerIt\Models\AssetWarranty;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use App\Modules\Industry\ComputerIt\Models\LicenceKey;
use App\Modules\Industry\ComputerIt\Models\ServiceCall;
use App\Modules\Industry\ComputerIt\Services\ServiceCallConversionService;
use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use Throwable;

/**
 * Demo fixtures for the Computer & IT vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * The point of a demo workspace is the *work*. A computer-shop login that opens
 * onto an empty call book demonstrates nothing about being a computer shop, so
 * this seeds a support desk mid-morning: an install base with machines in
 * different states, cover in the three states worth arguing about (live and
 * on-site, live but carry-in only, and run out), a licence register with a full
 * volume key and a subscription about to lapse, and a call book with something
 * already late on it.
 *
 * The parts worth having are the awkward ones. A breached call, a volume key
 * with no seats left, and a machine whose warranty is live but does *not* pay
 * for an engineer to travel are the three situations a shop of this kind loses
 * money on, and they are exactly what a date-only, seat-blind register gets
 * wrong.
 *
 * Keyed on modules rather than on the trade: this vertical has one trade today,
 * but a workspace that switched Service off has no job book to convert a call
 * into, and the fixtures have to hold either way.
 *
 * Idempotent: machines are matched on their serial, documents on their number,
 * and the job card is only opened if the call has not already produced one — a
 * re-seed refreshes the desk rather than doubling it.
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
            'computer_it' => ['label' => 'Install Base, AMC & Licence Keys', 'route' => '/computer-it/assets'],
            'service' => ['label' => 'IT Bench Job Card', 'route' => '/service-jobs', 'modules' => ['computer_it', 'service']],
        ];
    }

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('computer_it', $modules, true)) {
            return;
        }

        $customers = $this->customers();
        $this->catalogue();

        $contracts = $this->contracts($customers, $userId);
        $assets = $this->assets($customers, $contracts, $userId);

        $this->warranties($assets, $userId);
        $this->licenceKeys($customers, $assets, $userId);

        $calls = $this->calls($customers, $assets, $userId);

        // Only a shop with a bench can open a job card — a workspace that never
        // switched Service on has no job book to put one in.
        if (in_array('service', $modules, true)) {
            $this->jobCard($calls, $userId);
        }
    }

    /**
     * The customers. A corporate account on an AMC, a walk-in retail customer
     * and a small practice — the three shapes of buyer this trade actually has,
     * and the reason the same shop needs both a call book and a counter.
     *
     * @return array<string, Customer>
     */
    private function customers(): array
    {
        // Matched and created on `code`, which the customer master requires and
        // has no default for. The IT- prefix is also what makes a re-seed find
        // these three again rather than adding a second Shreeji Textiles.
        $rows = [
            'shreeji' => ['code' => 'IT-C01', 'name' => 'Shreeji Textiles Pvt Ltd', 'phone' => '9824022001', 'type' => 'wholesale'],
            'nirali' => ['code' => 'IT-C02', 'name' => 'Nirali Shah', 'phone' => '9824022002', 'type' => 'retail'],
            'aarav' => ['code' => 'IT-C03', 'name' => 'Aarav Legal Services', 'phone' => '9824022003', 'type' => 'retail'],
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
     * What the shop sells and fits, so the machine register has something to
     * point at and a job card has something to charge for.
     */
    private function catalogue(): void
    {
        $rows = [
            'IT-LAP-L3520' => ['name' => 'Dell Latitude 3520 (i5 / 16 GB / 512 GB)', 'cost_price' => 51000, 'selling_price' => 58900],
            'IT-DSK-OPTI' => ['name' => 'Dell OptiPlex 3000 Desktop', 'cost_price' => 38000, 'selling_price' => 44500],
            'IT-SRV-T350' => ['name' => 'Lenovo ThinkSystem ST250 Server', 'cost_price' => 128000, 'selling_price' => 149000],
            'IT-PRN-M211' => ['name' => 'HP LaserJet M211d Printer', 'cost_price' => 12400, 'selling_price' => 15200],
            'IT-SSD-1TB' => ['name' => 'NVMe SSD 1 TB', 'cost_price' => 4900, 'selling_price' => 6400],
            'IT-RAM-16' => ['name' => 'DDR4 16 GB SODIMM', 'cost_price' => 2900, 'selling_price' => 3900],
            'IT-ACC-KBM' => ['name' => 'Wireless Keyboard & Mouse Set', 'cost_price' => 980, 'selling_price' => 1450],
            'IT-SW-OFFICE' => ['name' => 'MS Office LTSC 2021 (per seat)', 'cost_price' => 21000, 'selling_price' => 24500],
            // Labour bills as an is_service product — the same convention every
            // other revenue module uses, so it flows through the sales spine.
            'IT-LAB-BENCH' => [
                'name' => 'Labour — Bench Repair',
                'cost_price' => 0, 'selling_price' => 650, 'is_service' => true,
            ],
            'IT-LAB-SITE' => [
                'name' => 'Labour — On-site Visit',
                'cost_price' => 0, 'selling_price' => 900, 'is_service' => true,
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
     * One live AMC with visits already drawn down, so the contract screen shows
     * a quota being consumed rather than a pristine document nobody has used.
     *
     * @param  array<string, Customer>  $customers
     * @return array<string, ServiceContract>
     */
    private function contracts(array $customers, int $userId): array
    {
        $contract = ServiceContract::firstOrNew(['contract_number' => 'AMC-DEMO-IT01']);

        // visits_used is deliberately not rewritten on a re-seed: the call and
        // job card below draw it down, and resetting it would make the contract
        // disagree with the work booked against it.
        $contract->fill([
            'customer_id' => $customers['shreeji']->id,
            'title' => 'Annual maintenance — 14 desktops, 2 servers, quarterly preventive',
            'start_date' => now()->subMonths(5)->toDateString(),
            'end_date' => now()->addMonths(7)->toDateString(),
            'visits_allowed' => 24,
            'amount' => 48000,
            'status' => 'active',
            'created_by' => $userId,
        ]);

        if (! $contract->exists) {
            $contract->visits_used = 6;
        }

        $contract->save();

        return ['shreeji' => $contract];
    }

    /**
     * Four machines, deliberately unalike: a laptop comfortably covered with an
     * on-site plan, a desktop out of warranty but on the AMC, a server overdue
     * its preventive visit, and a printer on the shelf that has never been
     * delivered.
     *
     * @param  array<string, Customer>  $customers
     * @param  array<string, ServiceContract>  $contracts
     * @return array<string, CustomerAsset>
     */
    private function assets(array $customers, array $contracts, int $userId): array
    {
        $rows = [
            'latitude' => [
                'serial_no' => 'DL9XKLM23',
                'customer' => 'nirali',
                'product_sku' => 'IT-LAP-L3520',
                'category' => 'laptop',
                'make' => 'Dell', 'model' => 'Latitude 3520',
                'specification' => 'i5-1235U · 16 GB · 512 GB NVMe · 15.6" FHD',
                'os' => 'Windows 11 Pro',
                'mac_address' => '3C:52:82:1A:4E:07',
                'site' => 'Home office, Satellite',
                'assigned_user' => 'Nirali Shah',
                'purchase_date' => now()->subMonths(8)->toDateString(),
                'installed_on' => now()->subMonths(8)->toDateString(),
                'status' => 'deployed',
            ],
            // On the AMC, out of manufacturer warranty — the commonest machine
            // in any corporate install base, and the one a shop makes its money
            // on.
            'optiplex' => [
                'serial_no' => 'OPX77120A',
                'customer' => 'shreeji',
                'product_sku' => 'IT-DSK-OPTI',
                'category' => 'desktop',
                'make' => 'Dell', 'model' => 'OptiPlex 3000',
                'specification' => 'i3-12100 · 8 GB · 1 TB HDD',
                'os' => 'Windows 11 Pro',
                'site' => 'Bodakdev — 2nd floor, Accounts',
                'department' => 'Accounts',
                'assigned_user' => 'Hetal Panchal',
                'purchase_date' => now()->subMonths(40)->toDateString(),
                'installed_on' => now()->subMonths(40)->toDateString(),
                'amc' => 'shreeji',
                'status' => 'deployed',
                'service_interval_days' => 90,
                'last_service_at' => now()->subDays(96),
                'next_service_due_at' => now()->subDays(6)->toDateString(),
            ],
            // The one that will hurt if it is forgotten.
            'server' => [
                'serial_no' => 'LNV-ST250-4471',
                'customer' => 'shreeji',
                'product_sku' => 'IT-SRV-T350',
                'category' => 'server',
                'make' => 'Lenovo', 'model' => 'ThinkSystem ST250',
                'specification' => 'Xeon E-2314 · 32 GB ECC · 2 × 2 TB RAID 1',
                'os' => 'Windows Server 2022',
                'mac_address' => '00:1A:64:F2:9C:11',
                'site' => 'Bodakdev — server room',
                'department' => 'IT',
                'purchase_date' => now()->subMonths(18)->toDateString(),
                'installed_on' => now()->subMonths(18)->toDateString(),
                'amc' => 'shreeji',
                'status' => 'deployed',
                'service_interval_days' => 90,
                'last_service_at' => now()->subDays(104),
                'next_service_due_at' => now()->subDays(14)->toDateString(),
            ],
            // Sold but not yet delivered: a machine with a serial, no owner and
            // no site. The register has to hold this state or a shop cannot
            // register goods-in serials at all.
            'printer' => [
                'serial_no' => 'HPM211-88210',
                'product_sku' => 'IT-PRN-M211',
                'category' => 'printer',
                'make' => 'HP', 'model' => 'LaserJet M211d',
                'status' => 'in_stock',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $customerKey = $attrs['customer'] ?? null;
            $amcKey = $attrs['amc'] ?? null;
            $sku = $attrs['product_sku'] ?? null;
            unset($attrs['customer'], $attrs['amc'], $attrs['product_sku']);

            $asset = CustomerAsset::firstOrNew(['serial_no' => $attrs['serial_no']]);

            $asset->fill($attrs + [
                'customer_id' => $customerKey ? $customers[$customerKey]->id : null,
                'product_id' => $sku ? Product::where('sku', $sku)->value('id') : null,
                'amc_contract_id' => $amcKey ? ($contracts[$amcKey]->id ?? null) : null,
                'created_by' => $userId,
            ]);

            // The tag is generated once and then belongs to the machine — a
            // re-seed must not reissue it, or the sticker on the chassis stops
            // matching the register.
            if (! $asset->exists) {
                $asset->asset_tag = CustomerAsset::nextNumber();
            }

            $asset->save();

            $out[$key] = $asset;
        }

        return $out;
    }

    /**
     * Cover in the three states worth seeing: live *and* on-site, live but
     * carry-in only, and run out.
     *
     * The middle one is the case a shop gets wrong. A customer with a live
     * warranty assumes an engineer will come; a register that stores only dates
     * cannot tell them otherwise, and the visit gets made for free.
     *
     * @param  array<string, CustomerAsset>  $assets
     */
    private function warranties(array $assets, int $userId): void
    {
        $rows = [
            // Next-business-day on site, and live. The engineer travels and
            // nobody pays the shop for it — which is why the cost sits with the
            // manufacturer and the register has to prove it.
            'ITW-DEMO-001' => [
                'asset' => 'server',
                'type' => 'product', 'provider' => 'manufacturer',
                'service_level' => 'nbd_onsite',
                'start_date' => now()->subMonths(18)->toDateString(),
                'end_date' => now()->addMonths(18)->toDateString(),
                'coverage_months' => 36,
                'terms' => 'Manufacturer 3-year next-business-day on-site. Excludes consumables and accidental damage.',
            ],
            // Live, and carry-in only: the customer must bring the machine in.
            'ITW-DEMO-002' => [
                'asset' => 'latitude',
                'type' => 'product', 'provider' => 'manufacturer',
                'service_level' => 'carry_in',
                'start_date' => now()->subMonths(8)->toDateString(),
                'end_date' => now()->addMonths(4)->toDateString(),
                'coverage_months' => 12,
                'terms' => 'Manufacturer 1-year carry-in. Battery covered for 6 months.',
            ],
            // Run out on time — the desktop the AMC now carries instead.
            'ITW-DEMO-003' => [
                'asset' => 'optiplex',
                'type' => 'product', 'provider' => 'manufacturer',
                'service_level' => 'onsite',
                'start_date' => now()->subMonths(40)->toDateString(),
                'end_date' => now()->subMonths(4)->toDateString(),
                'coverage_months' => 36,
                'terms' => 'Manufacturer 3-year on-site warranty.',
            ],
            // The shop's own promise on a part it fitted.
            'ITW-DEMO-004' => [
                'asset' => 'optiplex',
                'type' => 'part', 'provider' => 'dealer',
                'product_sku' => 'IT-SSD-1TB',
                'serial_no' => 'SSD-2026-44190',
                'service_level' => 'carry_in',
                'start_date' => now()->subMonths(2)->toDateString(),
                'end_date' => now()->addMonths(34)->toDateString(),
                'coverage_months' => 36,
                'terms' => 'SSD replacement warranty, 3 years from fitment, carry-in.',
            ],
        ];

        foreach ($rows as $number => $attrs) {
            $asset = $assets[$attrs['asset']] ?? null;
            if (! $asset) {
                continue;
            }

            unset($attrs['asset']);

            if (isset($attrs['product_sku'])) {
                $attrs['product_id'] = Product::where('sku', $attrs['product_sku'])->value('id');
                unset($attrs['product_sku']);
            }

            $warranty = AssetWarranty::firstOrNew(['warranty_number' => $number]);
            $isNew = ! $warranty->exists;

            // claims_count and status are deliberately not rewritten on a
            // re-seed: the job card below stamps a claim, and resetting the
            // counter would make the register disagree with the job book it is
            // meant to summarise.
            $warranty->fill($attrs + [
                'asset_id' => $asset->id,
                'customer_id' => $asset->customer_id,
                'serial_no' => $attrs['serial_no'] ?? $asset->serial_no,
                'created_by' => $userId,
            ]);

            if ($isNew) {
                $warranty->status = 'active';
            }

            $warranty->save();
        }
    }

    /**
     * The licence register, in the four states that matter: an OEM key married
     * to a machine, a volume key with nothing left to give, a subscription about
     * to lapse, and one still on the shelf.
     *
     * @param  array<string, Customer>  $customers
     * @param  array<string, CustomerAsset>  $assets
     */
    private function licenceKeys(array $customers, array $assets, int $userId): void
    {
        $rows = [
            // OEM: tied to the laptop it shipped on, and not movable.
            'W11P-6VNT4-DEMO-K7R2Q' => [
                'software' => 'Windows 11 Pro', 'version' => '23H2', 'publisher' => 'Microsoft',
                'licence_type' => 'oem', 'seats' => 1, 'seats_used' => 1,
                'customer' => 'nirali', 'asset' => 'latitude',
                'vendor' => 'Redington India',
                'valid_from' => now()->subMonths(8)->toDateString(),
                'status' => 'allocated',
                'notes' => 'OEM licence — stays with this chassis, cannot be transferred.',
            ],
            // A volume key with every seat spoken for. The state a shop only
            // discovers when it tries to activate the twenty-sixth machine.
            'OFF21-VOLUME-DEMO-3M8XZ' => [
                'software' => 'MS Office LTSC', 'version' => '2021', 'publisher' => 'Microsoft',
                'licence_type' => 'volume', 'seats' => 25, 'seats_used' => 25,
                'customer' => 'shreeji',
                'product_sku' => 'IT-SW-OFFICE',
                'vendor' => 'Softline',
                'valid_from' => now()->subMonths(14)->toDateString(),
                'status' => 'allocated',
                'notes' => 'Open Value agreement 8842-11. All seats deployed.',
            ],
            // Runs out inside the month — the renewal list is not empty on a
            // fresh demo.
            'TALLY-SUB-DEMO-91LKD' => [
                'software' => 'TallyPrime Silver', 'version' => '4.1', 'publisher' => 'Tally Solutions',
                'licence_type' => 'subscription', 'seats' => 1, 'seats_used' => 1,
                'customer' => 'aarav',
                'vendor' => 'Tally Partner — Ahmedabad',
                'valid_from' => now()->subMonths(11)->toDateString(),
                'valid_to' => now()->addDays(19)->toDateString(),
                'status' => 'allocated',
            ],
            // On the shelf, ready to sell.
            'ESET-2026-DEMO-77Q3B' => [
                'software' => 'ESET Endpoint Antivirus', 'version' => '11', 'publisher' => 'ESET',
                'licence_type' => 'retail', 'seats' => 5, 'seats_used' => 0,
                'vendor' => 'Iris Global',
                'valid_from' => now()->subDays(20)->toDateString(),
                'valid_to' => now()->addMonths(11)->toDateString(),
                'status' => 'in_stock',
            ],
        ];

        foreach ($rows as $key => $attrs) {
            $customerKey = $attrs['customer'] ?? null;
            $assetKey = $attrs['asset'] ?? null;
            $sku = $attrs['product_sku'] ?? null;
            unset($attrs['customer'], $attrs['asset'], $attrs['product_sku']);

            $licence = LicenceKey::firstOrNew(['licence_key' => $key]);

            $licence->fill($attrs + [
                'licence_key' => $key,
                'customer_id' => $customerKey ? $customers[$customerKey]->id : null,
                'asset_id' => $assetKey ? ($assets[$assetKey]->id ?? null) : null,
                'product_id' => $sku ? Product::where('sku', $sku)->value('id') : null,
                'allocated_at' => ($attrs['status'] ?? 'in_stock') === 'allocated' ? now()->subMonths(2) : null,
                'created_by' => $userId,
            ]);

            $licence->save();
        }
    }

    /**
     * A morning's call book: one already late, one booked for today, one
     * attended and waiting on a part, one closed on the phone, and one that is
     * about to become bench work.
     *
     * @param  array<string, Customer>  $customers
     * @param  array<string, CustomerAsset>  $assets
     * @return array<string, ServiceCall>
     */
    private function calls(array $customers, array $assets, int $userId): array
    {
        $rows = [
            // Logged two days ago at critical priority and still not attended.
            // This is the row the breach tile counts, and the reason the board
            // sorts by promise rather than by date.
            'late' => [
                'call_number' => 'SC-DEMO-001',
                'customer' => 'shreeji', 'asset' => 'server',
                'type' => 'onsite', 'priority' => 'critical', 'status' => 'assigned',
                'logged_at' => now()->subDays(2)->setTime(9, 20),
                'sla_due_at' => now()->subDays(2)->setTime(13, 20),
                'complaint' => 'Server not reachable from accounts machines after a power cut. RAID alarm audible.',
                'contact_person' => 'Hetal Panchal',
                'contact_phone' => '9824022011',
                'is_chargeable' => false,
            ],
            'today' => [
                'call_number' => 'SC-DEMO-002',
                'customer' => 'shreeji', 'asset' => 'optiplex',
                'type' => 'preventive', 'priority' => 'low', 'status' => 'scheduled',
                'logged_at' => now()->subDays(1)->setTime(16, 5),
                'scheduled_at' => now()->setTime(15, 0),
                'complaint' => 'Quarterly preventive — clean, thermal paste, disk health, Windows updates.',
                'is_chargeable' => false,
            ],
            // Attended, not resolved: the state a support desk needs to be able
            // to say out loud.
            'waiting' => [
                'call_number' => 'SC-DEMO-003',
                'customer' => 'aarav',
                'type' => 'carry_in', 'priority' => 'normal', 'status' => 'attended',
                'logged_at' => now()->subDays(4)->setTime(11, 45),
                'attended_at' => now()->subDays(4)->setTime(15, 30),
                'complaint' => 'Desktop restarts under load. Suspected PSU.',
                'resolution' => 'Diagnosed on site — 450 W PSU failing under load. Replacement ordered.',
            ],
            'closed' => [
                'call_number' => 'SC-DEMO-004',
                'customer' => 'nirali', 'asset' => 'latitude',
                'type' => 'remote', 'priority' => 'normal', 'status' => 'resolved',
                'logged_at' => now()->subDays(6)->setTime(10, 10),
                'attended_at' => now()->subDays(6)->setTime(10, 40),
                'closed_at' => now()->subDays(6)->setTime(11, 5),
                'complaint' => 'Outlook not syncing after password change.',
                'resolution' => 'Re-authenticated the profile over remote session. Verified send/receive.',
            ],
            // The one that becomes a job card below.
            'incoming' => [
                'call_number' => 'SC-DEMO-005',
                'customer' => 'nirali', 'asset' => 'latitude',
                'type' => 'carry_in', 'priority' => 'high', 'status' => 'logged',
                'logged_at' => now()->subHours(3),
                'complaint' => 'Laptop will not power on. No charging light. Customer bringing it in this afternoon.',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $customerKey = $attrs['customer'] ?? null;
            $assetKey = $attrs['asset'] ?? null;
            unset($attrs['customer'], $attrs['asset']);

            $call = ServiceCall::firstOrNew(['call_number' => $attrs['call_number']]);

            // status and service_job_id are not rewritten once the call has
            // produced bench work — a re-seed must not reopen a converted call.
            if ($call->exists && $call->service_job_id) {
                $out[$key] = $call;

                continue;
            }

            $asset = $assetKey ? ($assets[$assetKey] ?? null) : null;

            $call->fill($attrs + [
                'customer_id' => $customerKey ? $customers[$customerKey]->id : null,
                'asset_id' => $asset?->id,
                'contract_id' => $asset?->amc_contract_id,
                'site_address' => $asset?->site,
                'sla_due_at' => $attrs['sla_due_at']
                    ?? ServiceCall::slaDueFrom($attrs['priority'] ?? 'normal', $attrs['logged_at']),
                'created_by' => $userId,
            ]);

            $call->save();

            $out[$key] = $call;
        }

        return $out;
    }

    /**
     * One call already on the bench, so the job book is not empty and the link
     * between a call and the work it became is visible from both ends.
     *
     * @param  array<string, ServiceCall>  $calls
     */
    private function jobCard(array $calls, int $userId): void
    {
        $call = $calls['incoming'] ?? null;

        if (! $call || $call->service_job_id) {
            return;
        }

        try {
            app(ServiceCallConversionService::class)->convert($call, $userId);
        } catch (Throwable) {
            // A re-seed can legitimately find the call already spent, and the
            // conversion service is right to refuse it. Anything else worth
            // knowing about surfaces through DemoShopSeeder's per-seeder warning.
        }
    }
}
