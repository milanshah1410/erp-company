<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Database;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use App\Modules\Industry\Agriculture\Models\AgriLicence;
use App\Modules\Industry\Agriculture\Models\FarmProductionLog;
use App\Modules\Industry\Agriculture\Models\LivestockAnimal;
use App\Modules\Industry\Agriculture\Models\LivestockFlock;
use App\Modules\Industry\Agriculture\Services\FarmProductionService;
use Illuminate\Support\Facades\DB;

/**
 * Demo fixtures for the Agriculture vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * The point of a demo workspace is the *work*, not the nav. A dairy login that
 * opens onto an empty herd demonstrates nothing, so this seeds a farm that has
 * been running for a fortnight: animals with different yields, a layer flock with
 * real mortality and lay figures, and a dealer's licence register with one
 * licence about to lapse and one already lapsed — because that is the state the
 * register exists to make visible.
 *
 * Everything is keyed on what the workspace has switched on, so the seeder can be
 * handed any trade's module list. Re-running is safe: records are matched on
 * their natural keys, and production entries only post stock the first time they
 * are created — posting them again on a re-seed would double a fortnight of milk.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** Days of production history to write. */
    private const DAYS = 7;

    /**
     * Litres per session for each demo cow, in order. Hard-coded rather than
     * randomised so a re-seed produces the same farm and the yield columns stay
     * comparable between runs.
     *
     * @var list<float>
     */
    private const YIELDS = [7.5, 6.0, 9.25, 5.5];

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (in_array('agriculture', $modules, true)) {
            $this->seedDealer($userId);
        }

        if (in_array('livestock', $modules, true)) {
            $this->seedFarm($userId);
        }
    }

    /**
     * A dealer's shelf and paperwork: agri-typed products, and three licences in
     * three different states.
     */
    private function seedDealer(?int $userId): void
    {
        $this->product('AGRI-SEED-01', 'Cotton Hybrid Seed 450g', [
            'agri_input_type' => 'seed', 'crop' => 'Cotton', 'variety' => 'Bt-II',
            'germination_percent' => 78, 'cost_price' => 620, 'selling_price' => 767,
            'track_batch' => true, 'track_expiry' => true,
        ]);
        $this->product('AGRI-SEED-02', 'Wheat Seed HD-2967 40kg', [
            'agri_input_type' => 'seed', 'crop' => 'Wheat', 'variety' => 'HD-2967',
            'germination_percent' => 85, 'cost_price' => 1450, 'selling_price' => 1680,
            'track_batch' => true, 'track_expiry' => true,
        ]);
        $this->product('AGRI-FERT-01', 'Urea 46% N 45kg', [
            'agri_input_type' => 'fertilizer', 'nutrient_content' => '46:0:0',
            'cost_price' => 245, 'selling_price' => 266.50, 'track_batch' => true,
        ]);
        $this->product('AGRI-FERT-02', 'NPK 12:32:16 50kg', [
            'agri_input_type' => 'fertilizer', 'nutrient_content' => '12:32:16',
            'cost_price' => 1290, 'selling_price' => 1470, 'track_batch' => true,
        ]);
        $this->product('AGRI-PEST-01', 'Imidacloprid 17.8% SL 500ml', [
            'agri_input_type' => 'pesticide', 'technical_name' => 'Imidacloprid 17.8% SL',
            'crop' => 'Cotton', 'cost_price' => 410, 'selling_price' => 495,
            'track_batch' => true, 'track_expiry' => true,
        ]);
        $this->product('AGRI-PEST-02', 'Mancozeb 75% WP 1kg', [
            'agri_input_type' => 'pesticide', 'technical_name' => 'Mancozeb 75% WP',
            'crop' => 'Potato', 'cost_price' => 330, 'selling_price' => 390,
            'track_batch' => true, 'track_expiry' => true,
        ]);

        // Three states on purpose: current, about to lapse, already lapsed. The
        // register's whole job is to make the last two impossible to miss during
        // a season, so a demo that only holds valid licences shows none of it.
        $this->licence('seed', 'SD/DAO/2024/1187', [
            'form_number' => 'Form B',
            'issued_on' => now()->subYears(2)->toDateString(),
            'valid_till' => now()->addMonths(14)->toDateString(),
        ], $userId);

        $this->licence('fertilizer', 'FRT/O-1/2023/0442', [
            'form_number' => 'Form O-1',
            'issued_on' => now()->subYears(3)->toDateString(),
            'valid_till' => now()->addDays(24)->toDateString(),
        ], $userId);

        $this->licence('insecticide', 'INS/V/2022/0913', [
            'form_number' => 'Form V',
            'issued_on' => now()->subYears(3)->toDateString(),
            'valid_till' => now()->subDays(18)->toDateString(),
        ], $userId);
    }

    /**
     * A working farm: four cows, two flocks, and a week of the daily book —
     * posted, so the stock and the ratios on every screen are real rather than
     * decorative.
     */
    private function seedFarm(?int $userId): void
    {
        $warehouse = Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            return;
        }

        /**
         * Both farm trades run the same `livestock` module, so the module set
         * cannot tell a dairy from a poultry shed — but the demo has to. A
         * poultry workspace holding four cows makes the two logins look like the
         * same ERP, which is the one thing DemoShopSeeder exists to disprove. The
         * trade is read from the workspace's own setting; anything else that
         * switches livestock on (a mixed farm) gets both.
         */
        $trade = (string) (Setting::where('key', 'shop_profile')->value('value') ?? '');
        $withHerd = $trade !== 'poultry';
        $withFlocks = $trade !== 'dairy_farm';

        $milk = $withHerd ? $this->product('FARM-MILK-01', 'Raw Cow Milk (litre)', [
            'cost_price' => 34, 'selling_price' => 56, 'track_expiry' => true,
        ]) : null;
        $eggs = $withFlocks ? $this->product('FARM-EGG-01', 'Table Eggs (piece)', [
            'cost_price' => 4.10, 'selling_price' => 6.50,
        ]) : null;
        $cattleFeed = $withHerd ? $this->product('FARM-FEED-01', 'Cattle Feed Pellets (kg)', [
            'agri_input_type' => 'feed', 'cost_price' => 26, 'selling_price' => 32,
        ]) : null;
        $layerFeed = $withFlocks ? $this->product('FARM-FEED-02', 'Layer Mash (kg)', [
            'agri_input_type' => 'feed', 'cost_price' => 29, 'selling_price' => 35,
        ]) : null;

        $animals = [];
        foreach ($withHerd ? [
            ['C-101', 'Ganga', 'cow', 'HF Cross', 3],
            ['C-102', 'Yamuna', 'cow', 'Jersey', 2],
            ['B-201', 'Kaveri', 'buffalo', 'Murrah', 4],
            ['C-103', 'Sita', 'cow', 'Gir', 1],
        ] : [] as $i => [$tag, $name, $species, $breed, $lactation]) {
            $animals[] = LivestockAnimal::firstOrCreate(
                ['tag_number' => $tag],
                [
                    'name' => $name,
                    'species' => $species,
                    'breed' => $breed,
                    'gender' => 'female',
                    'date_of_birth' => now()->subYears(4 + $i)->subMonths(3)->toDateString(),
                    'acquired_on' => now()->subYears(2)->toDateString(),
                    'acquisition_type' => $i === 3 ? 'born' : 'purchased',
                    'acquisition_cost' => $i === 3 ? 0 : 62000 + ($i * 4500),
                    'warehouse_id' => $warehouse->id,
                    'lactation_number' => $lactation,
                    // One animal dry, so the herd summary shows the distinction
                    // that matters: a dry cow is still on the farm and still
                    // eating, which is not the same as one that has left.
                    'status' => $i === 3 ? 'dry' : 'active',
                    'created_by' => $userId,
                ],
            );
        }

        $layers = null;

        if ($withFlocks) {
            // Two batches on purpose: a layer shed in lay, and a broiler batch
            // part-way through its cycle. They are the two shapes the screen has
            // to report differently — the broiler row has no lay percentage and
            // no feed-per-dozen, and seeing that dash is the point.
            $layers = LivestockFlock::firstOrCreate(
                ['batch_code' => 'FLK-L-01'],
                [
                    'bird_type' => 'layer',
                    'breed' => 'BV-300',
                    'placed_on' => now()->subDays(180)->toDateString(),
                    'opening_birds' => 2000,
                    'current_birds' => 2000,
                    'chick_cost' => 96000,
                    'warehouse_id' => $warehouse->id,
                    'status' => 'active',
                    'created_by' => $userId,
                ],
            );

            LivestockFlock::firstOrCreate(
                ['batch_code' => 'FLK-B-07'],
                [
                    'bird_type' => 'broiler',
                    'breed' => 'Cobb 430',
                    'placed_on' => now()->subDays(26)->toDateString(),
                    'opening_birds' => 5000,
                    'current_birds' => 5000,
                    'chick_cost' => 215000,
                    'warehouse_id' => $warehouse->id,
                    'status' => 'active',
                    'created_by' => $userId,
                ],
            );
        }

        $service = app(FarmProductionService::class);

        for ($day = self::DAYS; $day >= 1; $day--) {
            $date = now()->subDays($day)->toDateString();

            foreach ($animals as $i => $animal) {
                if ($animal->status !== 'active') {
                    continue;
                }

                foreach (['morning', 'evening'] as $session) {
                    $litres = self::YIELDS[$i % count(self::YIELDS)]
                        + ($session === 'evening' ? -0.75 : 0)
                        // A gentle drift over the week so the yield columns are
                        // not four identical numbers.
                        + (($day % 3) * 0.25);

                    $this->log($service, $userId, [
                        'log_date' => $date,
                        'session' => $session,
                        'animal_id' => $animal->id,
                        'product_id' => $milk->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => round($litres, 2),
                        'unit_cost' => 34,
                        'fat_percent' => $animal->species === 'buffalo' ? 6.8 : 4.1,
                        'snf_percent' => $animal->species === 'buffalo' ? 9.2 : 8.6,
                        'feed_product_id' => $cattleFeed->id,
                        'feed_quantity' => 4,
                    ]);
                }
            }

            // The layer shed: one collection a day, with the couple of birds a
            // 2,000-bird flock genuinely loses in a week.
            if ($layers) {
                $this->log($service, $userId, [
                    'log_date' => $date,
                    'session' => 'day',
                    'flock_id' => $layers->id,
                    'product_id' => $eggs->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1640 + (($day % 4) * 25),
                    'unit_cost' => 4.10,
                    'feed_product_id' => $layerFeed->id,
                    'feed_quantity' => 230,
                    'mortality_count' => $day % 3 === 0 ? 1 : 0,
                ]);
            }
        }
    }

    /**
     * Write one production entry and post it.
     *
     * The guard is what makes a re-seed safe: an entry that already exists is not
     * posted again, because posting is what moves stock and a second pass would
     * hand the demo farm a fortnight of milk it never produced.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function log(FarmProductionService $service, ?int $userId, array $attributes): void
    {
        $key = [
            'log_date' => $attributes['log_date'],
            'session' => $attributes['session'],
            'animal_id' => $attributes['animal_id'] ?? null,
            'flock_id' => $attributes['flock_id'] ?? null,
            'product_id' => $attributes['product_id'],
        ];

        if (FarmProductionLog::where($key)->exists()) {
            return;
        }

        DB::transaction(function () use ($service, $userId, $attributes) {
            $log = FarmProductionLog::create($attributes + ['created_by' => $userId]);
            $service->post($log, $userId);
        });
    }

    /**
     * A product the demo needs, created only if this workspace has not got it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function product(string $sku, string $name, array $attributes): Product
    {
        return Product::firstOrCreate(
            ['sku' => $sku],
            $attributes + ['name' => $name, 'is_active' => true],
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function licence(string $type, string $number, array $attributes, ?int $userId): void
    {
        AgriLicence::firstOrCreate(
            ['licence_type' => $type, 'licence_number' => $number],
            $attributes + [
                'authority' => 'District Agriculture Officer',
                'holder_name' => 'Demo Agro Centre',
                'premises' => 'Shop 4, Market Yard Road, Rajkot 360001',
                'status' => 'active',
                'created_by' => $userId,
            ],
        );
    }
}
