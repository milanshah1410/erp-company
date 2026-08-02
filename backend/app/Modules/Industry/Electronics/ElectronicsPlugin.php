<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics;

use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use App\Modules\Industry\Electronics\Models\ServiceRequest;
use App\Modules\Industry\Electronics\Observers\ServiceJobDeviceObserver;

/**
 * Electronics & Appliances — the serial-number vertical.
 *
 * Five trades sit under it and they divide along one line: what happens after
 * the box leaves the shop. A mobile counter's aftersales is a carry-in repair; a
 * television showroom's is a van, a wall bracket and a fitter; an appliance
 * dealer's is a plumber on Thursday for something delivered on Tuesday; an IT
 * shop's is an AMC on forty machines in an office; an electrical counter's is a
 * customer arriving with a dead LED driver and a bill from eight months ago.
 *
 * What they all share is that the *unit* is the record everything hangs off. Two
 * customers buy the same television and from that moment only the serial tells
 * them apart — which one is covered, which one has been opened twice, which one
 * the extended plan was sold against. That is the same shape as Automobile's
 * vehicle register, and for the same reason: the person holding the receipt
 * changes, the machine does not.
 *
 * The plugin therefore ships two things core does not have — a serial/IMEI
 * register and the service-request desk — and deliberately does not ship a
 * warranty register, a job card, an AMC or a delivery run. Warranty is the core
 * `warranty` module's card, which was written for exactly these trades; a repair
 * is a service job with a unit on it; an AMC is core's ServiceContract, the same
 * document whether it covers a chiller or a photocopier; a delivery is the
 * Logistics module's dispatch. Re-implementing any of them would mean a second,
 * worse copy of claim settlement, parts issue, contract drawdown and proof of
 * delivery.
 *
 * What it does do to warranty is *extend* it: `product_warranties` gains a unit
 * link and the parts/labour/onsite scope this trade argues about, so one register
 * serves the shoe shop and the appliance dealer without either pretending to be
 * the other.
 */
class ElectronicsPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'electronics';
    }

    public function label(): string
    {
        return 'Electronics & Appliances';
    }

    public function icon(): string
    {
        return 'pi pi-desktop';
    }

    public function description(): string
    {
        return 'Electronics showrooms, mobile counters, computer shops, home-appliance dealers and electrical goods stores.';
    }

    /**
     * Teach the core service job about units, without core knowing this plugin
     * exists.
     *
     * The dependency has to point plugin → core, or a workspace that never
     * bought Electronics would still carry its classes. resolveRelationUsing is
     * the seam: the relations are attached at boot, and ServiceJob::$pluginRelations
     * tells the core controller which are safe to eager-load — so with the plugin
     * unregistered the columns are unused nullables and nothing tries to load
     * anything.
     *
     * The relation names are prefixed rather than plain (`deviceWarranty`, not
     * `warranty`) because Automobile has already claimed `vehicle`, `booking` and
     * `warranty` on this same model. Two verticals running side by side in one
     * workspace — a shop that repairs both cars and car stereos is not a
     * hypothetical — must not overwrite each other's relations.
     */
    public function boot(): void
    {
        ServiceJob::resolveRelationUsing(
            'deviceUnit',
            static fn (ServiceJob $job) => $job->belongsTo(DeviceUnit::class, 'device_unit_id'),
        );
        ServiceJob::resolveRelationUsing(
            'serviceRequest',
            static fn (ServiceJob $job) => $job->belongsTo(ServiceRequest::class, 'service_request_id'),
        );
        ServiceJob::resolveRelationUsing(
            'deviceWarranty',
            static fn (ServiceJob $job) => $job->belongsTo(ProductWarranty::class, 'device_warranty_id'),
        );

        /**
         * And the other direction: a warranty card knows which machine it was
         * issued against. Registered here rather than declared on the core model
         * for the usual reason — ProductWarranty must not import a plugin class,
         * or a workspace that never bought Electronics would carry it anyway.
         */
        ProductWarranty::resolveRelationUsing(
            'unit',
            static fn (ProductWarranty $w) => $w->belongsTo(DeviceUnit::class, 'unit_id'),
        );

        ServiceJob::$pluginRelations['deviceUnit'] = 'deviceUnit';

        // Finishing a job is what updates a unit's service record and closes the
        // request behind it. Doing it in an observer rather than in this
        // plugin's own endpoints is the only way it also happens when the job is
        // closed from the ordinary Service screen — which is where a shop
        // running both will usually close it.
        ServiceJob::observe(ServiceJobDeviceObserver::class);
    }

    /**
     * What every electronics trade adds on top of the Core ERP set: a counter
     * (pos) and GST billing. Selling, buying, stocking and keeping books are
     * core, merged in by ShopProfiles::all().
     *
     * `electronics`, `warranty`, `service`, `logistics` and `devices` are added
     * per trade — an electrical goods counter has no workshop and no delivery
     * van, though it very much has warranty claims.
     *
     * `warranty` is on every trade here and is not this plugin's own module: it
     * is core's warranty-card register, which the plugin extends with a unit link
     * and a parts/labour scope rather than duplicating. A trade that declared
     * `electronics` without it would get a unit register that could not answer
     * the only question the register exists for.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['pos', 'gst'];
    }

    public function trades(): array
    {
        return [
            'electronics_shop' => [
                'label' => 'Electronics Showroom',
                'icon' => 'pi pi-desktop',
                'description' => 'Televisions and audio by serial number, with warranty, installation, service requests, AMC and delivery',
                'modules' => [...$this->base(), 'electronics', 'warranty', 'service', 'logistics', 'devices', 'loyalty'],
                'features' => [
                    'serial_register' => 'available',
                    'warranty' => 'available',
                    'extended_warranty' => 'available',
                    'service_request' => 'available',
                    'installation' => 'available',
                    'job_card' => 'available',
                    'spare_parts' => 'available',
                    'amc' => 'available',
                    'barcode' => 'available',
                    // The Logistics module's dispatch plans and proof of
                    // delivery. The request desk schedules the visit; the van is
                    // planned there, and neither is re-implemented here.
                    'delivery' => 'available',
                    /**
                     * The POS exchange: the old unit comes back on a sales return
                     * and the replacement goes out on the same bill, settling the
                     * difference in one tender.
                     */
                    'exchange' => 'available',
                    /**
                     * Not the same thing as the exchange above, and not built:
                     * an "exchange offer" in this trade means valuing a customer's
                     * ten-year-old television as a discount on a new one. That is
                     * a valuation grid and a second-hand stock item, and nothing
                     * models either.
                     */
                    'buyback_valuation' => 'planned',
                ],
            ],

            /**
             * `mobile_shop` and `computer_shop` are deliberately absent, even
             * though a showroom's aftersales is the same shape as theirs. Both
             * are owned by dedicated verticals — the Mobile plugin (IMEI register,
             * protection plans) and Computer & IT (customer assets, service
             * calls, licence keys) — and trade keys are globally unique, so a copy
             * here would silently shadow whichever plugin registered later and
             * strand those verticals' screens behind a module nobody enabled.
             */
            'home_appliance' => [
                'label' => 'Home Appliance & Kitchenware',
                'icon' => 'pi pi-home',
                'description' => 'Delivery and installation scheduling, warranty, AMC and onsite service visits',
                'modules' => [...$this->base(), 'electronics', 'warranty', 'service', 'logistics', 'devices', 'loyalty'],
                'features' => [
                    'serial_register' => 'available',
                    'warranty' => 'available',
                    'extended_warranty' => 'available',
                    'service_request' => 'available',
                    // The trade this matters most to: a washing machine is
                    // delivered on Tuesday and plumbed in on Thursday, and the
                    // gap between the two is the commonest complaint there is.
                    'installation' => 'available',
                    'job_card' => 'available',
                    'spare_parts' => 'available',
                    'amc' => 'available',
                    'delivery' => 'available',
                    'barcode' => 'available',
                    'exchange' => 'available',
                    'buyback_valuation' => 'planned',
                ],
            ],

            'electrical_shop' => [
                'label' => 'Electrical Goods Shop',
                'icon' => 'pi pi-bolt',
                'description' => 'Counter sales of fittings and fixtures, with serial-backed brand warranty',
                // No `service` module: a counter selling switches, wire and LED
                // fittings has no workshop behind it. It still runs `electronics`
                // for the unit and warranty registers, which is how it answers
                // "is this driver still covered?" eight months after the sale.
                'modules' => [...$this->base(), 'electronics', 'warranty', 'devices'],
                'features' => [
                    'serial_register' => 'available',
                    'warranty' => 'available',
                    'barcode' => 'available',
                    // Replacement across the counter, which is how a brand
                    // warranty on a fitting is actually settled.
                    'exchange' => 'available',
                    /**
                     * Watt, voltage and IP rating as searchable attributes rather
                     * than free text in the product name. Reachable today through
                     * Dynamic Custom Fields, but not as a first-class spec sheet
                     * the counter can filter on, so it is not claimed as built.
                     */
                    'electrical_specs' => 'planned',
                ],
            ],
        ];
    }
}
