<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\MenuItem;
use App\Models\OnboardingProgress;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\TenantReadyEmail;
use App\Services\DemoDataGenerator;
use App\Support\TenantContext;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $this->ensureGlobalMenuExists();

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

            // Give the tenant its own copy of the navigation catalogue, stamped
            // with its tenant_id, so it can be reordered/customised per tenant
            // without touching the shared template. The read-time permission /
            // plan-feature / module gating in MenuController still applies on
            // top of these rows.
            $this->cloneMenuForTenant($tenant->id);

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

            // Apply the onboarding-wizard choices (steps 1-6) on top of the
            // baseline seed, then optionally load sample data (step 7).
            $this->applyWizardConfig($registration->config ?? [], $admin, $company, $branch);
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
     * Apply the onboarding-wizard payload to the freshly-seeded tenant. Runs
     * inside the tenant context, so every write is tenant-scoped. Every section
     * is optional — a bare signup (no wizard) leaves the baseline seed intact.
     *
     * @param  array<string, mixed>  $config
     */
    private function applyWizardConfig(array $config, User $admin, ?Company $company, ?Branch $branch): void
    {
        if ($config === []) {
            return;
        }

        // --- Step 1 / 5 / 6: company profile, currency, financial-year start ---
        if ($company) {
            $co = $config['company'] ?? [];
            $currency = $config['currency'] ?? [];
            $company->fill(array_filter([
                'name' => $company->name,
                'legal_name' => $co['legal_name'] ?? null,
                'gstin' => $co['gstin'] ?? ($config['tax']['gstin'] ?? null),
                'pan' => $co['pan'] ?? null,
                'address' => $co['address'] ?? null,
                'city' => $co['city'] ?? null,
                'state' => $co['state'] ?? null,
                'country' => $co['country'] ?? null,
                'pincode' => $co['pincode'] ?? null,
                'currency_code' => $currency['code'] ?? null,
                'currency_symbol' => $currency['symbol'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));
            if (! empty($config['fy_start_month'])) {
                $company->financial_year_start_month = (int) $config['fy_start_month'];
            }
            $company->save();
        }

        // --- Step 5: rename the default branch / warehouse ---
        if ($branch && ! empty($config['branch']['name'])) {
            $branch->update(['name' => $config['branch']['name']]);
        }
        if (! empty($config['warehouse']['name'])) {
            Warehouse::where('code', 'WH-MAIN')->update(['name' => $config['warehouse']['name']]);
        }

        // --- Step 5: financial year ---
        if ($company && ! empty($config['financial_year']['start_date']) && ! empty($config['financial_year']['end_date'])) {
            $fy = $config['financial_year'];
            FinancialYear::updateOrCreate(
                ['company_id' => $company->id, 'name' => $fy['name'] ?? 'FY'],
                [
                    'start_date' => $fy['start_date'],
                    'end_date' => $fy['end_date'],
                    'is_current' => true,
                    'is_closed' => false,
                ],
            );
        }

        // --- Steps 2 / 3 / 6: settings (industry, modules, tax, number series) ---
        $settings = [];
        if (! empty($config['industry'])) {
            $settings['industry'] = (string) $config['industry'];
        }
        if (! empty($config['modules']) && is_array($config['modules'])) {
            $settings['enabled_modules'] = json_encode(array_values($config['modules']));
        }
        if (isset($config['tax']['gst_enabled'])) {
            $settings['gst_enabled'] = $config['tax']['gst_enabled'] ? '1' : '0';
        }
        if (isset($config['tax']['default_gst_rate'])) {
            $settings['default_tax_rate'] = (string) $config['tax']['default_gst_rate'];
        }
        // Number-series prefixes — only accept known keys (Setting::NUMBER_PREFIX_DEFAULTS).
        foreach ((array) ($config['number_prefixes'] ?? []) as $key => $val) {
            if (array_key_exists($key, Setting::NUMBER_PREFIX_DEFAULTS) && is_string($val) && $val !== '') {
                $settings[$key] = $val;
            }
        }
        if (! empty($config['company']['logo_path'])) {
            $settings['company_logo_url'] = Storage::disk('public')->url($config['company']['logo_path']);
        }
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // --- Step 4: additional team members ---
        foreach ($config['users'] ?? [] as $u) {
            if (empty($u['email']) || empty($u['name'])) {
                continue;
            }
            $user = new User();
            $user->name = $u['name'];
            $user->email = strtolower(trim($u['email']));
            $user->password = Hash::make(Str::random(16));
            $user->is_active = true;
            $user->save();

            $role = $this->resolveRole($u['role'] ?? null);
            $user->assignRole($role);

            if ($company && $branch) {
                $user->companies()->syncWithoutDetaching([$company->id]);
                $user->branches()->syncWithoutDetaching([$branch->id]);
                $user->update(['current_company_id' => $company->id, 'current_branch_id' => $branch->id]);
            }
        }

        // --- Step 7: optional sample data ---
        if (! empty($config['load_demo'])) {
            $count = (int) ($config['demo_count'] ?? 100);
            (new DemoDataGenerator($admin->id))->generate(array_keys(DemoDataGenerator::MODULES), $count);
        }
    }

    /** Map a requested role slug to an existing web role, defaulting to staff. */
    private function resolveRole(?string $slug): string
    {
        $slug = $slug ? trim($slug) : '';
        if ($slug !== '' && Role::where('guard_name', 'web')->where('name', $slug)->exists()) {
            return $slug;
        }

        return 'staff';
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

    /**
     * Ensure the shared (global) menu catalogue exists before we clone it for a
     * new tenant. Idempotent — MenuSeeder only seeds/updates the NULL-tenant
     * rows, so calling it on every provision is safe and self-heals a deploy
     * whose menu was never seeded.
     */
    private function ensureGlobalMenuExists(): void
    {
        if (! MenuItem::whereNull('tenant_id')->exists()) {
            (new MenuSeeder)->run();
        }
    }

    /**
     * Clone the global menu catalogue (tenant_id NULL) into rows owned by this
     * tenant. Runs inside the tenant context. Parent links are remapped to the
     * freshly-created tenant rows, and the whole thing is idempotent: if the
     * tenant already has menu rows we leave them untouched.
     */
    private function cloneMenuForTenant(int $tenantId): void
    {
        if (MenuItem::where('tenant_id', $tenantId)->exists()) {
            return; // already cloned
        }

        // Group the global rows by parent so we can walk the tree top-down and
        // always know a child's new parent id before inserting the child.
        $byParent = MenuItem::whereNull('tenant_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('parent_id');

        $clone = function (?int $globalParentId, ?int $newParentId) use (&$clone, $byParent, $tenantId): void {
            foreach ($byParent->get($globalParentId, collect()) as $row) {
                /** @var MenuItem $copy */
                $copy = $row->replicate();
                $copy->tenant_id = $tenantId;
                $copy->parent_id = $newParentId;
                $copy->save();

                $clone($row->id, $copy->id);
            }
        };

        $clone(null, null);
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
