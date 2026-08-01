<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Distribution & Wholesale — the second industry plugin.
 *
 * A distributor does not really sell things; it sells *credit and reach*. The
 * goods are somebody else's brand, the margin is thin and fixed, and the only
 * two questions that decide whether the business survives are "how many shops
 * did we cover this week?" and "how much of last month's despatch is still
 * lying with the party?". Everything below follows from that: Salesman, Beat and
 * Route on one side of the ledger, Credit Limit and Outstanding on the other,
 * with Multi Warehouse and Transport as the plumbing between them, and Import &
 * Export where the trade buys from overseas rather than from a principal.
 *
 * Pharma distribution is deliberately *not* a trade here even though a stockist
 * is a distributor in every mechanical sense: the Healthcare vertical owns
 * `pharma_distributor`, because what makes that trade hard is the drug
 * regulation, not the credit. Trade keys are globally unique and declaring it in
 * two verticals would put two near-identical rows in the picker. The two screens
 * this plugin adds are gated on the Sales module, not on a trade, so a pharma
 * stockist reaches them exactly like everyone else.
 *
 * All five trades run on the same spine the ERP already has — sales orders,
 * warehouse stock, invoices, payments. What this vertical adds is a pair of
 * screens that spine never had, because a retail counter does not need them:
 *
 *  - Credit Control — every party's limit against live exposure (outstanding
 *    plus what is confirmed and not yet invoiced), with a manual supply hold.
 *  - Outstanding & Ageing — receivables in days-past-due buckets, sliced by
 *    salesman, beat and area, which is how a collection round is actually
 *    planned.
 *
 * Feature statuses are load-bearing, as in Food & Beverage: a capability is
 * 'available' only where a trade can genuinely reach it with the modules this
 * plugin pre-ticks. Where a trade simply does not do a thing — a cash & carry
 * counter does not import containers — the key is omitted rather than marked
 * planned, because "planned" is a promise and no such promise was made.
 */
class DistributionPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'distribution';
    }

    public function label(): string
    {
        return 'Distribution & Wholesale';
    }

    public function icon(): string
    {
        return 'pi pi-truck';
    }

    public function description(): string
    {
        return 'Wholesalers, FMCG distributors, C&F agents and super stockists, cash & carry, pharma and hardware distribution.';
    }

    /**
     * Modules every distribution trade runs on: `distribution` itself (credit
     * control and ageing — the vertical's own screens), it buys in bulk and sells
     * in bulk (sales/purchase/inventory), it lives on party ledgers (accounts),
     * it moves goods under an e-way bill (gst), and it gets the common core
     * (crm, ai).
     *
     * Note what is *not* here: `pos`. A wholesale desk raises an order against
     * a party's credit, it does not tender cash at a counter — only the cash &
     * carry trade below adds it.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['distribution', 'sales', 'purchase', 'inventory', 'accounts', 'gst', 'crm', 'ai'];
    }

    /**
     * The five capabilities every trade here shares, all of them already built.
     * Kept in one place because a distributor is a distributor: whatever else
     * differs, all six manage stock across godowns, extend credit, chase
     * outstanding and put goods on a lorry.
     *
     * @return array<string, string>
     */
    private function commonFeatures(): array
    {
        return [
            // Godowns, bin locations, stock transfers between them.
            'multi_warehouse' => 'available',
            // Per-party limit + live exposure + supply hold (this plugin).
            'credit_limit' => 'available',
            // Days-past-due ageing, salesman/beat/area-wise (this plugin).
            'outstanding' => 'available',
            // Transporters, LR/freight charges, vehicles and drivers.
            'transport' => 'available',
            // Quantity-slab and party-specific rates off the price-list master.
            'slab_pricing' => 'available',
            // Bulk despatch is where e-way bills actually bite.
            'e_way_bill' => 'available',
        ];
    }

    /**
     * Beat-based field selling: a salesman with a route, a visit plan and a
     * collection book. Only trades that send someone out get these.
     *
     * @return array<string, string>
     */
    private function fieldFeatures(): array
    {
        return [
            'salesman' => 'available',
            'route' => 'available',
            // Order booked on the doorstep, collection receipted against the
            // party's outstanding — both are Field Sales screens today.
            'collection' => 'available',
        ];
    }

    public function trades(): array
    {
        return [
            /**
             * The general bulk trader. This key already existed as a
             * hand-written preset in ShopProfiles; the plugin now owns it, the
             * same handover 'restaurant' went through when Food & Beverage
             * shipped. Its module set is deliberately unchanged from that
             * preset so no existing workspace's profile shifts underneath it.
             */
            'wholesale' => [
                'label' => 'Wholesale / Distribution',
                'icon' => 'pi pi-truck',
                'description' => 'Bulk orders, party credit, outstanding, transport and brokers',
                'modules' => [...$this->base(), 'broker', 'field_sales', 'logistics'],
                'features' => [
                    ...$this->commonFeatures(),
                    ...$this->fieldFeatures(),
                    // Many general wholesalers buy their own containers rather
                    // than lift from a principal, so the exim side is theirs.
                    'import_export' => 'available',
                    // Commission agents are how a lot of bulk trade is sourced.
                    'broker' => 'available',
                ],
            ],

            /**
             * The classic FMCG distributor: one or two principals, a fleet of
             * beats, and a month that is judged on coverage and collections.
             */
            'fmcg_distribution' => [
                'label' => 'FMCG Distributor',
                'icon' => 'pi pi-box',
                'description' => 'Beat-wise coverage, salesman order booking, credit control and collections',
                'modules' => [...$this->base(), 'field_sales', 'logistics', 'devices', 'workflow'],
                'features' => [
                    ...$this->commonFeatures(),
                    ...$this->fieldFeatures(),
                    // Cartons are scanned in and out at the godown door.
                    'barcode' => 'available',
                    /**
                     * A principal's scheme — 10+1, a quarterly slab rebate, a
                     * display allowance — is neither a discount on a line nor a
                     * price list: it is a claim raised against the principal
                     * after the fact, and nothing in the ERP models that yet.
                     */
                    'scheme' => 'planned',
                    // Stock loaded onto a van in the morning and reconciled at
                    // night is its own document; beats and visits are not it.
                    'van_sales' => 'planned',
                ],
            ],

            /**
             * C&F agent / super stockist: holds a principal's stock across
             * godowns and bills sub-distributors, so the whole business is
             * transfers, secondary billing and credit on other distributors.
             */
            'super_stockist' => [
                'label' => 'C&F Agent / Super Stockist',
                'icon' => 'pi pi-building',
                'description' => 'Principal stock across godowns, sub-distributor billing and credit',
                'modules' => [...$this->base(), 'field_sales', 'logistics', 'devices', 'workflow'],
                'features' => [
                    ...$this->commonFeatures(),
                    ...$this->fieldFeatures(),
                    'barcode' => 'available',
                    'scheme' => 'planned',
                    /**
                     * What the sub-distributor sold on to retail. It arrives as
                     * the sub-distributor's own file, not as a document this
                     * workspace raised, so there is nowhere to put it yet.
                     */
                    'secondary_sales' => 'planned',
                ],
            ],

            /**
             * Cash & carry: a wholesale counter where the buyer is a shopkeeper
             * with a GSTIN, pays on the spot and carries it away himself. POS at
             * wholesale rates — no beats, no lorry, and credit only by
             * exception, which is exactly why the limit still matters.
             */
            'cash_carry' => [
                'label' => 'Cash & Carry / Wholesale Counter',
                'icon' => 'pi pi-shopping-bag',
                'description' => 'Counter billing at wholesale slabs, GST B2B bills, spot payment',
                'modules' => [...$this->base(), 'pos', 'devices', 'loyalty'],
                'features' => [
                    ...$this->commonFeatures(),
                    'barcode' => 'available',
                    // The counter already refuses to book an unpaid balance past
                    // the party's limit (PosController).
                    'counter_billing' => 'available',
                    'scheme' => 'planned',
                ],
            ],

            /**
             * Hardware, sanitaryware, tiles, electricals, building material.
             * Sold by weight, length and box, delivered by the trader's own
             * lorry to a site, and very often imported directly.
             */
            'hardware_wholesale' => [
                'label' => 'Hardware & Building Material',
                'icon' => 'pi pi-wrench',
                'description' => 'Site deliveries, godown-wise stock, party credit and imports',
                'modules' => [...$this->base(), 'logistics', 'broker', 'field_sales', 'devices'],
                'features' => [
                    ...$this->commonFeatures(),
                    ...$this->fieldFeatures(),
                    'import_export' => 'available',
                    'broker' => 'available',
                    'barcode' => 'available',
                    // Weighbridge / counter scale adapters exist in Device
                    // Manager, which is what a cement or rod trade bills on.
                    'weight_scale' => 'available',
                ],
            ],
        ];
    }
}
