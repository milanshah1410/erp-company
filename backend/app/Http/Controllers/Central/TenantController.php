<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenant;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GstinValidator;
use App\Support\GstStateCodes;
use App\Support\IndustryTemplates;
use App\Support\Modules;
use App\Support\ProvisionSteps;
use App\Support\ShopProfiles;
use App\Support\Subdomain;
use App\Support\TenantContext;
use App\Support\TenantLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant management for the SaaS-owner platform panel (Level-1 RBAC):
 * list/create tenant workspaces, suspend/reactivate them, and impersonate a
 * workspace admin for support. Guarded by the `tenants.*` central permissions.
 *
 * Creation reuses the self-serve provisioning pipeline (PendingRegistration →
 * ProvisionTenant) so an operator-created workspace is identical to a signup —
 * except the email-verification step is skipped: the operator vouches for the
 * address, so the registration is created already-verified and provisioning
 * is dispatched immediately.
 */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->with('subscription.plan')
            ->orderByDesc('created_at')
            ->get();

        // Per-tenant user counts off the raw table: the User model's tenant
        // scope no-ops on central requests (no tenant context), so counting via
        // the relation would need explicit scoping anyway — one grouped query
        // beats N counts.
        $userCounts = DB::table('users')
            ->select('tenant_id', DB::raw('count(*) as aggregate'))
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        return response()->json([
            'data' => $tenants->map(fn (Tenant $t) => [
                'id' => $t->id,
                'company_name' => $t->company_name,
                'subdomain' => $t->subdomain,
                'workspace_url' => $t->workspaceUrl(),
                'email' => $t->email,
                'phone' => $t->phone,
                'industry' => $t->industry,
                'industry_label' => $t->industryLabel(),
                'business_type' => $t->business_type,
                'business_type_label' => $t->businessTypeLabel(),
                'status' => $t->status,
                'has_limit_overrides' => ! empty($t->limit_overrides),
                'suspended_by' => $t->suspended_by,
                // The subscription is what plan management acts on; the id travels
                // with the row so the panel doesn't have to look it up per tenant.
                'subscription_id' => $t->subscription?->id,
                'plan' => $t->subscription?->plan?->name,
                'subscription_status' => $t->subscription?->status,
                'trial_ends_at' => $t->subscription?->trial_ends_at?->toDateString(),
                'users_count' => (int) ($userCounts[$t->id] ?? 0),
                'created_at' => $t->created_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * One tenant's full profile — the company record, its industry/trade, plan
     * and the reference lists the edit form needs so the panel renders in one
     * round-trip.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'states' => collect(GstStateCodes::MAP)
                ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                ->values(),
            'data' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'subdomain' => $tenant->subdomain,
                'workspace_url' => $tenant->workspaceUrl(),
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'industry' => $tenant->industry,
                'industry_label' => $tenant->industryLabel(),
                'business_type' => $tenant->business_type,
                'business_type_label' => $tenant->businessTypeLabel(),
                'status' => $tenant->status,
                'suspended_by' => $tenant->suspended_by,
                // The tenant as *our* customer — what our GST tax invoices to it
                // are raised against (Volume 11 §Billing → GST).
                'billing_legal_name' => $tenant->billing_legal_name,
                'billing_gstin' => $tenant->billing_gstin,
                'billing_state_code' => $tenant->billing_state_code,
                'billing_address' => $tenant->billing_address,
                'place_of_supply' => $tenant->placeOfSupply(),
                'place_of_supply_label' => $tenant->placeOfSupply()
                    ? GstStateCodes::name($tenant->placeOfSupply())
                    : null,
                'subscription_id' => $tenant->subscription?->id,
                'plan' => $tenant->subscription?->plan?->name,
                'plan_slug' => $tenant->subscription?->plan?->slug,
                'subscription_status' => $tenant->subscription?->status,
                'created_at' => $tenant->created_at?->toDateTimeString(),
            ],
            'industries' => IndustryTemplates::catalogue(),
        ]);
    }

    /**
     * Edit the company profile: contact details plus the industry and trade the
     * workspace is classified under.
     *
     * The trade is mirrored into the workspace's own `shop_profile` setting,
     * because tenant-side code (the trade-specific screens) reads that setting
     * and would otherwise keep answering with the old trade. Deliberately *not*
     * mirrored: the module selection. Re-applying a trade preset would switch
     * modules on and off inside a live workspace — a reclassification is
     * bookkeeping, not a re-onboarding. Modules stay where the Access panel and
     * the tenant's own settings put them.
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'industry' => ['sometimes', 'nullable', 'string', Rule::in(IndustryTemplates::keys())],
            'business_type' => ['sometimes', 'nullable', 'string', Rule::in(ShopProfiles::keys())],
            // Billing identity: what our own tax invoices to this workspace carry.
            'billing_legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_gstin' => ['sometimes', 'nullable', 'string', 'size:15'],
            'billing_state_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (! empty($data['billing_gstin'])) {
            $data['billing_gstin'] = strtoupper(trim($data['billing_gstin']));

            // A wrong customer GSTIN puts the invoice in the wrong state's return
            // and costs the customer its input credit, so it is checked properly
            // (checksum included) rather than by length.
            if (! GstinValidator::isValid($data['billing_gstin'])) {
                return response()->json([
                    'message' => 'That GSTIN is not valid.',
                    'errors' => ['billing_gstin' => ['Enter a valid 15-character GSTIN.']],
                ], 422);
            }
        }

        if (! empty($data['billing_state_code']) && ! GstStateCodes::name($data['billing_state_code'])) {
            return response()->json([
                'message' => 'That is not a recognised GST state code.',
                'errors' => ['billing_state_code' => ['Unknown state code.']],
            ], 422);
        }

        // A trade implies its industry; fill it in rather than storing a pair
        // that contradicts itself.
        if (array_key_exists('business_type', $data) && empty($data['industry'])) {
            $resolved = IndustryTemplates::industryOfTrade($data['business_type']);
            if ($resolved && ! $tenant->industry) {
                $data['industry'] = $resolved;
            }
        }

        $tenant->update($data);

        if (array_key_exists('business_type', $data)) {
            $this->syncTradeSetting($tenant);
        }

        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id, 'changes' => $data])
            ->event('updated')->log("Operator updated tenant profile \"{$tenant->company_name}\"");

        return response()->json(['message' => 'Profile updated.', 'data' => $tenant->fresh()]);
    }

    /**
     * Effective resource limits, per-tenant overrides and current usage.
     */
    public function limits(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => TenantLimits::summary($tenant->id)]);
    }

    /**
     * Set (or clear) this tenant's limit overrides.
     *
     * Sending `null` for a key makes that resource unlimited for this tenant;
     * omitting a key entirely — i.e. posting a smaller object — drops the
     * override and hands the resource back to the plan. The payload is the whole
     * override set, not a patch, so "remove the comp" is expressible.
     */
    public function updateLimits(Request $request, Tenant $tenant): JsonResponse
    {
        $overridable = TenantLimits::overridableKeys();

        $data = $request->validate([
            'overrides' => ['present', 'array'],
            'overrides.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $unknown = array_diff(array_keys($data['overrides']), $overridable);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Only metered limits can be overridden.',
                'errors' => ['overrides' => [
                    'Unknown limit: '.implode(', ', $unknown).'. Allowed: '.implode(', ', $overridable).'.',
                ]],
            ], 422);
        }

        $clean = TenantLimits::sanitizeOverrides($data['overrides']);

        $tenant->update(['limit_overrides' => $clean === [] ? null : $clean]);

        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id, 'overrides' => $clean])
            ->event('limits-updated')->log("Operator changed limits for tenant \"{$tenant->company_name}\"");

        return response()->json([
            'message' => 'Limits updated.',
            'data' => TenantLimits::summary($tenant->id),
        ]);
    }

    /**
     * Keep the workspace's own `shop_profile` setting in step with the trade the
     * panel recorded. Runs inside the tenant context because settings are
     * tenant-scoped and the scope fails closed on central requests.
     */
    private function syncTradeSetting(Tenant $tenant): void
    {
        if (! $tenant->business_type) {
            return;
        }

        app(TenantContext::class)->runWith($tenant->id, function () use ($tenant) {
            Setting::updateOrCreate(['key' => 'shop_profile'], ['value' => $tenant->business_type]);
            if ($tenant->industry) {
                Setting::updateOrCreate(['key' => 'industry'], ['value' => $tenant->industry]);
            }
        });
    }

    /**
     * Create a tenant workspace on behalf of a customer. Mirrors the public
     * signup (RegistrationController::register) minus email verification.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            // Optional: left blank, ProvisionTenant derives a free address from
            // the company name (same fallback the public signup uses).
            'subdomain' => ['nullable', 'string', 'max:'.Subdomain::MAX],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'plan_slug' => ['nullable', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
            // Industry / trade. A trade also decides the module set the new
            // workspace starts with, exactly as on the public signup.
            'industry' => ['nullable', 'string', Rule::in(IndustryTemplates::keys())],
            'business_type' => ['nullable', 'string', Rule::in(ShopProfiles::keys())],
            // Explicit module selection (onboarding step 6). Omit it and the
            // trade's preset decides, which is what the signup wizard does;
            // send it to hand-pick, e.g. when comping a module the plan or the
            // preset would not have switched on.
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],
            'load_demo' => ['nullable', 'boolean'],
            'demo_count' => ['nullable', 'integer', 'min:10', 'max:1000'],
        ]);

        // Email is the global login identity — must be free across every
        // tenant's users and any live registration (same rule as signup).
        $email = strtolower(trim($data['email']));
        if ($reason = $this->emailError($email)) {
            return response()->json([
                'message' => 'That email address is not available.',
                'errors' => ['email' => [$reason]],
            ], 422);
        }

        $subdomain = Subdomain::normalize((string) ($data['subdomain'] ?? ''));
        if ($subdomain !== '' && ($reason = Subdomain::error($subdomain))) {
            return response()->json([
                'message' => 'That workspace address is not available.',
                'errors' => ['subdomain' => [$reason]],
            ], 422);
        }

        // Same wizard payload the public signup builds, so ProvisionTenant records
        // the industry/trade and ticks the trade's modules identically whichever
        // door the workspace came through.
        $config = array_filter([
            'industry' => $data['industry'] ?? IndustryTemplates::industryOfTrade($data['business_type'] ?? null),
            'shop_profile' => $data['business_type'] ?? null,
            'load_demo' => ! empty($data['load_demo']) ?: null,
            'demo_count' => ! empty($data['load_demo']) ? (int) ($data['demo_count'] ?? 100) : null,
        ], static fn ($v) => $v !== null);

        // An explicit selection wins; otherwise the trade's preset, narrowed to
        // what the plan sells. The operator's own picks are NOT narrowed — the
        // SaaS owner may comp a workspace a module its tier does not include
        // (the same latitude the Access panel takes).
        $plan = Plan::findBySlug($data['plan_slug'] ?? null);

        if (! empty($data['modules'])) {
            $config['modules'] = Modules::sanitize($data['modules']);
            // Anything picked beyond the tier is recorded as an operator grant,
            // without which EnabledModules would narrow it straight back off on
            // the next read (see App\Support\EnabledModules::operatorGrants).
            $grants = array_values(array_diff($config['modules'], $plan?->allowedModules() ?? Modules::keys()));
            if ($grants !== []) {
                $config['module_grants'] = $grants;
            }
        } elseif (! empty($data['business_type'])) {
            $preset = ShopProfiles::modulesFor($data['business_type']);
            $config['modules'] = $plan
                ? array_values(array_intersect($preset, $plan->allowedModules()))
                : $preset;
        }

        $registration = PendingRegistration::create([
            'company_name' => $data['company_name'],
            'subdomain' => $subdomain ?: null,
            'admin_name' => $data['admin_name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed via cast
            'plan_slug' => $data['plan_slug'] ?? 'starter',
            'config' => $config === [] ? null : $config,
            'verification_code' => (string) random_int(100000, 999999),
            'verification_token' => Str::random(48),
            'verified_at' => now(), // operator-created: skip email verification
            'status' => 'provisioning',
        ]);

        ProvisionTenant::dispatch($registration->id);

        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'registration_id' => $registration->id, 'email' => $email])
            ->event('created')->log("Operator created tenant workspace \"{$registration->company_name}\"");

        return response()->json([
            'message' => 'Provisioning started.',
            'registration_id' => $registration->id,
        ], 201);
    }

    /**
     * Poll while ProvisionTenant runs for an operator-created workspace: the
     * coarse status plus the eight onboarding phases with what each one did
     * (Volume 11 §"Onboarding Automation"). A failed provision names the phase
     * it died in, which is the whole point of tracking them.
     */
    public function provisioningStatus(PendingRegistration $registration): JsonResponse
    {
        return response()->json([
            'status' => $registration->status,
            'error' => $registration->error,
            'tenant_id' => $registration->tenant_id,
            'steps' => ProvisionSteps::forRegistration($registration),
        ]);
    }

    /**
     * Suspend a workspace by operator action: a hard lock. Logins are rejected,
     * every tenant API route returns 403 (EnsureTenantActive), the SPA lands on
     * the suspended screen, and scheduled jobs skip it (IteratesTenants).
     * Unlike a billing suspension there is no read-only grace period.
     */
    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        if ($tenant->status === 'suspended') {
            return response()->json(['message' => 'Tenant is already suspended.'], 422);
        }

        $tenant->update(['status' => 'suspended', 'suspended_by' => 'operator']);

        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id])
            ->event('suspended')->log("Operator suspended tenant \"{$tenant->company_name}\"");

        return response()->json(['message' => 'Tenant suspended.', 'status' => $tenant->status]);
    }

    /**
     * Lift a suspension. Restores 'trial' while a trialing subscription is
     * still inside its window, 'active' otherwise.
     */
    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        if ($tenant->status !== 'suspended') {
            return response()->json(['message' => 'Tenant is not suspended.'], 422);
        }

        $subscription = $tenant->subscription;
        $status = ($subscription
            && $subscription->status === 'trialing'
            && $subscription->trial_ends_at?->isFuture()) ? 'trial' : 'active';

        $tenant->update(['status' => $status, 'suspended_by' => null]);

        activity('central-tenants')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id, 'restored_to' => $status])
            ->event('activated')->log("Operator reactivated tenant \"{$tenant->company_name}\"");

        return response()->json(['message' => 'Tenant reactivated.', 'status' => $tenant->status]);
    }

    /**
     * Impersonate the workspace's admin: issue a staff (web-guard User) Sanctum
     * token for the tenant's super-admin so the operator can open the workspace
     * exactly as that user sees it. The grant is fully audit-logged and the
     * token is named "impersonation" so it is distinguishable from real logins.
     */
    public function impersonate(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $this->findWorkspaceAdmin($tenant);

        if (! $user) {
            return response()->json(['message' => 'This workspace has no active user to impersonate.'], 422);
        }

        $operator = $request->user();

        // Log before switching context — central activity rows carry no tenant.
        activity('central-tenants')->causedBy($operator)
            ->withProperties([
                'ip' => $request->ip(),
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->event('impersonated')->log("Operator impersonated tenant \"{$tenant->company_name}\"");

        // Establish the tenant so sessionPayload (roles/permissions, company/
        // branch context — all tenant-scoped) resolves, mirroring Api\AuthController.
        app(TenantContext::class)->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $token = $user->createToken('impersonation:operator-'.$operator->id)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->sessionPayload(),
        ]);
    }

    /**
     * The tenant's web super-admin (first by id), falling back to the oldest
     * active user. Queried off the role pivot directly: on central requests the
     * User tenant-scope no-ops, so every query filters tenant_id explicitly.
     */
    private function findWorkspaceAdmin(Tenant $tenant): ?User
    {
        $teamKey = config('permission.column_names.team_foreign_key'); // tenant_id

        // Global web roles live at tenant_id NULL; assignments are team-scoped.
        $roleId = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'super-admin')
            ->whereNull($teamKey)
            ->value('id');

        $adminIds = $roleId
            ? DB::table('model_has_roles')
                ->where('role_id', $roleId)
                ->where('model_type', User::class)
                ->where($teamKey, $tenant->id)
                ->pluck('model_id')
            : collect();

        $active = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        return (clone $active)->whereIn('id', $adminIds)->orderBy('id')->first()
            ?? $active->orderBy('id')->first();
    }

    /**
     * Same availability rule as public signup: email must not belong to any
     * user (across tenants) or a live pending registration.
     */
    private function emailError(string $email): ?string
    {
        $takenByUser = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->exists();

        if ($takenByUser) {
            return 'An account with that email already exists.';
        }

        $takenByPending = PendingRegistration::where('email', $email)
            ->where('status', '!=', 'failed')
            ->exists();

        if ($takenByPending) {
            return 'A signup with that email is already in progress.';
        }

        return null;
    }
}
