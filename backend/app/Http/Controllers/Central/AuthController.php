<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Authentication for the SaaS-owner platform panel (Level-1 RBAC). Issues a
 * Sanctum token bound to a CentralUser; that token authenticates on the
 * `central` guard (config/auth.php).
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $operator = CentralUser::query()->where('email', $credentials['email'])->first();

        if (! $operator || ! Hash::check($credentials['password'], $operator->password)) {
            activity('central-auth')
                ->withProperties(['ip' => $request->ip(), 'email' => $credentials['email']])
                ->event('login_failed')->log('Failed platform login attempt');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $operator->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This operator account is disabled.'],
            ]);
        }

        // Central roles/permissions live under the sentinel team — resolve there.
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);

        $token = $operator->createToken('central')->plainTextToken;

        activity('central-auth')->causedBy($operator)
            ->withProperties(['ip' => $request->ip()])->event('login')->log('Operator logged in');

        return response()->json([
            'token' => $token,
            'user' => $operator->sessionPayload(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->sessionPayload());
    }

    public function logout(Request $request): JsonResponse
    {
        activity('central-auth')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip()])->event('logout')->log('Operator logged out');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
