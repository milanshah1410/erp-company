<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with(['roles', 'companies:id,name', 'branches:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->query('role')));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return UserResource::collection($query->paginate($perPage));
    }

    public function store(UserRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'password' => Hash::make($data['password']),
        ]);
        $user->syncRoles($data['roles'] ?? []);
        $user->companies()->sync($data['company_ids'] ?? []);
        $user->branches()->sync($data['branch_ids'] ?? []);

        return (new UserResource($user->load(['roles', 'companies:id,name', 'branches:id,name'])))->response()->setStatusCode(201);
    }

    public function show(User $user)
    {
        return new UserResource($user->load(['roles', 'companies:id,name', 'branches:id,name']));
    }

    public function update(UserRequest $request, User $user)
    {
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }
        $isSuperAdmin = $user->hasRole(['super-admin', 'admin']);
        if (array_key_exists('company_ids', $data)) {
            $user->companies()->sync($data['company_ids'] ?? []);
            // Drop an active context the user no longer has access to (super-admins can access any company).
            if (! $isSuperAdmin && $user->current_company_id && ! in_array($user->current_company_id, $data['company_ids'] ?? [], true)) {
                $user->update(['current_company_id' => null, 'current_branch_id' => null]);
            }
        }
        if (array_key_exists('branch_ids', $data)) {
            $user->branches()->sync($data['branch_ids'] ?? []);
            if (! $isSuperAdmin && $user->current_branch_id && ! in_array($user->current_branch_id, $data['branch_ids'] ?? [], true)) {
                $user->update(['current_branch_id' => null]);
            }
        }

        return new UserResource($user->load(['roles', 'companies:id,name', 'branches:id,name']));
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
