<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

        $user = \App\Models\User::where('email', $credentials['email'])->first();

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
