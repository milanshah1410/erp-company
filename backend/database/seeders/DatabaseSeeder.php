<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\MarketplaceItem;
use App\Models\OnboardingProgress;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Development seed for the shared-database, single-tenant-per-user model.
 *
 * Seeds the platform-global data (plans + the shared role/permission set with
 * tenant_id = NULL), then provisions one ready-to-use dev tenant with an admin
 * and baseline data — mirroring what App\Jobs\ProvisionTenant does at signup.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Platform-global data (no tenant).
        $this->call(PlanSeeder::class);

        // The industry catalogue (Volume 11 §"Industry Templates") — seeded
        // before the dev tenant so its industry/business type resolve.
        $this->call(IndustryTemplateSeeder::class);

        // The dynamic navigation catalogue (project-stack.md §3) is global too
        // (tenant_id NULL), shared by every tenant like the permission set.
        $this->call(MenuSeeder::class);

        // Shop-wise menu templates (.claude/shops-modules.md) — the trade layer
        // between that catalogue and a workspace's own menu. Must run *after*
        // MenuSeeder: a template names catalogue routes and copies their gates,
        // so it has nothing to resolve against until the catalogue exists.
        // Seeds only trades that have no template yet, so it never stomps a
        // menu an operator has curated at /platform/shop-menus.
        $this->call(ShopMenuSeeder::class);

        // Global roles/permissions live at tenant_id = NULL and are shared by
        // every tenant; seed them with no active team.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->call(RolePermissionSeeder::class);

        // SaaS-owner RBAC (Level 1): platform operators, roles & permissions on
        // the `central` guard, plus a bootstrap Super Admin. Manages its own
        // (NULL) team context internally.
        $this->call(CentralRolePermissionSeeder::class);

        // The marketplace catalogue (Volume 11 §"Integration Marketplace" /
        // §"Device Marketplace") — one row per integration and device offering,
        // all on sale by default. The panel re-syncs on open, so this only makes
        // a fresh install deterministic rather than being the sole source.
        MarketplaceItem::syncCatalogue();

        // The module marketplace (Volume 11 §"Module Marketplace") — Industry,
        // Feature and AI packs. Seeded after PlanSeeder because each pack declares
        // the plan slugs that bundle it.
        $this->call(PlatformPackSeeder::class);

        // 2. The dev tenant — seeded active (not on a trial) so it can never be
        //    swept up by the daily lapse/suspension processing.
        $tenant = Tenant::updateOrCreate(
            ['email' => 'admin@demo.test'],
            [
                'company_name' => 'Demo Company',
                'status' => 'active',
                'suspended_by' => null,
                // Industry / trade, mirroring what the wizard records at signup.
                'industry' => 'retail',
                'business_type' => 'garment',
            ],
        );

        // 3. Tenant-scoped data: admin user + baseline, inside the tenant context.
        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            $admin = User::updateOrCreate(
                ['email' => 'admin@demo.test'],
                [
                    'name' => 'System Administrator',
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ],
            );
            $admin->syncRoles(['super-admin']);

            (new TenantDatabaseSeeder)->run();

            // Give the admin access + an active company/branch context.
            $company = Company::where('code', 'MAIN')->first();
            $branch = $company?->branches()->where('code', 'HO')->first();
            if ($company && $branch) {
                $admin->companies()->syncWithoutDetaching([$company->id]);
                $admin->branches()->syncWithoutDetaching([$branch->id]);
                $admin->update([
                    'current_company_id' => $company->id,
                    'current_branch_id' => $branch->id,
                ]);
            }
        });

        // 4. Landlord records (explicit tenant_id, no tenant scope).
        //    Unlimited subscription: Enterprise plan (null feature limits =
        //    unlimited) as an active sub with NO current_period_end — the
        //    subscriptions:process lapse sweep only suspends subs whose trial
        //    or paid period has an end date in the past, so this never lapses.
        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => now(),
                'current_period_end' => null,
            ],
        );
        OnboardingProgress::firstOrCreate(['tenant_id' => $tenant->id], ['steps' => []]);

        // 5. One demo workspace per trade (grocery@demo.test, restaurant@demo.test,
        //    …) — the surface that shows the same software as a different ERP per
        //    shop type. Last on purpose: it manages its own tenant + permission
        //    team context per workspace and leaves the registrar pointed at the
        //    final demo tenant, so nothing above it may depend on that state.
        //
        //    Off-switch for a fast seed (DEMO_SHOPS=0); DEMO_SHOP_DATA=1 adds the
        //    sample transactions on top, as documented on the seeder itself.
        if (filter_var(env('DEMO_SHOPS', true), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoShopSeeder::class);
        }
    }
}
