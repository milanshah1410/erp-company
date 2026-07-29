<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\EnabledModules;
use App\Support\Modules;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use App\Support\TenantRoleCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Roles split two ways on the tenant `web` guard:
     *
     *   system — seeded catalogue rows at tenant_id = NULL, shared by every
     *            workspace. Read-only here: one tenant editing a shared row
     *            would rewrite it for all of them. Duplicate to customise.
     *   custom — rows carrying this tenant's id. Created, edited and deleted
     *            freely, and invisible to every other tenant.
     */
    public function index()
    {
        $tenantId = app(TenantContext::class)->id();

        // Guard + tenant scoping, both of which the shared `roles` table needs:
        // without the guard filter the platform's Level-1 central roles show up
        // in a tenant workspace, and without the tenant filter so do other
        // tenants' custom roles.
        $roles = $this->visibleRoles($tenantId)->orderBy('name')->get();

        // Count users per role from the pivot directly (avoids guard-model
        // resolution issues on Spatie's users() relation under withCount).
        //
        // The role rows themselves are a shared catalogue, but *assignments* are
        // tenant-scoped (Spatie teams, team key = tenant_id), so the count must
        // be filtered — otherwise every tenant sees the platform-wide total.
        $userCounts = DB::table('model_has_roles')
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->where('model_type', User::class)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        // Permissions per role, counted within what this tenant can actually
        // reach: a role holding all 465 permissions reads as (say) 240 in a
        // workspace whose package covers five modules and whose operator has
        // revoked a few screens on top. Matches the matrix below.
        $gated = EnabledModules::forTenant($tenantId) !== null
            || TenantMenuAccess::revokedGroups($tenantId) !== [];

        $permCounts = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->when($gated, fn ($q) => $q->whereIn('permissions.name', $this->visiblePermissionNames($tenantId)))
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        // Tag each role with its department (Level-2 org chart) so the UI can
        // group them. A tenant's own roles sit under "Custom" instead of the org
        // chart, and system roles the catalogue doesn't place fall into "Other".
        $order = array_keys(TenantRoleCatalog::departments());
        $roles->each(function ($role) use ($userCounts, $permCounts) {
            $role->users_count = (int) ($userCounts[$role->id] ?? 0);
            $role->permissions_count = (int) ($permCounts[$role->id] ?? 0);
            $role->department = $role->tenant_id === null
                ? (TenantRoleCatalog::departmentOf($role->name) ?? 'Other')
                : 'Custom';
        });

        // The workspace's own custom roles first — they are the ones a tenant
        // actually maintains — then the system catalogue in org-chart order. The
        // UI groups in encounter order, so this drives section ordering too.
        $roles = $roles->sortBy(function ($role) use ($order) {
            $idx = array_search($role->department, $order, true);

            return sprintf(
                '%d:%03d:%s',
                $role->tenant_id === null ? 1 : 0,
                $idx === false ? 999 : $idx,
                $role->name
            );
        })->values();

        return RoleResource::collection($roles);
    }

    public function show(Role $role)
    {
        $this->assertVisible($role);

        return new RoleResource($role->load('permissions'));
    }

    /**
     * The roles this workspace may see: tenant `web` roles that are either part
     * of the shared system catalogue (tenant_id NULL) or its own.
     */
    private function visibleRoles(?int $tenantId): Builder
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where(fn ($q) => $q->whereNull('tenant_id')
                ->when($tenantId !== null, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)));
    }

    /** A shared catalogue role — read-only from inside a tenant workspace. */
    private function isSystem(Role $role): bool
    {
        return $role->tenant_id === null;
    }

    /**
     * Route binding resolves roles by id across the whole shared table, so a
     * tenant could otherwise reach another tenant's custom role — or a platform
     * central role — by guessing an id. Anything out of scope reads as missing.
     */
    private function assertVisible(Role $role): void
    {
        $tenantId = app(TenantContext::class)->id();

        abort_if(
            $role->guard_name !== 'web' || ($role->tenant_id !== null && $role->tenant_id !== $tenantId),
            404
        );
    }

    /** The tenant that owns anything created here. */
    private function currentTenantId(): int
    {
        $tenantId = app(TenantContext::class)->id();
        abort_if($tenantId === null, 422, 'Custom roles can only be created inside a workspace.');

        return $tenantId;
    }

    /**
     * Role names are unique *within a guard and tenant*, not globally: the same
     * name legitimately exists on the central guard (SaaS-owner roles) and in
     * other tenants. An unscoped unique rule rejected those as duplicates, so
     * editing e.g. "super-admin" here failed validation before it ever saved.
     */
    private function nameRule(?Role $role = null): Unique
    {
        $guard = $role->guard_name ?? 'web';
        $tenantId = $role ? $role->tenant_id : app(TenantContext::class)->id();

        $rule = Rule::unique('roles', 'name')->where(function ($query) use ($guard, $tenantId) {
            $query->where('guard_name', $guard);

            // Global (tenant_id NULL) rows are visible to every tenant, so a
            // clash with one is still a clash for this tenant.
            return $tenantId === null
                ? $query->whereNull('tenant_id')
                : $query->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
        });

        return $role ? $rule->ignore($role->id) : $rule;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->nameRule()],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Anything created here is this workspace's own role, never a catalogue
        // row: the explicit tenant_id is what keeps it out of other tenants'
        // lists (Spatie would infer it from the team resolver, but saying it
        // outright is what makes the separation a guarantee rather than a
        // side effect of whichever team id happens to be set).
        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'tenant_id' => $this->currentTenantId(),
        ]);
        $role->syncPermissions($data['permissions'] ?? []);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    /**
     * Copy a role into this workspace as an editable custom role.
     *
     * The way a tenant tailors a system role: the catalogue row stays untouched
     * for everyone else, and the copy carries the full permission set — including
     * permissions the matrix hides because the module is off — so upgrading a
     * package later reveals them instead of losing them.
     */
    public function duplicate(Request $request, Role $role)
    {
        $this->assertVisible($role);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->nameRule()],
        ]);

        $clone = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'tenant_id' => $this->currentTenantId(),
        ]);
        $clone->syncPermissions($role->loadMissing('permissions')->permissions->pluck('name')->all());

        return (new RoleResource($clone->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Role $role)
    {
        $this->assertVisible($role);

        if ($this->isSystem($role)) {
            return response()->json([
                'message' => 'System roles are shared across workspaces and cannot be edited. Duplicate this role to customise it.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->nameRule($role)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->name = $data['name'];
        $role->save();

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($this->withHiddenPreserved($role, $data['permissions'] ?? []));
        }

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        $this->assertVisible($role);

        if ($this->isSystem($role)) {
            return response()->json(['message' => 'This is a system role and cannot be deleted.'], 422);
        }
        // Assignments are team-scoped, so a custom role can only be held by users
        // of its own tenant — but scope the check anyway, to state the intent.
        $hasUsers = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('tenant_id', $role->tenant_id)
            ->exists();
        if ($hasUsers) {
            return response()->json(['message' => 'Cannot delete a role that still has users assigned.'], 422);
        }
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * Keep permissions the matrix never displayed.
     *
     * The UI posts the boxes it rendered, and it only renders what this tenant
     * can reach — so a plain sync would silently strip, say, a role's tailoring
     * permissions while a Starter tenant edits it. Those survive here and come
     * back into view if the tenant upgrades, or if the platform operator restores
     * a screen it had revoked.
     *
     * @param  array<int, string>  $submitted
     * @return array<int, string>
     */
    private function withHiddenPreserved(Role $role, array $submitted): array
    {
        $tenantId = app(TenantContext::class)->id();
        $enabled = EnabledModules::forTenant($tenantId);
        $revoked = TenantMenuAccess::revokedGroups($tenantId);
        if ($enabled === null && $revoked === []) {
            return $submitted;
        }

        $map = EnabledModules::permissionGroupMap();

        $hidden = $role->loadMissing('permissions')->permissions
            ->pluck('name')
            ->reject(fn (string $name) => $this->permissionVisible($name, $enabled, $map, $revoked))
            ->all();

        return array_values(array_unique(array_merge($submitted, $hidden)));
    }

    /**
     * The permissions this tenant may see and assign: everything its modules
     * cover, minus the groups behind screens the platform operator revoked on
     * /platform/tenants.
     *
     * @return Collection<int, string>
     */
    private function visiblePermissionNames(?int $tenantId): Collection
    {
        $enabled = EnabledModules::forTenant($tenantId);
        $map = EnabledModules::permissionGroupMap();
        $revoked = TenantMenuAccess::revokedGroups($tenantId);

        return Permission::orderBy('name')->pluck('name')
            ->filter(fn (string $name) => $this->permissionVisible($name, $enabled, $map, $revoked))
            ->values();
    }

    /**
     * Both gates, in the one order that matters: a permission has to belong to an
     * enabled module *and* still be reachable from a live menu row.
     *
     * @param  list<string>|null  $enabled
     * @param  array<string, string>  $map
     * @param  list<string>  $revoked
     */
    private function permissionVisible(string $permission, ?array $enabled, array $map, array $revoked): bool
    {
        return EnabledModules::groupAvailable(EnabledModules::groupOf($permission), $enabled, $map)
            && ! TenantMenuAccess::isRevoked($permission, $revoked);
    }

    /**
     * Permissions for the matrix UI, grouped by resource and **scoped to what
     * this tenant can reach**: a Starter tenant assigning roles should not be
     * shown tailoring or e-commerce permissions it cannot use, nor the screens
     * the platform operator switched off for it. Each group also reports the
     * module it belongs to, so the UI can section the matrix by module (core
     * groups — users, settings, companies … — report null).
     */
    public function permissions()
    {
        $tenantId = app(TenantContext::class)->id();
        $enabled = EnabledModules::forTenant($tenantId);
        $map = EnabledModules::permissionGroupMap();
        $catalogue = Modules::CATALOGUE;
        $revoked = TenantMenuAccess::revokedGroups($tenantId);

        $grouped = $this->visiblePermissionNames($tenantId)
            ->groupBy(fn (string $name) => EnabledModules::groupOf($name));

        $modules = $grouped->map(function ($perms, $group) use ($map, $catalogue) {
            // A group can be owned by several modules (POS shares sales.*); the
            // matrix sections by the first owner, which is the one whose branch
            // the screens live under.
            $moduleKey = EnabledModules::owningModule((string) $group, $map);

            return [
                'module' => $group,
                'module_key' => $moduleKey,
                'module_label' => $moduleKey ? ($catalogue[$moduleKey]['label'] ?? $moduleKey) : 'Core',
                'permissions' => $perms->values(),
            ];
        })->values();

        return response()->json([
            'modules' => $modules,
            'all' => $modules->pluck('permissions')->flatten()->values(),
            // Context for the UI: what is switched on, and what got filtered out.
            'enabled_modules' => $enabled,
            'hidden_modules' => $enabled === null ? [] : collect($catalogue)
                ->reject(fn ($meta, $key) => in_array($key, $enabled, true))
                ->map(fn ($meta, $key) => ['key' => $key, 'label' => $meta['label']])
                ->values(),
            // Screens the platform operator revoked for this workspace. Their
            // permissions are absent from the matrix above; a role that still
            // holds them keeps them (hidden) until the screen comes back.
            'revoked_groups' => $revoked,
        ]);
    }
}
