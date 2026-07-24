<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenant;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
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
                'email' => $t->email,
                'phone' => $t->phone,
                'status' => $t->status,
                'suspended_by' => $t->suspended_by,
                'plan' => $t->subscription?->plan?->name,
                'subscription_status' => $t->subscription?->status,
                'trial_ends_at' => $t->subscription?->trial_ends_at?->toDateString(),
                'users_count' => (int) ($userCounts[$t->id] ?? 0),
                'created_at' => $t->created_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * Create a tenant workspace on behalf of a customer. Mirrors the public
     * signup (RegistrationController::register) minus email verification.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'plan_slug' => ['nullable', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
            'load_demo' => ['nullable', 'boolean'],
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

        $registration = PendingRegistration::create([
            'company_name' => $data['company_name'],
            'admin_name' => $data['admin_name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed via cast
            'plan_slug' => $data['plan_slug'] ?? 'starter',
            'config' => ! empty($data['load_demo']) ? ['load_demo' => true] : null,
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
     * Poll while ProvisionTenant runs for an operator-created workspace.
     */
    public function provisioningStatus(PendingRegistration $registration): JsonResponse
    {
        return response()->json([
            'status' => $registration->status,
            'error' => $registration->error,
            'tenant_id' => $registration->tenant_id,
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
