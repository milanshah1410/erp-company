<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\MenuItem;
use App\Models\OnboardingProgress;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Industry\Support\IndustryRegistry;
use App\Services\DemoDataGenerator;
use App\Support\Modules;
use App\Support\ShopProfiles;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * One demo workspace per shop type, each with its own login.
 *
 * The point is the *difference* between them: a Grocery login and a Restaurant
 * login are the same software showing two different ERPs, because the nav, the
 * role matrix and the API are all gated on the tenant's `enabled_modules`, and
 * each workspace here is seeded with exactly the module set its trade runs on
 * (App\Support\ShopProfiles — which now includes every trade declared by an
 * industry plugin). Signing in as `restaurant@demo.test` shows Floor, KOT,
 * Kitchen Display, Menu and Recipes; signing in as `grocery@demo.test` shows
 * none of them.
 *
 * Every workspace is put on Enterprise deliberately. The plan is the ceiling
 * that `EnabledModules::forTenant` narrows against on every read, so anything
 * less would mean a demo's menu reflected its *package* rather than its trade —
 * and the trade is the thing being demonstrated. Plan gating has its own demo
 * surface on /platform/billing.
 *
 * Idempotent: re-running updates the workspaces in place rather than making a
 * second set, so it is safe to run after adding a trade or changing a preset.
 *
 * Usage:
 *   php artisan db:seed --class=DemoShopSeeder
 *   DEMO_SHOP_DATA=1 php artisan db:seed --class=DemoShopSeeder   (+ sample records)
 *
 * Sample data is opt-in because it is the slow part — twenty modules across
 * nineteen workspaces — and most of the time the point is just to look at the
 * menus.
 */
class DemoShopSeeder extends Seeder
{
    /** Shared password for every demo login. Development fixture, never production. */
    private const PASSWORD = 'password';

    /** Email domain for the demo logins — distinct from admin@sims.test. */
    private const DOMAIN = 'demo.test';

    public function run(): void
    {
        // The demo workspaces lean on the global catalogues, exactly as a real
        // signup does. Seeding them here means this seeder stands alone.
        $this->call(PlanSeeder::class);
        $this->call(MenuSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->call(RolePermissionSeeder::class);

        $planId = Plan::where('slug', 'enterprise')->value('id');
        $withData = filter_var(env('DEMO_SHOP_DATA', false), FILTER_VALIDATE_BOOL);

        $created = [];

        foreach (ShopProfiles::options() as $profile) {
            $created[] = $this->seedShop($profile, (int) $planId, $withData);
        }

        $this->report($created, $withData);
    }

    /**
     * Provision (or refresh) one trade's workspace.
     *
     * Mirrors App\Jobs\ProvisionTenant rather than calling it: that job starts
     * from a PendingRegistration and sends a "your account is ready" email,
     * neither of which belongs in a seeder.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function seedShop(array $profile, int $planId, bool $withData): array
    {
        $key = $profile['key'];
        $email = $key.'@'.self::DOMAIN;
        $modules = ShopProfiles::modulesFor($key);

        $tenant = Tenant::updateOrCreate(
            ['email' => $email],
            [
                'company_name' => $profile['label'].' Demo',
                // Active with no trial end: the daily lapse sweep only suspends
                // subscriptions whose period has an end date in the past, so a
                // demo workspace can never go dark on its own.
                'status' => 'active',
                'suspended_by' => null,
            ],
        );

        app(TenantContext::class)->runWith($tenant->id, function () use (
            $tenant, $profile, $key, $email, $modules, $withData
        ) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            (new TenantDatabaseSeeder)->run();

            // The trade's module set — the whole reason these workspaces differ.
            // Sanitized again here so a preset naming a retired module cannot
            // write a key nothing gates on.
            Setting::set('enabled_modules', json_encode(Modules::sanitize($modules)));
            Setting::set('shop_profile', $key);
            if (! empty($profile['industry'])) {
                Setting::set('industry', $profile['industry']);
            }

            $this->refreshMenu($tenant->id);

            $admin = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $profile['label'].' Admin',
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => true,
                ],
            );
            $admin->syncRoles(['super-admin']);

            $this->giveContext($admin);

            if ($withData) {
                $this->generateData($modules, $admin->id);
            }
        });

        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $planId,
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => now(),
                'current_period_end' => null,
            ],
        );
        OnboardingProgress::firstOrCreate(['tenant_id' => $tenant->id], ['steps' => []]);

        return [
            'key' => $key,
            'label' => $profile['label'],
            'industry' => $profile['industry'] ?? null,
            'email' => $email,
            'tenant_id' => $tenant->id,
            'modules' => $modules,
        ];
    }

    /**
     * Re-clone the tenant's private copy of the nav tree.
     *
     * `cloneForTenant` is a no-op when rows already exist, which is right during
     * a signup but wrong here: a re-run after the global menu gained a screen
     * would leave every demo workspace on the old tree. Dropping first means a
     * re-seed always reflects the current MenuSeeder. Safe because these
     * workspaces are fixtures — nobody has reordered their menu by hand.
     */
    private function refreshMenu(int $tenantId): void
    {
        MenuItem::where('tenant_id', $tenantId)->delete();
        TenantMenuAccess::cloneForTenant($tenantId);
    }

    /** Point the admin at the baseline company/branch so the app has a context. */
    private function giveContext(User $admin): void
    {
        $company = Company::where('code', 'MAIN')->first();
        $branch = Branch::where('code', 'HO')->first();

        if (! $company || ! $branch) {
            return;
        }

        $admin->companies()->syncWithoutDetaching([$company->id]);
        $admin->branches()->syncWithoutDetaching([$branch->id]);
        $admin->update([
            'current_company_id' => $company->id,
            'current_branch_id' => $branch->id,
        ]);
    }

    /**
     * Fill the workspace with records the trade would actually have.
     *
     * Masters are seeded for everyone; the rest follows the module set, so a
     * Grocery demo does not end up holding manufacturing orders it has no menu
     * to open.
     *
     * @param  list<string>  $modules
     */
    private function generateData(array $modules, int $userId): void
    {
        $demoModules = ['categories', 'brands', 'customers', 'suppliers', 'products'];

        $byModule = [
            'sales' => ['sales_quotations', 'sales_orders', 'invoices'],
            'pos' => ['pos_sales'],
            'purchase' => ['purchase_indents', 'purchase_orders', 'vendor_invoices'],
            'inventory' => ['stock_adjustments', 'stock_transfers'],
            'accounts' => ['expense_categories', 'expenses', 'vouchers'],
            'crm' => ['leads'],
            'hrms' => ['employees', 'attendance'],
            'manufacturing' => ['boms', 'manufacturing_orders'],
            'logistics' => ['vehicles', 'drivers'],
        ];

        foreach ($byModule as $module => $keys) {
            if (in_array($module, $modules, true)) {
                $demoModules = array_merge($demoModules, $keys);
            }
        }

        // Only ask for keys this build's generator actually knows, so a renamed
        // demo module degrades to "fewer records" instead of an exception.
        $demoModules = array_values(array_intersect(
            $demoModules,
            array_keys(DemoDataGenerator::MODULES),
        ));

        (new DemoDataGenerator($userId))->generate($demoModules, DemoDataGenerator::COUNTS[0]);
    }

    /**
     * @param  list<array<string, mixed>>  $shops
     */
    private function report(array $shops, bool $withData): void
    {
        $this->command?->newLine();
        $this->command?->info('Demo shop logins — password: '.self::PASSWORD);
        $this->command?->newLine();

        $rows = [];
        foreach ($shops as $s) {
            $rows[] = [
                $s['label'],
                $s['industry'] ?? '—',
                $s['email'],
                count($s['modules']),
            ];
        }

        $this->command?->table(['Shop', 'Industry', 'Login', 'Modules'], $rows);

        if (! $withData) {
            $this->command?->comment(
                'Masters only. Re-run with DEMO_SHOP_DATA=1 to add sample transactions.',
            );
        }

        // The module → menu map is cached for 10 minutes; a stale one would show
        // the previous module set and read exactly like the seeder not working.
        $this->command?->comment('Run `php artisan cache:clear` if a menu looks stale.');
        $this->command?->newLine();

        // Sanity line: the industry-plugin trades should be present.
        $plugins = count(IndustryRegistry::trades());
        $this->command?->line(
            count($shops).' workspaces seeded ('.$plugins.' from industry plugins).',
        );
    }
}
