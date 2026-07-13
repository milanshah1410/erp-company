<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenant;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Notifications\VerifyTenantEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    /**
     * Live email-availability check for the signup form.
     * GET /api/check-email?email=owner@acme.com
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->query('email', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['available' => false, 'reason' => 'Enter a valid email address.']);
        }

        if ($reason = $this->emailError($email)) {
            return response()->json(['available' => false, 'reason' => $reason]);
        }

        return response()->json(['available' => true]);
    }

    /**
     * Submit a signup. Creates a pending registration and emails a
     * verification code + link. The tenant is NOT provisioned until the
     * email is verified.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'plan_slug' => ['nullable', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
        ]);

        $email = strtolower(trim($data['email']));
        if ($reason = $this->emailError($email)) {
            return response()->json([
                'message' => 'That email address is not available.',
                'errors' => ['email' => [$reason]],
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        $registration = PendingRegistration::create([
            'company_name' => $data['company_name'],
            'admin_name' => $data['admin_name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed via cast
            'plan_slug' => $data['plan_slug'] ?? 'starter',
            'verification_code' => $code,
            'verification_token' => Str::random(48),
            'status' => 'pending_verification',
        ]);

        Notification::route('mail', $registration->email)
            ->notify(new VerifyTenantEmail($registration, $code));

        return response()->json([
            'message' => 'Account created. Check your email for the verification code.',
            'registration_id' => $registration->id,
            // Surfaced only in local/dev so the flow is testable without real SMTP.
            'dev_code' => app()->environment('local') ? $code : null,
        ], 201);
    }

    /**
     * Verify the emailed code (or signed token) and kick off provisioning.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'code' => ['required_without:token', 'nullable', 'string'],
            'token' => ['required_without:code', 'nullable', 'string'],
        ]);

        $registration = PendingRegistration::findOrFail($data['registration_id']);

        if ($registration->isVerified()) {
            return $this->statusPayload($registration);
        }

        $matches = (! empty($data['code']) && hash_equals((string) $registration->verification_code, (string) $data['code']))
            || (! empty($data['token']) && hash_equals((string) $registration->verification_token, (string) $data['token']));

        if (! $matches) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        $registration->forceFill([
            'verified_at' => now(),
            'status' => 'provisioning',
        ])->save();

        ProvisionTenant::dispatch($registration->id);

        return $this->statusPayload($registration);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['registration_id' => ['required', 'integer']]);
        $registration = PendingRegistration::findOrFail($data['registration_id']);

        if ($registration->isVerified()) {
            return response()->json(['message' => 'This account is already verified.']);
        }

        $code = (string) random_int(100000, 999999);
        $registration->update(['verification_code' => $code]);

        Notification::route('mail', $registration->email)
            ->notify(new VerifyTenantEmail($registration, $code));

        return response()->json([
            'message' => 'Verification code re-sent.',
            'dev_code' => app()->environment('local') ? $code : null,
        ]);
    }

    /**
     * Poll while the provisioning job runs (or after it finishes).
     * GET /api/provisioning-status/{registration}
     */
    public function provisioningStatus(PendingRegistration $registration): JsonResponse
    {
        return $this->statusPayload($registration);
    }

    private function statusPayload(PendingRegistration $registration): JsonResponse
    {
        return response()->json([
            'status' => $registration->status,
            'error' => $registration->error,
            'tenant_id' => $registration->tenant_id,
            'login_url' => $registration->status === 'active'
                ? (string) config('app.frontend_url', config('app.url'))
                : null,
        ]);
    }

    /**
     * Returns a human-readable reason the email can't be used, or null if it's
     * available. Email is globally unique across all tenants (login is by email
     * alone), so it must not already belong to a user or a live registration.
     */
    private function emailError(string $email): ?string
    {
        $takenByUser = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->exists();

        if ($takenByUser) {
            return 'An account with that email already exists.';
        }

        $takenByPending = PendingRegistration::where('email', $email)
            ->where('status', '!=', 'failed')
            ->exists();

        if ($takenByPending) {
            return 'A signup with that email is already in progress.';
        }

        return null;
    }
}
