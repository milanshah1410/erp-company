<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Read + tune the SaaS-owner (central-guard) roles that power the platform
 * panel. Role names are fixed (the Level-1 hierarchy is a product decision);
 * their permission sets can be re-tuned by a super-admin via update().
 */
class RoleController extends Controller
{
    private const GUARD = 'central';

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        $userCounts = DB::table('model_has_roles')
            ->whereIn('role_id', $roles->pluck('id'))
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions_count' => (int) $role->permissions_count,
                'operators_count' => (int) ($userCounts[$role->id] ?? 0),
                'permissions' => $role->permissions->pluck('name'),
            ]),
        ]);
    }

    /**
     * Re-tune a central role's permission set. Guarded by `roles.edit`, which
     * only super-admin holds.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === self::GUARD, 404);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', self::GUARD)],
        ]);

        $role->syncPermissions($data['permissions']);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name'),
            ],
        ]);
    }

    /** Platform permission vocabulary, grouped by control surface, for the matrix UI. */
    public function permissions(): JsonResponse
    {
        $grouped = Permission::query()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->pluck('name')
            ->groupBy(function (string $name) {
                $pos = strrpos($name, '.');

                return $pos === false ? 'other' : substr($name, 0, $pos);
            });

        return response()->json([
            'modules' => $grouped->map(fn ($perms, $module) => [
                'module' => $module,
                'permissions' => $perms->values(),
            ])->values(),
            'all' => $grouped->flatten()->values(),
        ]);
    }
}
