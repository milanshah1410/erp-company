<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Domain;
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
use App\Services\Billing\CouponService;
use App\Services\DemoDataGenerator;
use App\Support\IndustryTemplates;
use App\Support\ProvisionSteps;
use App\Support\ShopProfiles;
use App\Support\Subdomain;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Provisions a tenant end-to-end from a verified PendingRegistration in the
 * shared database: a tenants row, baseline data + the first admin user (all
 * scoped to the new tenant_id), and a trialing subscription.
 *
 * The work is organised as the eight documented onboarding phases (Volume 11
 * §"Onboarding Automation") — Create Tenant, Select Industry, Select Plan,
 * Migration, Seeder, Enable Modules, Create Admin, Demo Data — each run through
 * App\Support\ProvisionSteps so its outcome is written back to the registration
 * row while the job is still running. That row is what the operator panel and
 * the self-serve provisioning screen poll, so the progress they show is the
 * real thing rather than a timed animation.
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

        // findBySlug, not a bare where: a registration created before a tier was
        // retired must still provision — onto its replacement (Plan::LEGACY_SLUGS).
        $plan = Plan::findBySlug($registration->plan_slug)
            ?? throw new RuntimeException("Unknown plan slug \"{$registration->plan_slug}\".");

        // Phase-by-phase progress, written back to the registration row as each
        // one finishes so a poll mid-provision reports what actually happened
        // (Volume 11 §"Onboarding Automation").
        $steps = new ProvisionSteps($registration);

        $config = (array) ($registration->config ?? []);
        $trade = $config['shop_profile'] ?? null;
        $trade = is_string($trade) && array_key_exists($trade, ShopProfiles::all()) ? $trade : null;
        // Older wizard builds send the trade's *label* here, so resolve rather
        // than store: a signup is never failed over a classification field.
        $industry = IndustryTemplates::resolveKey($config['industry'] ?? null, $config['shop_profile'] ?? null);

        // --- 1. Create Tenant ---------------------------------------------
        // Central tenant record (landlord-owned, no tenant scope), and the
        // workspace address it claimed at signup. The address is re-resolved
        // rather than trusted blindly: a registration can sit unverified long
        // enough for someone else to take what it reserved.
        //
        // Industry + trade land on the tenant row here as well as in the
        // workspace's own settings (phase 6). Both are needed: tenant-side
        // screens read the setting, while the platform panel — which has no
        // tenant context — lists, filters and reports off the column.
        $subdomain = $this->claimSubdomain($registration);

        $tenant = $steps->run(
            'tenant',
            function () use ($registration, $subdomain, $industry, $trade): Tenant {
                $tenant = Tenant::create([
                    'company_name' => $registration->company_name,
                    'subdomain' => $subdomain,
                    'email' => $registration->email,
                    'phone' => $registration->phone,
                    'industry' => $industry,
                    'business_type' => $trade,
                    'status' => 'trial',
                ]);

                Domain::create([
                    'tenant_id' => $tenant->id,
                    'domain' => Subdomain::fqdn($subdomain),
                    'is_primary' => true,
                ]);

                return $tenant;
            },
            fn (Tenant $t) => Subdomain::fqdn((string) $t->subdomain),
        );

        // --- 2. Select Industry -------------------------------------------
        // Decided at signup and already stored above; recorded as its own phase
        // because it is what drives phases 6 and 7 (module preset, industry
        // plugin screens) and the operator wants to see which vertical landed.
        $industryLabel = $tenant->industryLabel();
        $tradeLabel = $tenant->businessTypeLabel();
        if ($industryLabel === null && $tradeLabel === null) {
            $steps->skip('industry', 'Not classified — the workspace can pick a trade later.');
        } else {
            $steps->done('industry', trim(($industryLabel ?? 'Unclassified').($tradeLabel ? " · {$tradeLabel}" : '')));
        }

        // --- 3. Select Plan -----------------------------------------------
        // Trialing subscription + the tenant-side onboarding tracker (both
        // landlord-owned, so an explicit tenant_id rather than a scope).
        $steps->run('plan', function () use ($tenant, $plan, $config): string {
            $trialDays = (int) config('saas.trial_days', 14);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays($trialDays),
                'current_period_start' => now(),
            ]);

            OnboardingProgress::create([
                'tenant_id' => $tenant->id,
                'steps' => [],
            ]);

            // A promo code from signup is pinned to the subscription, not redeemed:
            // it discounts the first invoice, which is raised when the trial
            // converts. Re-validated here because the code may have expired or run
            // out between signing up and verifying the email — in which case the
            // workspace is still provisioned, just at list price.
            $couponNote = '';
            $code = trim((string) ($config['coupon_code'] ?? ''));
            if ($code !== '') {
                $coupons = app(CouponService::class);
                try {
                    $coupon = $coupons->validate($code, [
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'amount' => (float) $plan->price,
                        'purchase_type' => 'subscription',
                        'is_new_tenant' => true,
                    ]);
                    $coupons->attachToSubscription($subscription, $coupon);
                    $couponNote = " · coupon {$coupon->code}";
                } catch (ValidationException) {
                    $couponNote = " · coupon {$code} no longer valid";
                }
            }

            return "{$plan->name} — {$trialDays}-day trial{$couponNote}";
        });

        // --- 4. Migration --------------------------------------------------
        // Tenants share one schema (row-level tenant_id), so there is no
        // per-tenant migration to run; the equivalent step is making sure the
        // shared catalogues this workspace is about to be built from exist.
        // Both are idempotent, so this also self-heals a deploy that was never
        // seeded — the very first signup on a fresh install still works.
        $steps->run('schema', function (): string {
            $this->ensureGlobalRolesExist();
            $this->ensureGlobalMenuExists();

            return 'Shared schema ready — global roles + menu catalogue verified.';
        });

        // Everything below writes tenant-scoped rows, so it runs inside the
        // tenant context with Spatie's team scope pinned to the same tenant.
        app(TenantContext::class)->runWith($tenant->id, function () use ($registration, $tenant, $steps, $config, $trade): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            // --- 5. Seeder -------------------------------------------------
            $steps->run('seed', function (): string {
                (new TenantDatabaseSeeder)->run();

                return 'Company, branch, warehouse, chart of accounts and reference data.';
            });

            // --- 6. Enable Modules -----------------------------------------
            // The tenant's own copy of the navigation catalogue plus the wizard
            // choices that decide what it can reach: module selection, industry,
            // tax, number series, company profile.
            $steps->run('modules', function () use ($tenant, $config, $trade): string {
                $this->cloneMenuForTenant($tenant->id, $trade);

                return $this->applyWizardConfig($config);
            });

            // --- 7. Create Admin -------------------------------------------
            $admin = $steps->run(
                'admin',
                fn (): User => $this->createAdmin($registration, $config),
                fn (User $u) => $u->email,
            );

            // --- 8. Demo Data ----------------------------------------------
            $count = (int) ($config['demo_count'] ?? 100);
            if (empty($config['load_demo'])) {
                $steps->skip('demo', 'Not requested — the workspace starts empty.');
            } else {
                $steps->run('demo', function () use ($admin, $count): string {
                    (new DemoDataGenerator($admin->id))->generate(array_keys(DemoDataGenerator::MODULES), $count);

                    return "Sample data generated (~{$count} rows per module).";
                });
            }
        });

        // Mark the registration active + wire it to the tenant.
        $registration->update([
            'status' => 'active',
            'tenant_id' => $tenant->id,
            // Mirror back the address actually granted — it may have gained a
            // numeric tail since the signup reserved it.
            'subdomain' => $subdomain,
        ]);

        // "Your account is ready" email.
        Notification::route('mail', $registration->email)
            ->notify(new TenantReadyEmail($registration->company_name, Subdomain::fqdn($subdomain)));
    }

    /**
     * Apply the onboarding-wizard payload to the freshly-seeded tenant: company
     * profile, financial year, settings and the module selection. Runs inside
     * the tenant context, so every write is tenant-scoped. Every section is
     * optional — a bare signup (no wizard) leaves the baseline seed intact.
     *
     * Returns the one-line summary recorded against the "Enable Modules" step.
     *
     * @param  array<string, mixed>  $config
     */
    private function applyWizardConfig(array $config): string
    {
        $company = Company::where('code', 'MAIN')->first();
        $branch = Branch::where('code', 'HO')->first();

        if ($config === []) {
            return 'Navigation cloned; plan defaults left in place.';
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
        // Stored as the industry-template *key*, matching the tenants.industry
        // column, so tenant-side reads and the platform panel agree. An older
        // wizard build sending a label is resolved, not stored verbatim.
        $industry = IndustryTemplates::resolveKey(
            $config['industry'] ?? null,
            is_string($config['shop_profile'] ?? null) ? $config['shop_profile'] : null,
        );
        if ($industry !== null) {
            $settings['industry'] = $industry;
        }
        // The shop-type preset the signup started from. Kept alongside the
        // resolved module list so a later "reset to the Grocery defaults" knows
        // which preset this workspace was built on.
        if (! empty($config['shop_profile'])) {
            $settings['shop_profile'] = (string) $config['shop_profile'];
        }
        if (! empty($config['modules']) && is_array($config['modules'])) {
            $settings['enabled_modules'] = json_encode(array_values($config['modules']));
        }
        // Modules an operator hand-picked beyond what the plan sells. Kept in
        // their own setting for the same reason the Access panel does it: a
        // later downgrade narrows everything except what was deliberately
        // comped (App\Support\EnabledModules::operatorGrants).
        if (! empty($config['module_grants']) && is_array($config['module_grants'])) {
            $settings['module_grants'] = json_encode(array_values($config['module_grants']));
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

        $modules = is_array($config['modules'] ?? null) ? $config['modules'] : [];

        return $modules === []
            ? 'Navigation cloned; every module the plan allows is on.'
            : count($modules).' module'.(count($modules) === 1 ? '' : 's').' switched on: '.implode(', ', array_slice($modules, 0, 8))
                .(count($modules) > 8 ? '…' : '');
    }

    /**
     * Create the workspace's first admin — the account the signup logs in with —
     * and any extra team members the wizard listed. Runs inside the tenant
     * context with the permission team scope already pinned.
     *
     * @param  array<string, mixed>  $config
     */
    private function createAdmin(PendingRegistration $registration, array $config): User
    {
        $company = Company::where('code', 'MAIN')->first();
        $branch = Branch::where('code', 'HO')->first();

        $admin = new User;
        $admin->name = $registration->admin_name;
        $admin->email = $registration->email;
        $admin->phone = $registration->phone;
        $admin->is_active = true;
        // Copy the already-hashed password verbatim (the 'hashed' cast leaves an
        // existing hash untouched), so the owner logs in with the password they
        // chose at signup.
        $admin->password = $registration->getRawOriginal('password');
        $admin->save();

        $admin->assignRole('super-admin');

        $this->attachToContext($admin, $company, $branch);

        // --- Additional team members from the wizard ---
        foreach ($config['users'] ?? [] as $u) {
            if (empty($u['email']) || empty($u['name'])) {
                continue;
            }
            $user = new User;
            $user->name = $u['name'];
            $user->email = strtolower(trim($u['email']));
            $user->password = Hash::make(Str::random(16));
            $user->is_active = true;
            $user->save();

            $user->assignRole($this->resolveRole($u['role'] ?? null));

            $this->attachToContext($user, $company, $branch);
        }

        return $admin;
    }

    /** Give a freshly-created user the default company/branch to work in. */
    private function attachToContext(User $user, ?Company $company, ?Branch $branch): void
    {
        if (! $company || ! $branch) {
            return;
        }

        $user->companies()->syncWithoutDetaching([$company->id]);
        $user->branches()->syncWithoutDetaching([$branch->id]);
        $user->update([
            'current_company_id' => $company->id,
            'current_branch_id' => $branch->id,
        ]);
    }

    /**
     * Settle on the workspace address for this tenant. The signup reserved one,
     * but a registration can sit unverified for days, so the address is
     * re-checked against what actually outlives it (live tenants + recorded
     * domains) and given a numeric tail if it was claimed in the meantime.
     * Never fails the provision over an address — a signup that got this far
     * has already paid the email-verification cost.
     */
    private function claimSubdomain(PendingRegistration $registration): string
    {
        $wanted = Subdomain::normalize((string) $registration->subdomain)
            ?: Subdomain::suggest((string) $registration->company_name);

        if (strlen($wanted) < Subdomain::MIN) {
            $wanted = 'workspace';
        }

        // Deliberately not Subdomain::isTaken(): that counts this very
        // registration's own reservation as a conflict.
        $free = static fn (string $s): bool => ! in_array($s, Subdomain::RESERVED, true)
            && ! Tenant::where('subdomain', $s)->exists()
            && ! Domain::where('domain', Subdomain::fqdn($s))->exists();

        if ($free($wanted)) {
            return $wanted;
        }

        $base = trim(substr($wanted, 0, Subdomain::MAX - 4), '-');
        for ($i = 1; $i <= 999; $i++) {
            if ($free($candidate = $base.'-'.$i)) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(4));
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
     * Clone a starting menu into rows owned by this tenant, so the operator can
     * later tailor its navigation. Idempotent — the shared helper leaves a tenant
     * that already owns rows alone. Lives in TenantMenuAccess because the platform
     * access screen clones legacy tenants the same way.
     *
     * The trade is passed through so the workspace opens on *its trade's* menu
     * where one has been curated (/platform/shop-menus). It is already validated
     * against ShopProfiles::all() at the top of handle(), so an unknown trade
     * arrives here as null and the helper falls back to the full catalogue.
     */
    private function cloneMenuForTenant(int $tenantId, ?string $trade): void
    {
        TenantMenuAccess::cloneForTenant($tenantId, $trade);
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
