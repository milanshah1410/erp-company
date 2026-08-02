<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Database;

use App\Models\Customer;
use App\Models\EyePrescription;
use App\Models\OpticalOrder;
use App\Models\Product;
use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use App\Modules\Industry\Optical\Models\OpticalAppointment;
use Illuminate\Support\Carbon;

/**
 * Demo fixtures for the Optical & Eyewear vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A demo optician with an empty diary and nothing on the collection shelf
 * demonstrates none of what this vertical is for, so the fixtures are the states
 * a shop actually opens these screens to deal with:
 *
 *  - a frame tray with the boxed markings filled in, because "52▫18-140" is the
 *    vocabulary the trade uses and a blank column does not show that;
 *  - lenses with real grinding ranges and different warranty terms from the
 *    frames, which is what makes a handover produce two cards rather than one;
 *  - today's diary with somebody already waiting, one slot overdue and one
 *    booked for this afternoon;
 *  - a pair that has been sitting ready for nine days that nobody has rung. That
 *    is the number the collection desk exists to make visible, and no shop ever
 *    discovers it on its own.
 *
 * Keyed on the module, and every step is skipped rather than faked when the
 * workspace has no customers yet — the generic demo generator may not have run.
 * Idempotent: products match on SKU, and the diary and the order are only
 * written when this vertical has nothing yet.
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
            'optical' => ['label' => 'Eye Test, Diary & Spectacle Order', 'route' => '/optical-orders'],
        ];
    }

    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('optical', $modules, true)) {
            return;
        }

        $frames = $this->frames();
        $lenses = $this->lenses();

        $customer = Customer::query()->orderBy('id')->first();

        if (! $customer) {
            return;
        }

        $prescription = $this->prescription($customer, $userId);
        $this->diary($customer, $prescription, $userId);
        $this->waitingPair($customer, $prescription, $frames[0], $lenses[0], $userId);
    }

    /**
     * The tray. Three frames a counter would recognise apart from each other —
     * a wide acetate, a small metal and a rimless — because the whole point of
     * the frame columns is telling one from another without holding them.
     *
     * @return list<Product>
     */
    private function frames(): array
    {
        $rows = [
            [
                'sku' => 'OPT-FRM-001', 'name' => 'Aria Acetate Rectangle — Tortoise',
                'cost_price' => 900, 'selling_price' => 2400,
                'frame_shape' => 'rectangle', 'frame_rim' => 'full_rim', 'frame_material' => 'acetate',
                'frame_gender' => 'unisex', 'frame_colour' => 'Tortoise',
                'frame_eye_size' => 54, 'frame_bridge_size' => 18, 'frame_temple_length' => 145,
                'frame_tray' => 'A-1',
                // Two years on the hinge is the usual promise on an acetate
                // frame, and it is what makes a card worth issuing at handover.
                'warranty_months' => 24,
                'warranty_terms' => 'Two years against manufacturing defects in the hinge and frame front. Accidental breakage is not covered.',
            ],
            [
                'sku' => 'OPT-FRM-002', 'name' => 'Kite Metal Cat-Eye — Rose Gold',
                'cost_price' => 1200, 'selling_price' => 3200,
                'frame_shape' => 'cat_eye', 'frame_rim' => 'full_rim', 'frame_material' => 'stainless_steel',
                'frame_gender' => 'women', 'frame_colour' => 'Rose Gold',
                'frame_eye_size' => 49, 'frame_bridge_size' => 17, 'frame_temple_length' => 140,
                'frame_tray' => 'B-3',
                'warranty_months' => 12,
                'warranty_terms' => 'One year against manufacturing defects. Plating wear is not a defect.',
            ],
            [
                'sku' => 'OPT-FRM-003', 'name' => 'Feather Rimless Titanium',
                'cost_price' => 2600, 'selling_price' => 6800,
                'frame_shape' => 'oval', 'frame_rim' => 'rimless', 'frame_material' => 'titanium',
                'frame_gender' => 'unisex', 'frame_colour' => 'Gunmetal',
                'frame_eye_size' => 52, 'frame_bridge_size' => 19, 'frame_temple_length' => 145,
                'frame_tray' => 'C-1',
                'warranty_months' => 24,
                'warranty_terms' => 'Two years on the mount and screws. Rimless frames must be fitted with polycarbonate or Trivex lenses.',
            ],
        ];

        return array_map(fn (array $row) => $this->product($row + ['is_frame' => true]), $rows);
    }

    /**
     * The lens board. Ranges are real ones: a standard CR39 single vision blank
     * stops well short of what a high-index does, which is exactly the refusal
     * the order form is supposed to make before anything is sent to the lab.
     *
     * @return list<Product>
     */
    private function lenses(): array
    {
        $rows = [
            [
                'sku' => 'OPT-LNS-SV150', 'name' => 'Single Vision CR39 1.50 — AR',
                'cost_price' => 300, 'selling_price' => 900,
                'lens_type' => 'single_vision', 'lens_material' => 'cr39', 'lens_index' => 1.50,
                'lens_coating' => 'ar', 'min_sph' => -6.00, 'max_sph' => 4.00, 'max_cyl' => -2.00,
                // Coating cover is shorter than a frame's, which is why a pair
                // yields two cards with two different end dates.
                'warranty_months' => 12,
                'warranty_terms' => 'One year against coating peel, crazing and delamination under normal use.',
            ],
            [
                'sku' => 'OPT-LNS-SV167', 'name' => 'Single Vision High Index 1.67 — Blue Cut',
                'cost_price' => 1400, 'selling_price' => 3600,
                'lens_type' => 'single_vision', 'lens_material' => 'high_index', 'lens_index' => 1.67,
                'lens_coating' => 'blue_cut', 'min_sph' => -12.00, 'max_sph' => 6.00, 'max_cyl' => -4.00,
                'warranty_months' => 12,
                'warranty_terms' => 'One year against coating peel and crazing.',
            ],
            [
                'sku' => 'OPT-LNS-PROG', 'name' => 'Progressive Polycarbonate 1.59 — Photochromic',
                'cost_price' => 2800, 'selling_price' => 7500,
                'lens_type' => 'progressive', 'lens_material' => 'polycarbonate', 'lens_index' => 1.59,
                'lens_coating' => 'photochromic', 'min_sph' => -8.00, 'max_sph' => 5.00, 'max_cyl' => -3.00,
                'warranty_months' => 6,
                'warranty_terms' => 'Six months adaptation guarantee: remade once at no charge if the wearer cannot adapt.',
            ],
        ];

        return array_map(fn (array $row) => $this->product($row), $rows);
    }

    /** @param  array<string, mixed>  $row */
    private function product(array $row): Product
    {
        $product = Product::withTrashed()->firstOrNew(['sku' => $row['sku']]);

        $product->fill($row + [
            'tax_percent' => 12,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
        ]);
        $product->save();

        return $product;
    }

    /** One test on file, still valid, so the order form has powers to copy. */
    private function prescription(Customer $customer, int $userId): EyePrescription
    {
        $existing = EyePrescription::query()->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        return EyePrescription::create([
            'prescription_number' => EyePrescription::nextNumber(),
            'customer_id' => $customer->id,
            'patient_name' => $customer->name,
            'patient_age' => 42,
            'patient_phone' => $customer->phone,
            'optometrist_name' => 'Dr. R. Menon',
            'optometrist_registration_no' => 'OPT/2019/4471',
            'vision_type' => 'progressive',
            'right_sph' => -2.25, 'right_cyl' => -0.75, 'right_axis' => 90, 'right_add' => 1.75,
            'left_sph' => -2.00, 'left_cyl' => -0.50, 'left_axis' => 85, 'left_add' => 1.75,
            'pd' => 63.0,
            'prescribed_on' => Carbon::today()->subDays(20)->toDateString(),
            'valid_until' => Carbon::today()->addYear()->toDateString(),
            'notes' => 'Reports glare on night driving. Anti-reflective coating advised.',
            'created_by' => $userId,
        ]);
    }

    /**
     * Today's diary: one patient already waiting, one slot that has come and
     * gone with nobody marked in, and one still to come this afternoon.
     */
    private function diary(Customer $customer, EyePrescription $prescription, int $userId): void
    {
        if (OpticalAppointment::query()->exists()) {
            return;
        }

        $today = Carbon::today();

        $rows = [
            [
                'patient_name' => $customer->name,
                'patient_phone' => $customer->phone,
                'customer_id' => $customer->id,
                'purpose' => 'eye_test',
                'status' => 'arrived',
                'arrived_at' => now()->subMinutes(12),
                'scheduled_at' => $today->copy()->setTime(10, 30),
                'duration_minutes' => 20,
                'notes' => 'Complains of headache reading. Bring previous pair.',
            ],
            [
                'patient_name' => 'Nilesh Trivedi',
                'patient_phone' => '9825012345',
                'purpose' => 'eye_test',
                'status' => 'booked',
                'scheduled_at' => $today->copy()->setTime(9, 15),
                'duration_minutes' => 20,
            ],
            [
                'patient_name' => 'Shaila Rao',
                'patient_phone' => '9820098765',
                'purpose' => 'collection',
                'status' => 'confirmed',
                'scheduled_at' => $today->copy()->setTime(17, 0),
                'duration_minutes' => 10,
                'eye_prescription_id' => $prescription->id,
                'notes' => 'Collecting for her father — check the name on the docket.',
            ],
        ];

        foreach ($rows as $row) {
            OpticalAppointment::create($row + [
                'appointment_number' => OpticalAppointment::nextNumber(),
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * The pair on the shelf: fitted nine days ago, nobody rung. This is the row
     * the collection desk was built for.
     */
    private function waitingPair(
        Customer $customer,
        EyePrescription $rx,
        Product $frame,
        Product $lens,
        int $userId,
    ): void {
        if (OpticalOrder::query()->where('status', 'fitted')->exists()) {
            return;
        }

        $order = OpticalOrder::create([
            'order_number' => OpticalOrder::nextNumber(),
            'customer_id' => $customer->id,
            'eye_prescription_id' => $rx->id,
            'frame_product_id' => $frame->id,
            'lens_product_id' => $lens->id,
            'status' => 'fitted',
            'vision_type' => 'progressive',
            'right_sph' => $rx->right_sph, 'right_cyl' => $rx->right_cyl,
            'right_axis' => $rx->right_axis, 'right_add' => $rx->right_add,
            'left_sph' => $rx->left_sph, 'left_cyl' => $rx->left_cyl,
            'left_axis' => $rx->left_axis, 'left_add' => $rx->left_add,
            'pd' => $rx->pd,
            'frame_price' => (float) $frame->selling_price,
            'lens_price' => (float) $lens->selling_price,
            'fitting_charge' => 150,
            'tax_percent' => 12,
            'promised_on' => Carbon::today()->subDays(11)->toDateString(),
            'ordered_at' => now()->subDays(16),
            'received_at' => now()->subDays(10),
            // Set directly rather than left to the observer: this row is created
            // already fitted, and the observer only stamps a *change* of status.
            'fitted_at' => now()->subDays(9),
            'lab_notes' => 'Ground at Sundaram Optical Lab, docket 88214.',
            'created_by' => $userId,
        ]);

        app(\App\Services\OpticalService::class)->reprice($order);
    }
}
