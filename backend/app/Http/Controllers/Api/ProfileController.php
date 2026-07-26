<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own profile. Deliberately permission-free: every user may
 * maintain their own name/phone/email/photo and change their own password, which
 * is what lets the trimmed signup wizard (3 steps) leave the rest for later.
 *
 * Editing *other* users stays with UserController behind `users.*` permissions.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    /** Update identity fields. Email doubles as the login, so it stays unique. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Table-name form on purpose: email is globally unique across tenants
            // (unique index on users.email), and a table rule bypasses the model's
            // tenant scope, so a clash in another tenant is still caught.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $emailChanged = strtolower($data['email']) !== strtolower((string) $user->email);

        $user->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
        ]);

        // A new address has not been proven yet — drop the verified stamp so any
        // verification-gated flow re-checks it.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        activity('profile')->causedBy($user)->event('updated')->log('Profile updated');

        return response()->json([
            'message' => 'Profile updated.',
            'data' => $this->payload($user),
        ]);
    }

    /** Change own password; the current one must be supplied. */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $user->update(['password' => $data['password']]); // hashed via cast

        activity('profile')->causedBy($user)->event('password-changed')->log('Password changed');

        return response()->json(['message' => 'Password changed.']);
    }

    /** Replace the profile photo (private tenant storage, signed URL on read). */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:2048']]); // 2 MB

        $user = $request->user();
        $disk = TenantStorage::disk();

        if ($user->avatar && $disk->exists($user->avatar)) {
            $disk->delete($user->avatar);
        }

        $user->update(['avatar' => $disk->putFile('avatars', $request->file('avatar'))]);

        return response()->json([
            'message' => 'Photo updated.',
            'data' => $this->payload($user),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $disk = TenantStorage::disk();

        if ($user->avatar && $disk->exists($user->avatar)) {
            $disk->delete($user->avatar);
        }

        $user->update(['avatar' => null]);

        return response()->json(['message' => 'Photo removed.', 'data' => $this->payload($user)]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => TenantStorage::temporaryUrl($user->avatar),
            'roles' => $user->getRoleNames(),
            'email_verified' => $user->email_verified_at !== null,
            'current_company' => $user->currentCompany?->only(['id', 'name']),
            'current_branch' => $user->currentBranch?->only(['id', 'name']),
        ];
    }
}
