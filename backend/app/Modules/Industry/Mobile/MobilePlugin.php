<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile;

use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Models\InsurancePolicy;
use App\Modules\Industry\Mobile\Observers\ServiceJobHandsetObserver;

/**
 * Mobile — the handset vertical.
 *
 * A phone shop sells *units*, not quantities. It does not sell three Galaxy A55s; it
 * sells three specific handsets, and for the next two years every conversation about
 * one of them concerns that handset and no other — is it covered, how many EMIs are
 * left, what did we allow for the old one, has it been opened before. Four registers
 * hang off the handset and they are what this plugin ships.
 *
 * Why it is its own vertical rather than a trade of Electronics & Appliances, whose
 * unit register is otherwise the same shape: three of the four things a phone counter
 * lives on do not exist in that trade at all. Nobody insures a television against
 * being dropped, nobody part-exchanges a ceiling fan, and an appliance dealer's EMI
 * is a card swipe rather than a file the shop is asked about for a year afterwards.
 * Folding them in would have put a screen-insurance book and a buy-price grid in front
 * of every electrical counter that only wanted serial-backed warranty.
 *
 * What it deliberately does *not* ship:
 *
 *  - A warranty register. Core's `product_warranties` is already the register a claim
 *    is settled against, and a phone counter settles one the way a footwear counter
 *    does. The plugin adds a `handset_id` column so a card can name a specific IMEI,
 *    and nothing else.
 *  - A repair job book. A repair is a service job with a handset on it: same
 *    lifecycle, same spare-part issue, same billing through the sales spine.
 *  - Accessories, barcodes and QR labels. Those are the ordinary catalogue, inventory
 *    and label-printing modules doing what they already do. A cable is not a special
 *    kind of product.
 */
class MobilePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'mobile';
    }

    public function label(): string
    {
        return 'Mobile & Handsets';
    }

    public function icon(): string
    {
        return 'pi pi-mobile';
    }

    public function description(): string
    {
        return 'Handset shops selling on IMEI, with warranty and screen insurance, EMI files, part-exchange and a repair bench.';
    }

    /**
     * Teach two core models about handsets, without core knowing this plugin exists.
     *
     * The dependency has to point plugin → core, or a workspace that never bought this
     * vertical would still carry its classes. resolveRelationUsing is the seam;
     * ServiceJob::$pluginRelations tells the core controller which relations are safe
     * to eager-load, so with the plugin unregistered the columns are unused nullables
     * and nothing asks for a relation that does not exist.
     *
     * The relation names are prefixed rather than plain (`mobileHandset`, not
     * `handset`) because Automobile has already claimed `vehicle`/`warranty` and
     * Electronics `deviceUnit`/`deviceWarranty` on this same model. Two verticals
     * running side by side in one workspace — a shop that sells phones and repairs
     * laptops is not hypothetical — must not overwrite each other's relations.
     */
    public function boot(): void
    {
        ServiceJob::resolveRelationUsing(
            'mobileHandset',
            static fn (ServiceJob $job) => $job->belongsTo(Handset::class, 'handset_id'),
        );
        ServiceJob::resolveRelationUsing(
            'insurancePolicy',
            static fn (ServiceJob $job) => $job->belongsTo(InsurancePolicy::class, 'insurance_policy_id'),
        );

        ServiceJob::$pluginRelations['mobileHandset'] = 'mobileHandset';

        /**
         * A warranty card issued against a handset. The core register carries the
         * column this plugin added; the relation lets the warranty screens show which
         * handset a card names without core depending on this vertical.
         */
        ProductWarranty::resolveRelationUsing(
            'mobileHandset',
            static fn (ProductWarranty $warranty) => $warranty->belongsTo(Handset::class, 'handset_id'),
        );

        /**
         * Booking a repair against a policy is what uses a claim up, and finishing one
         * is what moves a handset's service clock. Both in an observer rather than in
         * this plugin's endpoints, because most repairs are opened and closed from the
         * ordinary Service screen — and a claim counter that only moves when this
         * plugin's own screens were used is a claim counter nobody can trust.
         */
        ServiceJob::observe(ServiceJobHandsetObserver::class);
    }

    public function trades(): array
    {
        return [
            'mobile_shop' => [
                'label' => 'Mobile Phone Shop',
                'icon' => 'pi pi-mobile',
                'description' => 'IMEI register, handset warranty and screen insurance, EMI files, part-exchange and repair job cards',
                /**
                 * `mobile` for the four registers, `warranty` because the cards live in
                 * core and the plugin only extends them, `service` for the repair bench
                 * (a job card *is* a service job — without it the claim tracking has
                 * nothing to attach to), `pos` for the accessories counter, `gst`
                 * because every handset invoice carries it, `loyalty` because this
                 * trade runs on repeat customers, and `devices` for the barcode scanner
                 * and label printer at the counter.
                 */
                'modules' => ['mobile', 'warranty', 'service', 'pos', 'gst', 'loyalty', 'devices'],
                'features' => [
                    // The registers this plugin ships.
                    'imei' => 'available',
                    'insurance' => 'available',
                    'emi' => 'available',
                    'buyback' => 'available',
                    /**
                     * Part-exchange *is* buyback: the old handset is valued, taken in as
                     * a credit and tendered against the new bill. Listed separately
                     * because the shop calls it exchange and would look for the word.
                     */
                    'exchange' => 'available',
                    // Core's warranty register, with a handset on the card.
                    'warranty' => 'available',
                    'extended_warranty' => 'available',
                    'warranty_claim' => 'available',
                    // Core's service module, with a handset and a policy on the job.
                    'repair_job_card' => 'available',
                    'spare_parts' => 'available',
                    // Core doing what it already does.
                    'serial_number' => 'available',
                    'accessories' => 'available',
                    'barcode' => 'available',
                    'qr_code' => 'available',
                    'label_printing' => 'available',
                    /**
                     * The counter's billing screen takes no serials — most of what
                     * crosses it is accessories, and asking for an IMEI on a ₹200 cable
                     * would make it unusable. A handset sale is therefore registered
                     * against its invoice from the handset screen, which is one extra
                     * step. Inline capture at the POS is not built.
                     */
                    'pos_imei_capture' => 'planned',
                    /**
                     * A claim against a policy is recorded and the repair is done. What
                     * is not built is billing the *insurer* for it: a surveyor approves
                     * a figure and the shop invoices the insurance company rather than
                     * the customer, which is a different document from anything here.
                     * The Automobile vertical declares the same gap.
                     */
                    'insurance_claim_settlement' => 'planned',
                ],
            ],
        ];
    }
}
