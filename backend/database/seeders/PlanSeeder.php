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
    ];

    /**
     * Professional adds multi-channel selling and light production.
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
