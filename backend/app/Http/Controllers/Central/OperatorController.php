<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Resources\CentralUserResource;
use App\Models\CentralUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Manage platform operators (central_users) and their Level-1 roles.
 *
 * Guarded by the `operators.*` permissions. The `super-admin` role is the one
 * privilege boundary enforced here in code: only a super-admin may grant it or
 * modify/delete another super-admin, so a lesser admin cannot escalate.
 */
class OperatorController extends Controller
{
    private const GUARD = 'central';

    public function index(): AnonymousResourceCollection
    {
        $operators = CentralUser::query()->with('roles')->orderBy('name')->get();

        return CentralUserResource::collection($operators);
    }

    public function show(CentralUser $operator): CentralUserResource
    {
        return new CentralUserResource($operator->load('roles'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $this->authorizeRoleAssignment($request, $data['roles'] ?? []);

        $this->authorizeTenantAccessFlag($request, $data);

        $operator = new CentralUser;
        $operator->name = $data['name'];
        $operator->email = $data['email'];
        $operator->password = Hash::make($data['password']);
        $operator->is_active = $data['is_active'] ?? true;
        $operator->can_manage_tenant_access = $data['can_manage_tenant_access'] ?? false;
        $operator->save();

        $operator->syncRoles($data['roles'] ?? []);

        return (new CentralUserResource($operator->load('roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, CentralUser $operator): CentralUserResource
    {
        $this->guardSuperAdminTarget($request, $operator);
        $data = $this->validateData($request, $operator);
        $this->authorizeRoleAssignment($request, $data['roles'] ?? []);
        $this->authorizeTenantAccessFlag($request, $data, $operator);

        $operator->name = $data['name'];
        $operator->email = $data['email'];
        if (! empty($data['password'])) {
            $operator->password = Hash::make($data['password']);
        }
        if (array_key_exists('is_active', $data)) {
            $operator->is_active = $data['is_active'];
        }
        if (array_key_exists('can_manage_tenant_access', $data)) {
            $operator->can_manage_tenant_access = $data['can_manage_tenant_access'];
        }
        $operator->save();

        if (array_key_exists('roles', $data)) {
            $operator->syncRoles($data['roles']);
        }

        return new CentralUserResource($operator->load('roles'));
    }

    public function destroy(Request $request, CentralUser $operator): JsonResponse
    {
        $this->guardSuperAdminTarget($request, $operator);

        if ($request->user()->is($operator)) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        // Never allow removing the last super-admin — it would lock everyone out.
        if ($operator->hasRole('super-admin') && $this->superAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot delete the last super-admin.'], 422);
        }

        $operator->delete();

        return response()->json(['message' => 'Operator deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?CentralUser $operator = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('central_users', 'email')->ignore($operator?->id)],
            'password' => [$operator ? 'nullable' : 'required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'can_manage_tenant_access' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', self::GUARD)],
        ]);
    }

    /**
     * Only a super-admin may grant the super-admin role.
     *
     * @param  array<int, string>  $roles
     */
    private function authorizeRoleAssignment(Request $request, array $roles): void
    {
        if (in_array('super-admin', $roles, true) && ! $request->user()->hasRole('super-admin')) {
            abort(403, 'Only a super-admin may grant the super-admin role.');
        }
    }

    /**
     * Only a super-admin may hand out the tenant-access flag.
     *
     * The same rule as the super-admin role above, and for the same reason: an
     * operator must not be able to widen their own (or a colleague's) reach into
     * what a paying workspace is entitled to. Setting it to the value it already
     * has is not a change, so re-saving an operator form is never blocked.
     *
     * @param  array<string, mixed>  $data
     */
    private function authorizeTenantAccessFlag(Request $request, array $data, ?CentralUser $operator = null): void
    {
        if (! array_key_exists('can_manage_tenant_access', $data)) {
            return;
        }

        $current = (bool) ($operator?->can_manage_tenant_access ?? false);

        if ((bool) $data['can_manage_tenant_access'] !== $current && ! $request->user()->hasRole('super-admin')) {
            abort(403, 'Only a super-admin may change who can manage tenant access.');
        }
    }

    /** A non-super-admin may not modify or delete a super-admin operator. */
    private function guardSuperAdminTarget(Request $request, CentralUser $operator): void
    {
        if ($operator->hasRole('super-admin') && ! $request->user()->hasRole('super-admin')) {
            abort(403, 'Only a super-admin may modify another super-admin.');
        }
    }

    private function superAdminCount(): int
    {
        $roleId = Role::where('guard_name', self::GUARD)->where('name', 'super-admin')->value('id');
        if (! $roleId) {
            return 0;
        }

        // Count off the pivot directly to sidestep Spatie's team-scoped users()
        // relation (mirrors Api\RoleController). Only central operators count.
        return (int) DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', CentralUser::class)
            ->count();
    }
}
