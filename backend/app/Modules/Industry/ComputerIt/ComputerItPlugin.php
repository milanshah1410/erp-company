<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt;

use App\Models\ServiceJob;
use App\Modules\Industry\ComputerIt\Models\AssetWarranty;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use App\Modules\Industry\ComputerIt\Models\ServiceCall;
use App\Modules\Industry\ComputerIt\Observers\ServiceJobAssetObserver;
use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Computer & IT — the shop that keeps selling after the sale.
 *
 * `.claude/shops-modules.md` §"💻 4. Computer / Laptop Shop": Serial Number,
 * Warranty, AMC, Repair, Accessories, License Keys, Asset Tracking, Barcode,
 * Service Calls.
 *
 * What makes this trade different from ordinary retail is that the sale is the
 * *beginning* of the relationship. A kirana shop's transaction is over when the
 * customer leaves; a computer shop's is over three years later, after four
 * on-site visits, a replaced SSD, an argument about whether the spill is covered
 * and a Windows key that has to be moved onto the replacement machine. Every one
 * of those events is about a specific machine, so the machine is the record and
 * the customer hangs off it.
 *
 * The plugin therefore ships four registers core does not have — the install
 * base, warranty with a service level, licence keys, and the call book — and
 * deliberately does **not** ship a repair job, an AMC or a barcode. Those exist:
 *
 *  - Repair *is* a ServiceJob (the `service` module) with a machine on it. Same
 *    lifecycle, same spare-part issue from stock, same billing through the sales
 *    spine. A second job book would be a worse copy of both.
 *  - AMC *is* a ServiceContract, which already carries a term, a visit quota and
 *    the rule that jobs booked under it are worked and not billed. The plugin
 *    only records which machine sits on which contract.
 *  - Serial tracking and barcode/label printing are the `inventory` and
 *    `devices` modules, unchanged.
 *  - Accessories are products.
 *
 * That leaves the plugin owning exactly the things a general ERP has no reason
 * to know: that a laptop has a MAC address, that a warranty can be carry-in or
 * next-business-day on-site, that a licence key is worth money exactly once, and
 * that most support calls never become bench work.
 */
class ComputerItPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'computer_it';
    }

    public function label(): string
    {
        return 'Computer & IT';
    }

    public function icon(): string
    {
        return 'pi pi-desktop';
    }

    public function description(): string
    {
        return 'Computer and laptop shops: machines sold by serial, warranty and AMC cover, repairs, licence keys and the on-site call book.';
    }

    /**
     * Teach the core service job about customer machines, without core knowing
     * this plugin exists.
     *
     * The dependency has to point plugin → core, or a workspace that never
     * bought Computer & IT would still carry its classes. resolveRelationUsing
     * is the seam for exactly this: the relations are attached at boot, and
     * ServiceJob::$pluginRelations tells the core controller which of them are
     * safe to eager-load — so with the plugin unregistered the columns are
     * unused nullables and nothing asks for a relation that does not exist.
     */
    public function boot(): void
    {
        ServiceJob::resolveRelationUsing(
            'itAsset',
            static fn (ServiceJob $job) => $job->belongsTo(CustomerAsset::class, 'it_asset_id'),
        );
        ServiceJob::resolveRelationUsing(
            'itServiceCall',
            static fn (ServiceJob $job) => $job->belongsTo(ServiceCall::class, 'it_service_call_id'),
        );
        ServiceJob::resolveRelationUsing(
            'itWarranty',
            static fn (ServiceJob $job) => $job->belongsTo(AssetWarranty::class, 'it_warranty_id'),
        );

        ServiceJob::$pluginRelations['itAsset'] = 'itAsset';

        /**
         * Finishing a job is what services a machine and puts it back on its
         * owner's desk. Doing it in an observer rather than in this plugin's own
         * endpoints is the only way it also happens when the job is closed from
         * the ordinary Service screen — which is where a shop running both the
         * counter and the support desk will often close it.
         */
        ServiceJob::observe(ServiceJobAssetObserver::class);
    }

    /**
     * What this trade adds on top of the Core ERP set. Selling, buying, stocking
     * and keeping books are core and merged in by ShopProfiles::all().
     *
     * @return list<string>
     */
    public function trades(): array
    {
        return [
            'computer_shop' => [
                'label' => 'Computer / Laptop Shop',
                'icon' => 'pi pi-desktop',
                'description' => 'Machines sold by serial, warranty and AMC cover, repairs, licence keys, accessories and on-site service calls',
                /**
                 * `service` is not optional here the way it is for an automobile
                 * parts counter: a computer shop without a repair bench is not a
                 * computer shop, it is a box mover. `devices` carries the barcode
                 * scanner and the label printer that put an asset tag on a
                 * chassis; `hrms` is what makes the engineers assignable to a
                 * call at all.
                 */
                'modules' => ['computer_it', 'service', 'pos', 'gst', 'devices', 'hrms', 'loyalty'],
                'features' => [
                    // Core inventory tracks serials into and out of stock; the
                    // plugin's asset register is what keeps them afterwards.
                    'serial_number' => 'available',
                    'warranty' => 'available',
                    // The core service module's contract, with the machines that
                    // sit on it recorded against it.
                    'amc' => 'available',
                    'repair' => 'available',
                    'accessories' => 'available',
                    'license_keys' => 'available',
                    'asset_tracking' => 'available',
                    'barcode' => 'available',
                    'service_calls' => 'available',
                    /**
                     * Returning a dead part to the manufacturer and chasing the
                     * credit is a purchase-side document with its own ageing and
                     * its own argument. Nothing models it — the shop can raise a
                     * stock return, but the RMA reference, the courier and the
                     * replacement's serial are not tracked.
                     */
                    'rma' => 'planned',
                    /**
                     * Remote-support sessions are logged as calls of type
                     * `remote`, but there is no integration that starts one or
                     * records what was done inside it.
                     */
                    'remote_support' => 'planned',
                ],
            ],
        ];
    }
}
