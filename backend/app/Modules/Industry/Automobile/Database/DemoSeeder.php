<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Database;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleBooking;
use App\Modules\Industry\Automobile\Models\VehicleWarranty;
use App\Modules\Industry\Automobile\Services\BookingConversionService;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;
use Throwable;

/**
 * Demo fixtures for the Automobile vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * The point of a demo workspace is the *work*. A garage login that opens onto an
 * empty appointment book demonstrates nothing about being a garage, so this
 * seeds a workshop mid-morning: four vehicles with different histories, a day's
 * bookings spread across the statuses the board can show, warranty in all three
 * of its interesting states — live, run out on distance, run out on time — and
 * one job card already on the ramp with a warranty claim behind it.
 *
 * The warranty spread is the part worth having. Cover that has expired *on
 * kilometres while still inside its dates* is the case a workshop argues about,
 * and the case a date-only register gets wrong, so the demo puts a vehicle in
 * exactly that state.
 *
 * Keyed on modules rather than on the trade: the five automobile trades differ
 * by what they switched on, and a spare-parts counter that never enabled Service
 * has no job book to put a job card in.
 *
 * Idempotent: vehicles are matched on their identifier, documents on their
 * number, and the job card is only opened if the booking has not already
 * produced one — a re-seed refreshes the workshop rather than doubling it.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('automobile', $modules, true)) {
            return;
        }

        $owners = $this->owners();
        $vehicles = $this->vehicles($owners, $userId);

        $this->parts();
        $this->warranties($vehicles, $userId);

        $bookings = $this->bookings($vehicles, $userId);

        // Only a workshop can open a job card — a parts counter that never
        // switched Service on has no job book to put one in.
        if (in_array('service', $modules, true)) {
            $this->jobCard($bookings, $userId);
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
        // has no default for. The AUTO- prefix is also what makes a re-seed find
        // these three again rather than adding a second Rajesh Mehta.
        $rows = [
            'mehta' => ['code' => 'AUTO-C01', 'name' => 'Rajesh Mehta', 'phone' => '9824011001', 'type' => 'retail'],
            'patel' => ['code' => 'AUTO-C02', 'name' => 'Sunita Patel', 'phone' => '9824011002', 'type' => 'retail'],
            // A fleet owner: the taxi below is its vehicle, which is what makes
            // the run-out-on-kilometres warranty case believable.
            'travels' => ['code' => 'AUTO-C03', 'name' => 'Nakoda Travels', 'phone' => '9824011003', 'type' => 'wholesale'],
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
     * Four vehicles, deliberately unalike: a car comfortably in warranty, a taxi
     * that has run its cover out on distance, a two-wheeler overdue a service,
     * and a dealer's stock vehicle with a chassis number and no plate or owner.
     *
     * @param  array<string, Customer>  $owners
     * @return array<string, CustomerVehicle>
     */
    private function vehicles(array $owners, int $userId): array
    {
        $rows = [
            'swift' => [
                'registration_no' => 'GJ01AB1234',
                'vin' => 'MA3EWDE1S00123456',
                'engine_no' => 'K12MN1234567',
                'customer_id' => $owners['mehta']->id,
                'make' => 'Maruti Suzuki', 'model' => 'Swift', 'variant' => 'VDi',
                'manufacture_year' => 2024, 'fuel_type' => 'diesel', 'transmission' => 'manual',
                'colour' => 'Pearl White',
                'odometer' => 18400,
                'registration_date' => now()->subMonths(14)->toDateString(),
                'insurance_provider' => 'ICICI Lombard',
                'insurance_policy_no' => 'IL/2026/884412',
                'insurance_expiry' => now()->addMonths(4)->toDateString(),
                // Runs out inside the month, so the "papers expiring" work list
                // is not an empty screen on a fresh demo.
                'puc_expiry' => now()->addDays(12)->toDateString(),
                'service_interval_days' => 180,
                'service_interval_km' => 10000,
            ],
            // The taxi: inside its three years and well past its 45,000 km. This
            // is the case a date-only warranty register gets wrong.
            'dzire' => [
                'registration_no' => 'GJ18CD5678',
                'vin' => 'MA3ETDE1S00778899',
                'customer_id' => $owners['travels']->id,
                'make' => 'Maruti Suzuki', 'model' => 'Dzire', 'variant' => 'Tour S',
                'manufacture_year' => 2023, 'fuel_type' => 'cng', 'transmission' => 'manual',
                'colour' => 'Silver',
                'odometer' => 96500,
                'registration_date' => now()->subMonths(26)->toDateString(),
                'insurance_provider' => 'New India Assurance',
                'insurance_expiry' => now()->subDays(6)->toDateString(),
                'puc_expiry' => now()->subDays(20)->toDateString(),
                'fitness_expiry' => now()->addMonths(9)->toDateString(),
                'service_interval_days' => 90,
                'service_interval_km' => 8000,
            ],
            'activa' => [
                'registration_no' => 'GJ01EF9012',
                'customer_id' => $owners['patel']->id,
                'make' => 'Honda', 'model' => 'Activa', 'variant' => '6G',
                'manufacture_year' => 2022, 'fuel_type' => 'petrol', 'transmission' => 'automatic',
                'colour' => 'Matte Grey',
                'odometer' => 24800,
                'registration_date' => now()->subMonths(38)->toDateString(),
                'insurance_expiry' => now()->addDays(25)->toDateString(),
                'service_interval_days' => 120,
                'service_interval_km' => 4000,
            ],
            // A dealer's floor stock: chassis number only, no plate, no owner.
            'nexon' => [
                'vin' => 'MAT625437PNK55432',
                'make' => 'Tata', 'model' => 'Nexon', 'variant' => 'EV Creative+',
                'manufacture_year' => 2026, 'fuel_type' => 'electric', 'transmission' => 'automatic',
                'colour' => 'Flame Red',
                'odometer' => 14,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            // Matched on whichever identifier the vehicle actually has — the
            // stock vehicle has no plate to match on.
            $match = isset($attrs['registration_no'])
                ? ['registration_no' => $attrs['registration_no']]
                : ['vin' => $attrs['vin']];

            $vehicle = CustomerVehicle::firstOrNew($match);
            $vehicle->fill($attrs + ['created_by' => $userId, 'is_active' => true]);
            $vehicle->odometer_updated_at ??= now()->subDays(3);
            $vehicle->save();

            // A plausible schedule on the two serviced vehicles, so the "service
            // due" work list shows something. The Activa is past both limbs; the
            // Swift is comfortably inside them.
            $schedule = match ($key) {
                'activa' => [
                    'last_service_at' => now()->subMonths(6),
                    'next_service_due_at' => now()->subDays(45)->toDateString(),
                    'next_service_odometer' => 24000,
                ],
                'swift' => [
                    'last_service_at' => now()->subMonths(3),
                    'next_service_due_at' => now()->addMonths(3)->toDateString(),
                    'next_service_odometer' => 26000,
                ],
                default => null,
            };

            if ($schedule) {
                $vehicle->forceFill($schedule)->save();
            }

            $out[$key] = $vehicle;
        }

        return $out;
    }

    /**
     * Spares a workshop actually issues, so a job card has something to fit and
     * the parts picker is not empty.
     */
    private function parts(): void
    {
        $rows = [
            'AUTO-OIL-5W30' => ['name' => 'Engine Oil 5W-30 (3.5L)', 'cost_price' => 1850, 'selling_price' => 2350],
            'AUTO-FLT-OIL' => ['name' => 'Oil Filter', 'cost_price' => 240, 'selling_price' => 390],
            'AUTO-FLT-AIR' => ['name' => 'Air Filter', 'cost_price' => 410, 'selling_price' => 620],
            'AUTO-BRK-PAD' => ['name' => 'Brake Pad Set (Front)', 'cost_price' => 1450, 'selling_price' => 2100],
            'AUTO-BATT-12V' => ['name' => 'Battery 12V 35Ah', 'cost_price' => 3800, 'selling_price' => 5200],
            'AUTO-TYR-165' => ['name' => 'Tyre 165/80 R14', 'cost_price' => 3200, 'selling_price' => 4300],
            // Labour bills as an is_service product — the same convention every
            // other revenue module uses, so it flows through the sales spine.
            'AUTO-LAB-GEN' => [
                'name' => 'Labour — General Service',
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
     * Cover in all three states worth seeing: live, exhausted on distance while
     * still inside its dates, and simply expired.
     *
     * @param  array<string, CustomerVehicle>  $vehicles
     */
    private function warranties(array $vehicles, int $userId): void
    {
        $rows = [
            'WTY-DEMO-001' => [
                'vehicle' => 'swift',
                'type' => 'vehicle', 'provider' => 'manufacturer',
                'start_date' => now()->subMonths(14)->toDateString(),
                'end_date' => now()->addMonths(22)->toDateString(),
                'coverage_months' => 36, 'coverage_km' => 100000, 'start_odometer' => 0,
                'terms' => 'Manufacturer warranty. Excludes consumables, tyres, glass and damage from misuse.',
            ],
            // Inside its dates, past its kilometres — the argument at the counter.
            'WTY-DEMO-002' => [
                'vehicle' => 'dzire',
                'type' => 'vehicle', 'provider' => 'manufacturer',
                'start_date' => now()->subMonths(26)->toDateString(),
                'end_date' => now()->addMonths(10)->toDateString(),
                'coverage_months' => 36, 'coverage_km' => 45000, 'start_odometer' => 0,
                'terms' => 'Manufacturer warranty — 3 years or 45,000 km, whichever is earlier.',
            ],
            // The shop's own promise on a part it fitted, now run out on time.
            'WTY-DEMO-003' => [
                'vehicle' => 'activa',
                'type' => 'part', 'provider' => 'dealer',
                'product_sku' => 'AUTO-BATT-12V',
                'serial_no' => 'EXD-2024-77120',
                'start_date' => now()->subMonths(20)->toDateString(),
                'end_date' => now()->subMonths(2)->toDateString(),
                'coverage_months' => 18, 'start_odometer' => 19200,
                'terms' => 'Battery replacement warranty, 18 months from fitment.',
            ],
        ];

        foreach ($rows as $number => $attrs) {
            $vehicle = $vehicles[$attrs['vehicle']] ?? null;
            if (! $vehicle) {
                continue;
            }

            unset($attrs['vehicle']);

            if (isset($attrs['product_sku'])) {
                $attrs['product_id'] = Product::where('sku', $attrs['product_sku'])->value('id');
                unset($attrs['product_sku']);
            }

            $warranty = VehicleWarranty::firstOrNew(['warranty_number' => $number]);
            $isNew = ! $warranty->exists;

            // claims_count and status are deliberately not rewritten on a
            // re-seed: the job card below stamps a claim, and resetting the
            // counter would make the register disagree with the job book it is
            // meant to summarise.
            $warranty->fill($attrs + [
                'vehicle_id' => $vehicle->id,
                'customer_id' => $vehicle->customer_id,
                'created_by' => $userId,
            ]);

            if ($isNew) {
                $warranty->status = 'active';
            }

            $warranty->save();
        }
    }

    /**
     * A day on the board: one already on the ramp, one confirmed for the
     * afternoon, one still unconfirmed for tomorrow, and one that never turned
     * up — because the no-show rate is a number a service manager watches.
     *
     * @param  array<string, CustomerVehicle>  $vehicles
     * @return array<string, VehicleBooking>
     */
    private function bookings(array $vehicles, int $userId): array
    {
        $rows = [
            'BKG-DEMO-001' => [
                'vehicle' => 'swift',
                'service_type' => 'general', 'source' => 'phone', 'status' => 'arrived',
                'scheduled_at' => now()->setTime(9, 30),
                'estimated_ready_at' => now()->setTime(17, 0),
                'odometer' => 18400, 'estimated_cost' => 4200,
                'complaint' => 'Due for periodic service. Slight vibration on the steering above 80 kmph.',
            ],
            'BKG-DEMO-002' => [
                'vehicle' => 'dzire',
                'service_type' => 'running_repair', 'source' => 'walk_in', 'status' => 'confirmed',
                'scheduled_at' => now()->setTime(14, 0),
                'odometer' => 96500, 'estimated_cost' => 6800,
                'complaint' => 'Clutch slipping on inclines. Customer says it started last week.',
            ],
            'BKG-DEMO-003' => [
                'vehicle' => 'activa',
                'service_type' => 'general', 'source' => 'whatsapp', 'status' => 'booked',
                'scheduled_at' => now()->addDay()->setTime(11, 0),
                'odometer' => 24800, 'estimated_cost' => 1400,
                'complaint' => 'Service overdue. Battery also weak — does not start on the button.',
                'pickup_required' => true,
                'pickup_address' => '14, Shanti Residency, Bopal, Ahmedabad 380058',
            ],
            'BKG-DEMO-004' => [
                'vehicle' => 'swift',
                'service_type' => 'inspection', 'source' => 'online', 'status' => 'no_show',
                'scheduled_at' => now()->subDays(2)->setTime(10, 0),
                'estimated_cost' => 0,
                'complaint' => 'Pre-purchase inspection.',
                'notes' => 'No show: customer did not arrive and did not answer the reminder call.',
            ],
        ];

        $out = [];
        foreach ($rows as $number => $attrs) {
            $vehicle = $vehicles[$attrs['vehicle']] ?? null;
            if (! $vehicle) {
                continue;
            }

            unset($attrs['vehicle']);

            $booking = VehicleBooking::firstOrNew(['booking_number' => $number]);

            // Never rewrite a converted booking: the first run opened a job card
            // against it, and dragging it back to 'arrived' would leave the
            // appointment book still expecting a vehicle that is on the ramp.
            if ($booking->exists && $booking->status === 'converted') {
                $out[$number] = $booking;

                continue;
            }

            $booking->fill($attrs + [
                'vehicle_id' => $vehicle->id,
                'customer_id' => $vehicle->customer_id,
                'created_by' => $userId,
            ]);
            $booking->save();

            $out[$number] = $booking;
        }

        return $out;
    }

    /**
     * Put the morning's arrival on the ramp, so the demo has a live job card
     * with a vehicle, a reading and a warranty claim behind it.
     *
     * Goes through the real conversion service rather than writing a ServiceJob
     * by hand: the whole point of the fixture is to show what converting
     * actually does — pick up the cover, stamp the odometer, link both rows.
     *
     * @param  array<string, VehicleBooking>  $bookings
     */
    private function jobCard(array $bookings, int $userId): void
    {
        $booking = $bookings['BKG-DEMO-001'] ?? null;

        if (! $booking || $booking->service_job_id) {
            return;
        }

        try {
            $job = app(BookingConversionService::class)->convert($booking, 18412, $userId);
        } catch (Throwable) {
            // A demo fixture must never take the seeder down: a workspace with
            // one fewer job card is a far smaller problem than a failed seed.
            return;
        }

        $job->forceFill([
            'diagnosis' => 'Wheel balancing out on both front wheels. Oil and filters due.',
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ])->save();

        $this->worksheet($job);
    }

    /** The spares the technician has put on the worksheet. */
    private function worksheet(ServiceJob $job): void
    {
        if ($job->items()->exists()) {
            return;
        }

        foreach (['AUTO-OIL-5W30', 'AUTO-FLT-OIL', 'AUTO-FLT-AIR'] as $sku) {
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
