<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OnboardingProgress;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantReadyEmail;
use App\Support\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Provisions a tenant end-to-end from a verified PendingRegistration in the
 * shared database: a tenants row, baseline data + the first admin user (all
 * scoped to the new tenant_id), and a trialing subscription.
 *
 * Roles/permissions are global (tenant_id = NULL, shared across tenants); this
 * job only assigns the admin's role — it ensures the global set exists first so
 * the very first signup on a fresh deploy still works.
 */
class ProvisionTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $registrationId) {}

    public function handle(): void
    {
        $registration = PendingRegistration::findOrFail($this->registrationId);

        if ($registration->status === 'active') {
            return; // already provisioned (idempotent)
        }

        $plan = Plan::where('slug', $registration->plan_slug)->firstOrFail();

        $this->ensureGlobalRolesExist();

        // 1. Central tenant record (landlord-owned, no tenant scope).
        $tenant = Tenant::create([
            'company_name' => $registration->company_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'status' => 'trial',
        ]);

        // 2. Seed baseline + create the first admin, all scoped to the tenant.
        app(TenantContext::class)->runWith($tenant->id, function () use ($registration, $tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            (new TenantDatabaseSeeder)->run();

            $admin = new User();
            $admin->name = $registration->admin_name;
            $admin->email = $registration->email;
            $admin->phone = $registration->phone;
            $admin->is_active = true;
            // Copy the already-hashed password verbatim (the 'hashed' cast
            // leaves an existing hash untouched), so the owner logs in with
            // the password they chose at signup.
            $admin->password = $registration->getRawOriginal('password');
            $admin->save();

            $admin->assignRole('super-admin');

            $company = Company::where('code', 'MAIN')->first();
            $branch = Branch::where('code', 'HO')->first();
            if ($company && $branch) {
                $admin->companies()->syncWithoutDetaching([$company->id]);
                $admin->branches()->syncWithoutDetaching([$branch->id]);
                $admin->update([
                    'current_company_id' => $company->id,
                    'current_branch_id' => $branch->id,
                ]);
            }
        });

        // 3. Trialing subscription (landlord, explicit tenant_id).
        $trialDays = (int) config('saas.trial_days', 14);
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays($trialDays),
            'current_period_start' => now(),
        ]);

        // 4. Onboarding tracker (landlord).
        OnboardingProgress::create([
            'tenant_id' => $tenant->id,
            'steps' => [],
        ]);

        // 5. Mark the registration active + wire it to the tenant.
        $registration->update([
            'status' => 'active',
            'tenant_id' => $tenant->id,
        ]);

        // 6. "Your account is ready" email.
        Notification::route('mail', $registration->email)
            ->notify(new TenantReadyEmail($registration->company_name));
    }

    /**
     * Ensure the shared (global) role/permission set exists. Idempotent — safe
     * to call on every provision; seeds only when the set is absent.
     */
    private function ensureGlobalRolesExist(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        $exists = Role::query()
            ->whereNull(config('permission.column_names.team_foreign_key'))
            ->where('name', 'super-admin')
            ->exists();

        if (! $exists) {
            (new RolePermissionSeeder)->run();
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Tenant provisioning failed', [
            'registration_id' => $this->registrationId,
            'error' => $e->getMessage(),
        ]);

        $registration = PendingRegistration::find($this->registrationId);
        if (! $registration) {
            return;
        }

        // Roll back a half-created tenant; the tenant_id FKs cascade-delete all
        // of its scoped rows.
        if ($registration->tenant_id && ($tenant = Tenant::find($registration->tenant_id))) {
            $tenant->delete();
        }

        $registration->update([
            'status' => 'failed',
            'tenant_id' => null,
            'error' => $e->getMessage(),
        ]);
    }
}
