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
     * loyalty / assets / workflow / devices / ai used to be untagged menu rows,
     * visible to every package. They are switchable modules now, and they stay in
     * every plan's list so becoming switchable never reads as losing a feature.
     *
     * @var list<string>
     */
    private const STARTER_MODULES = [
        'sales', 'pos', 'purchase', 'inventory', 'accounts',
        'loyalty', 'assets', 'workflow', 'devices', 'ai',
    ];

    /**
     * Business adds the SMB back office and the single-trade verticals. Every key
     * the retired `growth` plan sold is here — see rule 1 above.
     *
     * @var list<string>
     */
    private const BUSINESS_MODULES = [
        ...self::STARTER_MODULES,
        'crm', 'field_sales', 'broker', 'logistics', 'gst', 'hrms',
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
        // Distribution & Wholesale sits here for the same reason and one more:
        // its whole value is credit control over `field_sales` beats, and both
        // are Business keys. Pricing it above would put party-credit discipline
        // out of reach of exactly the trade that goes under without it.
        'distribution',
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
