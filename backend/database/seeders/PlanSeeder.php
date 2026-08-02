<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The subscription catalogue (Volume 11 §"Subscription Plans"): Starter,
 * Business, Professional, Enterprise and Custom.
 *
 * **Each tier is a superset of the one below it.** `modules` is what a subscriber
 * may switch on, and EnabledModules narrows the stored selection to the plan on
 * every read — so a tier that omitted something the tier below sells would
 * silently switch it off for anyone who moved up. Business in particular is a
 * deliberate superset of the retired `growth` plan, because the 2026_08_02
 * migration repoints growth subscribers onto it.
 *
 * `feature_limits.max_*` are the caps EnforcePlanLimit meters; null means
 * unlimited. A single customer's cap can be bent without touching the catalogue —
 * see App\Support\TenantLimits and `tenants.limit_overrides`.
 *
 * Re-running this seeder rewrites the catalogue, prices included; it is the
 * source of truth for the shipped tiers. Prices tuned per-deployment belong in
 * the platform panel (Billing → Plans), where the change is audit-logged — and
 * will be overwritten by the next seed if they collide with a slug below.
 */
class PlanSeeder extends Seeder
{
    /**
     * Starter's module set — the counter-and-cash-book essentials.
     *
     * The first line is the Core ERP set (App\Support\Modules::CORE), which every
     * tier carries because no tier may price it out: Plan::allowedModules() unions
     * it in regardless of what is declared here. It is written out anyway so the
     * pricing page and this file agree about what Starter includes.
     *
     * loyalty / assets / workflow / devices used to be untagged menu rows, visible
     * to every package. They are switchable modules now, and they stay in every
     * plan's list so becoming switchable never reads as losing a feature.
     *
     * @var list<string>
     */
    private const STARTER_MODULES = [
        'sales', 'crm', 'purchase', 'inventory', 'accounts', 'ai',
        'pos', 'loyalty', 'assets', 'workflow', 'devices',
        /**
         * Warranty sits in Starter alongside loyalty, and for the same reason: a
         * single-counter footwear, watch or appliance shop is exactly the tenant
         * this tier is written for, and honouring a warranty is not an
         * enterprise activity. Pricing the claims register above the shops that
         * take claims every week would only push them back to a paper diary.
         */
        'warranty',
        /**
         * Grocery & Kirana is the one industry vertical in Starter, and it is
         * here rather than in Business on purpose: every other vertical was
         * priced at the tier its archetypal buyer sits in, and this trade's
         * archetypal buyer *is* Starter. A neighbourhood kirana with one counter
         * and a ₹40,000 monthly credit line is the smallest paying customer this
         * product has, and pricing combo packs and distributor credit above it
         * would sell the vertical to everyone except the shops it was written
         * for. It also costs the tier nothing extra to carry: `pos`, `loyalty`
         * and `devices` are already here, so the preset needs no other key.
         */
        'grocery',
        /**
         * Gift & Novelty sits in Starter on the same reasoning, and it is the same
         * size of business: one counter, one owner, and a year made in the six
         * weeks around Diwali. The module it needs costs the tier nothing extra —
         * `pos`, `loyalty` (which carries gift vouchers) and `devices` are already
         * here, so the preset adds no other key.
         *
         * Being in Starter is also why there is no Gift Pack in the marketplace:
         * every plan already carries the module, so a pack would price something
         * twice. If the vertical ever grows a heavier half (corporate hamper
         * contracts, a wrapping workshop), that is when to revisit this.
         */
        'gift',
        /**
         * Toys & Games, for the same reason and with one of its own. It is the
         * same size of business as the gift shop next door — one counter, one
         * owner, a year made at Diwali and in birthday season — and its preset
         * leans on `gift`, `pos`, `loyalty` and `devices`, all of which this tier
         * already carries.
         *
         * The reason of its own is that this vertical is a compliance register. A
         * toy sold on a lapsed BIS licence is an offence whatever the shop pays
         * for software, and pricing the thing that catches it above the smallest
         * shops would put it out of reach of precisely the ones with nobody
         * watching the dates. Same logic as warranty above: not an enterprise
         * activity. No marketplace pack, for the same reason as Gift — every plan
         * carries the module, so a pack would price it twice.
         */
        'toys',
        /**
         * Books & Periodicals, and this one is priced here on a different
         * argument from the three above it. It is not that a book shop is small —
         * many are — but that the module is *catalogue hygiene*, and a shop with
         * eight thousand titles and no ISBN discipline cannot reorder, cannot
         * match a distributor's file and cannot tell a customer on the phone
         * whether it has a book. That problem is worst at the smallest shops,
         * which have the most titles per member of staff and the least software.
         *
         * The preset also ticks `ecommerce`, which is a Professional key and
         * stays one: the online-order inbox is a genuinely bigger piece of
         * machinery than a catalogue, and the plan remains the ceiling. A Starter
         * book shop therefore gets its ISBN register, its authors and its
         * publishers, and sees the Online Orders row only once it buys the tier
         * that carries channels. That is a fair split rather than an accident —
         * and it is stated in the trade's own feature map so nobody is sold the
         * inbox twice.
         */
        'books',
    ];

    /**
     * Business adds the SMB back office and the single-trade verticals. Every key
     * the retired `growth` plan sold is here — see rule 1 above.
     *
     * @var list<string>
     */
    private const BUSINESS_MODULES = [
        ...self::STARTER_MODULES,
        'field_sales', 'broker', 'logistics', 'gst', 'hrms',
        'service', 'projects', 'pharmacy', 'jewellery', 'optical', 'restaurant',
        /**
         * `restaurant` carries the whole Food & Beverage vertical's counter side:
         * tables, KOT, the kitchen display, the menu card, combos, happy hours and
         * the token queue all gate on it. The trades that are not restaurants need
         * nothing extra — a bakery's cake orders ride on `sales`, a dairy's
         * collection on `purchase` and its round on `sales`, an ice-cream parlour's
         * freezers on `inventory`, and club cards on `loyalty` — all of which
         * Starter already sells.
         *
         * The one thing a Business subscriber does *not* get is Recipes for the
         * three producing trades (bakery, sweet mart, ice cream): those reach the
         * recipe book through Manufacturing → Production, and `manufacturing` is a
         * Professional module. That is the plan ceiling working as intended rather
         * than a gap — but it is the reason a bakery that wants food costing has to
         * be sold Professional, so change it deliberately if that is not the
         * intent.
         */
        // Agriculture's two halves are single-trade verticals like the rest: an
        // agro counter and a dairy farm are both SMBs, and pricing them above
        // Business would put the whole vertical out of reach of the shops it was
        // written for.
        'agriculture', 'livestock',
        // Healthcare's two, on the same reasoning: a two-room pathology lab and
        // a fifty-bed nursing home are SMBs, and the trades that need these are
        // exactly the ones that cannot pay for Professional.
        'diagnostics', 'hospital',
        // Automobile, likewise. A three-bay garage is the archetypal Business
        // subscriber, and its vertical is worth nothing without `service`
        // alongside — which this tier already sells.
        'automobile',
        // Electronics & Appliances, likewise. A mobile counter and a two-shop
        // appliance dealer are archetypal Business subscribers, and the vertical
        // is worth little without `service` and `logistics` beside it — both of
        // which this tier already sells.
        'electronics',
        // Computer & IT, likewise. A neighbourhood laptop shop with a bench and
        // two engineers is the archetypal Business subscriber, and the vertical
        // is hollow without `service` beside it — which this tier already sells.
        'computer_it',
        // Mobile, likewise — and this one is the archetype of the tier rather than
        // an example of it. A single-counter phone shop is the smallest business
        // that genuinely needs four registers, and the vertical is hollow without
        // `warranty` and `service` beside it, both of which this tier sells.
        'mobile',
        /**
         * Stationery, likewise — and with the sharpest version of the argument.
         * This trade earns most of its year in about ten weeks, and the module
         * that makes those weeks work is the one it can least afford in the nine
         * months either side. Pricing school supply above Business would sell it
         * only to the chains, which are precisely the shops that already cope.
         */
        'stationery',
        // Distribution & Wholesale sits here for the same reason and one more:
        // its whole value is credit control over `field_sales` beats, and both
        // are Business keys. Pricing it above would put party-credit discipline
        // out of reach of exactly the trade that goes under without it.
        'distribution',
        /**
         * Sports & Fitness sits in Business rather than Starter, and unlike its
         * neighbours the reason is not the size of the shop — it is that the trade
         * preset cannot work below this tier. Two of the seven things a sports
         * shop is sold are Repair and Warranty, and Repair is `service`, which is
         * a Business key. Pricing `sports` into Starter would sell a hire desk
         * beside a repair bullet with no bench behind it.
         *
         * The vertical is also a step up in what it manages. A hire fleet is
         * capital that leaves the premises: deposits held against other people's
         * money, kit promised for weekends it is already promised for, and a
         * utilisation report that decides what to buy next season. That is a shop
         * with an owner who looks at figures, which is what this tier is written
         * for.
         */
        'sports',
    ];

    /**
     * Professional adds multi-channel selling and production.
     *
     * `manufacturing` is the whole shop floor, not just BOMs: production runs,
     * the machine master with downtime and utilisation, quality inspections with
     * defect analysis, scrap and rejection, and the finished-cost breakdown. It
     * is what the eleven trades of the Manufacturing vertical are sold on, so a
     * factory that stops at Business gets none of it — which is why the
     * Manufacturing Pack exists in the marketplace for that tier.
     *
     * The garment chain (`tailoring`, `embroidery`) stays Enterprise-only or
     * pack-bought; `textile` is here because fabric rolls are stock handling as
     * much as production.
     *
     * @var list<string>
     */
    private const PROFESSIONAL_MODULES = [
        ...self::BUSINESS_MODULES,
        'ecommerce', 'manufacturing', 'textile',
    ];

    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price' => 999,
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 1,
                'feature_limits' => [
                    'max_warehouses' => 1,
                    'max_users' => 3,
                    'max_products' => 500,
                    'barcode' => false,
                    'reports' => false,
                    'api_access' => false,
                    'integrations' => false,
                    'support' => 'email',
                    // Onboarding §3: the module set a Starter tenant may enable.
                    // Keys come from App\Support\Modules::CATALOGUE.
                    'modules' => self::STARTER_MODULES,
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'price' => 2999, // matches the retired `growth` plan it replaces
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 2,
                'feature_limits' => [
                    'max_warehouses' => 5,
                    'max_users' => 15,
                    'max_products' => 10000,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => false,
                    'integrations' => false,
                    'support' => 'email',
                    'modules' => self::BUSINESS_MODULES,
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'price' => 5999,
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 3,
                'feature_limits' => [
                    'max_warehouses' => 15,
                    'max_users' => 50,
                    'max_products' => 50000,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => true,
                    'integrations' => true,
                    'support' => 'priority',
                    'modules' => self::PROFESSIONAL_MODULES,
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 11999,
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 4,
                'feature_limits' => [
                    // null = unlimited
                    'max_warehouses' => null,
                    'max_users' => null,
                    'max_products' => null,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => true,
                    'integrations' => true,
                    'support' => 'dedicated',
                    // '*' unlocks the whole catalogue, including the textile /
                    // tailoring / embroidery production chain.
                    'modules' => ['*'],
                ],
            ],
            [
                'slug' => 'custom',
                'name' => 'Custom',
                // Priced per deal: the public pricing page renders is_custom as
                // "Contact sales" rather than a number.
                'price' => 0,
                'billing_cycle' => 'monthly',
                'is_custom' => true,
                'sort_order' => 5,
                'feature_limits' => [
                    'max_warehouses' => null,
                    'max_users' => null,
                    'max_products' => null,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => true,
                    'integrations' => true,
                    'support' => 'dedicated',
                    'modules' => ['*'],
                ],
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::updateOrCreate(['slug' => $attributes['slug']], $attributes);
        }
    }
}
