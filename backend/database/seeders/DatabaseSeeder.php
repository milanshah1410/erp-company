<?php

namespace Database\Seeders;

use App\Models\Company;
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

        // Global roles/permissions live at tenant_id = NULL and are shared by
        // every tenant; seed them with no active team.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->call(RolePermissionSeeder::class);

        // 2. A dev tenant — seeded on a trial so the trial banner + onboarding
        //    "Getting Started" widget are visible on first login.
        $tenant = Tenant::firstOrCreate(
            ['email' => 'admin@sims.test'],
            ['company_name' => 'Demo Company', 'status' => 'trial'],
        );

        // 3. Tenant-scoped data: admin user + baseline, inside the tenant context.
        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            $admin = User::updateOrCreate(
                ['email' => 'admin@sims.test'],
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
        Subscription::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => Plan::where('slug', 'starter')->value('id'),
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays((int) config('saas.trial_days', 14)),
                'current_period_start' => now(),
            ],
        );
        OnboardingProgress::firstOrCreate(['tenant_id' => $tenant->id], ['steps' => []]);
    }
}
