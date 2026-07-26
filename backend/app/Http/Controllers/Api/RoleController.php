<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\EnabledModules;
use App\Support\Modules;
use App\Support\TenantContext;
use App\Support\TenantRoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /** Roles that ship with the system and cannot be deleted. */
    private array $protected = ['super-admin', 'admin'];

    public function index()
    {
        $roles = Role::query()->orderBy('name')->get();

        $tenantId = app(TenantContext::class)->id();

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
        // workspace whose package covers five modules. Matches the matrix below.
        $enabled = EnabledModules::forTenant($tenantId);
        $permCounts = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->when($enabled !== null, function ($q) use ($enabled) {
                $map = EnabledModules::permissionGroupMap();
                $visible = Permission::orderBy('name')->pluck('name')
                    ->filter(fn (string $name) => EnabledModules::groupAvailable(EnabledModules::groupOf($name), $enabled, $map))
                    ->values();

                return $q->whereIn('permissions.name', $visible);
            })
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        // Tag each role with its department (Level-2 org chart) so the UI can
        // group them. Custom roles fall into "Other".
        $order = array_keys(TenantRoleCatalog::departments());
        $roles->each(function ($role) use ($userCounts, $permCounts) {
            $role->users_count = (int) ($userCounts[$role->id] ?? 0);
            $role->permissions_count = (int) ($permCounts[$role->id] ?? 0);
            $role->department = TenantRoleCatalog::departmentOf($role->name) ?? 'Other';
        });

        // Order by department (org-chart order), then name — the UI groups in
        // encounter order, so this drives the section ordering too.
        $roles = $roles->sortBy(function ($role) use ($order) {
            $idx = array_search($role->department, $order, true);

            return sprintf('%03d:%s', $idx === false ? 999 : $idx, $role->name);
        })->values();

        return RoleResource::collection($roles);
    }

    public function show(Role $role)
    {
        return new RoleResource($role->load('permissions'));
    }

    /**
     * Role names are unique *within a guard and tenant*, not globally: the same
     * name legitimately exists on the central guard (SaaS-owner roles) and in
     * other tenants. An unscoped unique rule rejected those as duplicates, so
     * editing e.g. "super-admin" here failed validation before it ever saved.
     */
    private function nameRule(?Role $role = null): \Illuminate\Validation\Rules\Unique
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

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->nameRule($role)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Core role names are locked; their permissions can still be tuned.
        if (! in_array($role->name, $this->protected, true)) {
            $role->name = $data['name'];
            $role->save();
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($this->withHiddenPreserved($role, $data['permissions'] ?? []));
        }

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, $this->protected, true)) {
            return response()->json(['message' => 'This is a system role and cannot be deleted.'], 422);
        }
        $hasUsers = DB::table('model_has_roles')->where('role_id', $role->id)->exists();
        if ($hasUsers) {
            return response()->json(['message' => 'Cannot delete a role that still has users assigned.'], 422);
        }
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * Keep permissions the matrix never displayed.
     *
     * The UI posts the boxes it rendered, and it only renders the tenant's own
     * modules — so a plain sync would silently strip, say, a role's tailoring
     * permissions while a Starter tenant edits it. Those survive here and come
     * back into view if the tenant upgrades.
     *
     * @param  array<int, string>  $submitted
     * @return array<int, string>
     */
    private function withHiddenPreserved(Role $role, array $submitted): array
    {
        $enabled = EnabledModules::forTenant(app(TenantContext::class)->id());
        if ($enabled === null) {
            return $submitted;
        }

        $map = EnabledModules::permissionGroupMap();

        $hidden = $role->loadMissing('permissions')->permissions
            ->pluck('name')
            ->reject(fn (string $name) => EnabledModules::groupAvailable(EnabledModules::groupOf($name), $enabled, $map))
            ->all();

        return array_values(array_unique(array_merge($submitted, $hidden)));
    }

    /**
     * Permissions for the matrix UI, grouped by resource and **scoped to this
     * tenant's modules**: a Starter tenant assigning roles should not be shown
     * tailoring or e-commerce permissions it cannot reach. Each group also
     * reports the module it belongs to, so the UI can section the matrix by
     * module (core groups — users, settings, companies … — report null).
     */
    public function permissions()
    {
        $enabled = EnabledModules::forTenant(app(TenantContext::class)->id());
        $map = EnabledModules::permissionGroupMap();
        $catalogue = Modules::CATALOGUE;

        $grouped = Permission::orderBy('name')->pluck('name')
            ->groupBy(fn (string $name) => EnabledModules::groupOf($name))
            ->filter(fn ($perms, $group) => EnabledModules::groupAvailable((string) $group, $enabled, $map));

        $modules = $grouped->map(function ($perms, $group) use ($map, $catalogue) {
            $moduleKey = $map[$group] ?? null;

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
        ]);
    }
}
