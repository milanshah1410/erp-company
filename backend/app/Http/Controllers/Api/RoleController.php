<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
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
        $roles = Role::query()->withCount('permissions')->orderBy('name')->get();

        // Count users per role from the pivot directly (avoids guard-model
        // resolution issues on Spatie's users() relation under withCount).
        $userCounts = DB::table('model_has_roles')
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles->each(fn ($role) => $role->users_count = (int) ($userCounts[$role->id] ?? 0));

        return RoleResource::collection($roles);
    }

    public function show(Role $role)
    {
        return new RoleResource($role->load('permissions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
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
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Core role names are locked; their permissions can still be tuned.
        if (! in_array($role->name, $this->protected, true)) {
            $role->name = $data['name'];
            $role->save();
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions'] ?? []);
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

    /** All permissions grouped by module, for the permission-matrix UI. */
    public function permissions()
    {
        $grouped = Permission::orderBy('name')->pluck('name')->groupBy(function ($name) {
            $pos = strrpos($name, '.');

            return $pos === false ? 'other' : substr($name, 0, $pos);
        });

        return response()->json([
            'modules' => $grouped->map(fn ($perms, $module) => [
                'module' => $module,
                'permissions' => $perms->values(),
            ])->values(),
            'all' => Permission::orderBy('name')->pluck('name'),
        ]);
    }
}
