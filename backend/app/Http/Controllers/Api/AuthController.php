<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    /**
     * Issue a Sanctum personal access token for valid credentials.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Login runs before any tenant context exists, so look the user up
        // across all tenants (email is globally unique — exactly one match).
        $user = \App\Models\User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
            activity('auth')->withProperties(['ip' => request()->ip(), 'email' => $request->input('email')])->event('login_failed')->log('Failed login attempt');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        // Operator-suspended workspaces are hard-locked: no fresh sessions.
        // (Billing-suspended tenants may still log in — the workspace is
        // read-only and they need access to the billing screen to recover.)
        $tenant = \App\Models\Tenant::find($user->tenant_id);
        if ($tenant && $tenant->isOperatorSuspended()) {
            activity('auth')->withProperties(['ip' => request()->ip(), 'email' => $user->email, 'tenant_id' => $tenant->id])
                ->event('login_blocked')->log('Login rejected: workspace suspended by operator');

            throw ValidationException::withMessages([
                'email' => ['This workspace has been suspended by the platform administrator. Please contact support.'],
            ]);
        }

        // Establish the tenant so the session payload (roles/permissions,
        // companies/branches — all tenant-scoped) resolves correctly.
        app(TenantContext::class)->set($user->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        $token = $user->createToken('api')->plainTextToken;

        activity('auth')->causedBy($user)->withProperties(['ip' => request()->ip()])->event('login')->log('User logged in');

        return response()->json([
            'token' => $token,
            'user' => $user->sessionPayload(),
        ]);
    }

    /**
     * Return the authenticated user with roles, permissions, and company/branch context.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->sessionPayload());
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        activity('auth')->causedBy($request->user())->withProperties(['ip' => request()->ip()])->event('logout')->log('User logged out');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
