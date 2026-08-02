<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery;

use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Stationery\Models\ClassKit;
use App\Modules\Industry\Stationery\Models\Institution;
use App\Modules\Industry\Stationery\Observers\SchoolQuotationObserver;

/**
 * Stationery & School Supplies — the shop whose year is ten weeks long.
 *
 * `.claude/shops-modules.md` §"📝 10. Stationery Shop": Bulk Sales, School
 * Orders, Barcode, Purchase, Inventory, GST Billing.
 *
 * Four of those six are core and stay core. What makes this trade different is
 * not that it sells pens — it is that between April and June it stops being a
 * retail shop at all and becomes a supplier to *institutions*, and an institution
 * does not buy things. It publishes a list, appoints a vendor and sends four
 * hundred parents to fetch it. Every hard problem in the trade follows from that:
 *
 *  - The order is placed by someone who is not the payer. The school writes the
 *    list; the parents pay, one at a time, over a counter, in ten weeks.
 *  - The list is the product. "Class 5" is twenty-two lines, three of which are
 *    out of stock in June, one of which is optional and one of which the school
 *    calls something different from the shop.
 *  - Getting the quantity wrong is fatal in both directions. Short in May means
 *    turning away the customers who pay for the year; long in July means a godown
 *    of Class 7 books nobody will ever ask for again.
 *
 * So the plugin ships two registers and nothing else: the institutions, and the
 * booklists they publish. Everything downstream is deliberately core:
 *
 *  - **Bulk Sales already exists.** Quantity slabs are `price_lists.min_quantity`
 *    and are resolved by `Customer::priceFor()` — a four-hundred-set school gets
 *    the bulk rate through the same code path the counter uses. A slab table here
 *    would be a second opinion the POS never asked for.
 *  - **A school order is a Sales Order** on the `institution` channel, with the
 *    school, the list and their purchase-order number on it. Picking, phased
 *    delivery, invoicing and the receivable are all things that work already.
 *  - **A tender is a Sales Quotation**, with send/accept/convert behind it.
 *  - Barcode, purchase, inventory and GST are the `devices`, `purchase`,
 *    `inventory` and `gst` modules, untouched.
 */
class StationeryPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'stationery';
    }

    public function label(): string
    {
        return 'Stationery & School Supplies';
    }

    public function icon(): string
    {
        return 'pi pi-pencil';
    }

    public function description(): string
    {
        return 'Stationery and book shops: school and institution accounts, class booklists, bulk quantity pricing and term-wise supply orders.';
    }

    /**
     * Teach the two core sales documents about schools, without core knowing this
     * plugin exists.
     *
     * The dependency has to point plugin → core, or a workspace that never bought
     * Stationery would still carry its classes. resolveRelationUsing is the seam
     * for exactly that: with the plugin unregistered the `stn_` columns are unused
     * nullables and nothing asks for a relation that is not there.
     *
     * Relation names are prefixed `stationery*` on purpose. Two verticals can be
     * registered in one workspace, and an unqualified `institution` on SalesOrder
     * is the kind of name a later plugin would take for a hospital or a college
     * without either of them noticing.
     */
    public function boot(): void
    {
        SalesOrder::resolveRelationUsing(
            'stationeryInstitution',
            static fn (SalesOrder $order) => $order->belongsTo(Institution::class, 'stn_institution_id'),
        );
        SalesOrder::resolveRelationUsing(
            'stationeryClassKit',
            static fn (SalesOrder $order) => $order->belongsTo(ClassKit::class, 'stn_class_kit_id'),
        );
        SalesQuotation::resolveRelationUsing(
            'stationeryInstitution',
            static fn (SalesQuotation $quote) => $quote->belongsTo(Institution::class, 'stn_institution_id'),
        );
        SalesQuotation::resolveRelationUsing(
            'stationeryClassKit',
            static fn (SalesQuotation $quote) => $quote->belongsTo(ClassKit::class, 'stn_class_kit_id'),
        );

        /**
         * Converting a tender into an order is the one hop where a school's
         * identity would otherwise be dropped, and it usually happens on the
         * ordinary Quotations screen rather than on any screen of ours — which is
         * why this is an observer and not a step in a service.
         */
        SalesQuotation::observe(SchoolQuotationObserver::class);
    }

    /**
     * What this trade adds on top of the Core ERP set. Selling, buying, stocking
     * and keeping books are core and merged in by ShopProfiles::all().
     *
     * @return array<string, array<string, mixed>>
     */
    public function trades(): array
    {
        return [
            'stationery_shop' => [
                'label' => 'Stationery Shop',
                'icon' => 'pi pi-pencil',
                'description' => 'School and office stationery: institution accounts, class booklists, bulk quantity pricing, school supply orders and counter billing',
                /**
                 * `devices` carries the barcode scanner and the label printer,
                 * which this trade lives on — a counter serving a queue of
                 * parents in June cannot search for products by name. `pos` is
                 * the counter itself; `gst` is the billing the list names.
                 * `logistics` because a school's order is delivered by the van
                 * in phases, and `loyalty` because the same parents come back
                 * every term for refills.
                 */
                'modules' => ['stationery', 'pos', 'gst', 'devices', 'logistics', 'loyalty'],
                'features' => [
                    // The school as an account, with its board, session, roll and
                    // the concession it negotiated for parents.
                    'school_accounts' => 'available',
                    // The class booklist, and everything it explodes into.
                    'class_booklists' => 'available',
                    // A Sales Order on the `institution` channel, carrying the
                    // school's own purchase-order reference.
                    'school_orders' => 'available',
                    // Tender quotations, on the core quotation document.
                    'tender_quotations' => 'available',
                    /**
                     * Quantity slabs, resolved by Customer::priceFor at the
                     * quantity actually being bought. Core, and reachable under
                     * this trade's module set via Price Lists.
                     */
                    'bulk_pricing' => 'available',
                    // Rolling the whole season's published lists up into one
                    // buying list, netted against stock.
                    'season_demand' => 'available',
                    'barcode' => 'available',
                    'purchase' => 'available',
                    'inventory' => 'available',
                    'gst_billing' => 'available',
                    /**
                     * A parent who paid for a set and went home with nineteen of
                     * twenty-two items is owed the rest, and the slip they are
                     * handed is not modelled — the shortfall is reported at the
                     * time of sale but nothing tracks the promise afterwards.
                     */
                    'pending_item_slips' => 'planned',
                    /**
                     * Printing a school's list as a priced, branded booklist to
                     * hand parents at the gate. The data is all here; the
                     * document is not.
                     */
                    'printed_booklists' => 'planned',
                ],
            ],
        ];
    }
}
