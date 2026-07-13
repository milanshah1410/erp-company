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
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Provisions a tenant end-to-end from a verified PendingRegistration:
 * tenant record + subdomain, database (created + migrated via stancl events),
 * baseline seed data, the tenant's first admin user, and a trialing
 * subscription. Runs on the central queue.
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

        // 1. Central tenant record. Creating it fires TenantCreated, whose job
        //    pipeline (CreateDatabase + MigrateDatabase) runs synchronously.
        $tenant = Tenant::create([
            'company_name' => $registration->company_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'status' => 'trial',
        ]);

        // 2. Subdomain → tenant mapping.
        $tenant->domains()->create(['domain' => $registration->subdomain]);

        // 3. Seed baseline data + create the first admin, inside the tenant DB.
        $tenant->run(function () use ($registration) {
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

        // 4. Trialing subscription (central DB).
        $trialDays = (int) config('saas.trial_days', 14);
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays($trialDays),
            'current_period_start' => now(),
        ]);

        // 5. Onboarding tracker (central DB).
        OnboardingProgress::create([
            'tenant_id' => $tenant->id,
            'steps' => [],
        ]);

        // 6. Mark the registration active + wire it to the tenant.
        $registration->update([
            'status' => 'active',
            'tenant_id' => $tenant->id,
        ]);

        // 7. "Your account is ready" email with the tenant login URL.
        Notification::route('mail', $registration->email)
            ->notify(new TenantReadyEmail($registration->company_name, $registration->subdomain));
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

        // Roll back a half-created tenant so its subdomain frees up and no
        // orphan database lingers.
        if ($registration->tenant_id && ($tenant = Tenant::find($registration->tenant_id))) {
            $tenant->delete(); // TenantDeleted → DeleteDatabase
        }

        $registration->update([
            'status' => 'failed',
            'tenant_id' => null,
            'error' => $e->getMessage(),
        ]);
    }
}
