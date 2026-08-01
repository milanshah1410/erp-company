<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile;

use App\Models\ServiceJob;
use App\Modules\Industry\Automobile\Models\CustomerVehicle;
use App\Modules\Industry\Automobile\Models\VehicleBooking;
use App\Modules\Industry\Automobile\Models\VehicleWarranty;
use App\Modules\Industry\Automobile\Observers\ServiceJobVehicleObserver;
use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Automobile — the workshop vertical.
 *
 * Five trades sit under it and they divide along one line: who touches the
 * vehicle. A service centre and a two-wheeler workshop take custody of it and
 * hand it back repaired; a dealership sells it; a parts counter and a tyre shop
 * sell things that go onto it. What they all share is that the *vehicle* is the
 * record everything hangs off — the same owner brings two cars, the same car
 * changes hands and keeps its history, and every argument in the trade ("is
 * this still covered?", "when was the last service?") is settled against the
 * vehicle rather than the person holding the keys.
 *
 * The plugin therefore ships three things core does not have — a VIN register,
 * an appointment book and a warranty register — and deliberately does *not*
 * ship a job card. A job card is a service job with a vehicle on it: same
 * lifecycle, same spare-part issue from stock, same billing through the sales
 * spine. Re-implementing that would mean a second, worse copy of parts issue
 * and invoicing, so instead the plugin adds its columns to `service_jobs` and
 * teaches the core model about them at boot.
 */
class AutomobilePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'automobile';
    }

    public function label(): string
    {
        return 'Automobile';
    }

    public function icon(): string
    {
        return 'pi pi-car';
    }

    public function description(): string
    {
        return 'Service centres, two-wheeler workshops, dealerships, spare-part counters and tyre shops.';
    }

    /**
     * Teach the core service job about vehicles, without core knowing this
     * plugin exists.
     *
     * The dependency has to point plugin → core, or a workspace that never
     * bought Automobile would still carry its classes. resolveRelationUsing is
     * the seam for exactly this: the relations are attached at boot, and
     * ServiceJob::$pluginRelations tells the core controller which of them are
     * safe to eager-load — so with the plugin unregistered the column is simply
     * an unused nullable and nothing tries to load anything.
     */
    public function boot(): void
    {
        ServiceJob::resolveRelationUsing(
            'vehicle',
            static fn (ServiceJob $job) => $job->belongsTo(CustomerVehicle::class, 'vehicle_id'),
        );
        ServiceJob::resolveRelationUsing(
            'booking',
            static fn (ServiceJob $job) => $job->belongsTo(VehicleBooking::class, 'booking_id'),
        );
        ServiceJob::resolveRelationUsing(
            'warranty',
            static fn (ServiceJob $job) => $job->belongsTo(VehicleWarranty::class, 'warranty_id'),
        );

        ServiceJob::$pluginRelations['vehicle'] = 'vehicle';

        // Finishing a job is what moves a vehicle's service clock forward. Doing
        // it in an observer rather than in the plugin's own endpoints is the
        // only way it also happens when the job is completed from the ordinary
        // Service screen — which is where a workshop that runs both trades will
        // often close it.
        ServiceJob::observe(ServiceJobVehicleObserver::class);
    }

    /**
     * Modules every automobile trade runs on: it sells (sales/pos), it buys and
     * stocks parts (purchase/inventory), it bills under GST, and it gets the
     * common core (crm, ai). `service` and `automobile` are added per trade —
     * a parts counter has no workshop behind it.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'crm', 'ai'];
    }

    public function trades(): array
    {
        return [
            'auto_service' => [
                'label' => 'Car Service Centre / Garage',
                'icon' => 'pi pi-car',
                'description' => 'Vehicle register, service bookings, job cards, spare parts and warranty claims',
                'modules' => [...$this->base(), 'automobile', 'service', 'hrms', 'devices'],
                'features' => [
                    'vin' => 'available',
                    'booking' => 'available',
                    'job_card' => 'available',
                    'service' => 'available',
                    'spare_parts' => 'available',
                    'warranty' => 'available',
                    'amc' => 'available',
                    'technician' => 'available',
                    // Insurance-company billing is a different document: a
                    // surveyor approves a figure and the shop bills the insurer,
                    // not the owner. Not built.
                    'insurance_claim' => 'planned',
                ],
            ],

            'two_wheeler' => [
                'label' => 'Two-Wheeler Workshop',
                'icon' => 'pi pi-compass',
                'description' => 'Bike and scooter servicing with free-service coupons, parts and warranty',
                'modules' => [...$this->base(), 'automobile', 'service', 'loyalty'],
                'features' => [
                    'vin' => 'available',
                    'booking' => 'available',
                    'job_card' => 'available',
                    'service' => 'available',
                    'spare_parts' => 'available',
                    'warranty' => 'available',
                    // The manufacturer's booklet of three free services is a
                    // prepaid entitlement, closest in shape to an AMC — but it
                    // is issued by the maker, not sold by the shop, and nothing
                    // models that yet.
                    'free_service_coupon' => 'planned',
                ],
            ],

            'auto_dealer' => [
                'label' => 'Vehicle Dealership',
                'icon' => 'pi pi-building',
                'description' => 'Vehicle stock by VIN, bookings, delivery, and the service centre behind it',
                'modules' => [...$this->base(), 'automobile', 'service', 'field_sales', 'hrms', 'loyalty'],
                'features' => [
                    // A dealer's stock vehicle is registered before it has an
                    // owner or a number plate — the VIN is the whole identity.
                    'vin' => 'available',
                    'booking' => 'available',
                    'job_card' => 'available',
                    'service' => 'available',
                    'spare_parts' => 'available',
                    'warranty' => 'available',
                    // Selling a vehicle bills through the ordinary sales spine;
                    // what does not exist is the RTO/registration paperwork
                    // trail and the exchange valuation that go with it.
                    'exchange' => 'planned',
                    'rto_registration' => 'planned',
                    'vehicle_finance' => 'planned',
                ],
            ],

            'spare_parts' => [
                'label' => 'Spare Parts & Accessories',
                'icon' => 'pi pi-cog',
                'description' => 'Counter sales of parts by vehicle fitment, with part warranty registration',
                // No `service` module: a parts counter has no workshop behind
                // it. It still runs `automobile` for the vehicle and warranty
                // registers, which is how a counter answers "will this fit?"
                // and "is this battery still covered?".
                'modules' => [...$this->base(), 'automobile', 'devices', 'loyalty'],
                'features' => [
                    'vin' => 'available',
                    'spare_parts' => 'available',
                    'warranty' => 'available',
                    'barcode' => 'available',
                    // Which part fits which make/model/year. A genuine
                    // catalogue, not a product attribute — it is many-to-many
                    // and it is the parts trade's whole skill.
                    'fitment_catalogue' => 'planned',
                ],
            ],

            'tyre_battery' => [
                'label' => 'Tyre & Battery Shop',
                'icon' => 'pi pi-circle',
                'description' => 'Fitment jobs, serial-tracked tyres and batteries, and pro-rata warranty',
                'modules' => [...$this->base(), 'automobile', 'service', 'devices'],
                'features' => [
                    'vin' => 'available',
                    'job_card' => 'available',
                    'spare_parts' => 'available',
                    'warranty' => 'available',
                    // Serial tracking exists in advanced inventory, which is how
                    // a specific battery is traced back to its warranty.
                    'serial_tracking' => 'available',
                    // A tyre warranty settles as a share of the price based on
                    // tread left, not as a free replacement. That is a credit
                    // calculation nothing here performs.
                    'pro_rata_claim' => 'planned',
                ],
            ],
        ];
    }
}
