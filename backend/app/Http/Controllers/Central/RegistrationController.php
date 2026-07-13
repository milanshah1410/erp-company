<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenant;
use App\Models\PendingRegistration;
use App\Models\Plan;
use App\Notifications\VerifyTenantEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\Models\Domain;

class RegistrationController extends Controller
{
    /**
     * Live subdomain-availability check for the signup form.
     * GET /api/check-subdomain?subdomain=acme
     */
    public function checkSubdomain(Request $request): JsonResponse
    {
        $subdomain = strtolower(trim((string) $request->query('subdomain', '')));

        if ($reason = $this->subdomainError($subdomain)) {
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
            'subdomain' => ['required', 'string'],
            'plan_slug' => ['nullable', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
        ]);

        $subdomain = strtolower(trim($data['subdomain']));
        if ($reason = $this->subdomainError($subdomain)) {
            return response()->json([
                'message' => 'That subdomain is not available.',
                'errors' => ['subdomain' => [$reason]],
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        $registration = PendingRegistration::create([
            'company_name' => $data['company_name'],
            'admin_name' => $data['admin_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed via cast
            'subdomain' => $subdomain,
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
        $scheme = request()->getScheme();
        $port = request()->getPort();
        $portSuffix = in_array($port, [80, 443, null], true) ? '' : ':'.$port;
        $host = $registration->subdomain.'.'.config('saas.tenant_domain');

        return response()->json([
            'status' => $registration->status,
            'error' => $registration->error,
            'tenant_id' => $registration->tenant_id,
            'subdomain' => $registration->subdomain,
            'login_url' => $registration->status === 'active'
                ? "{$scheme}://{$host}{$portSuffix}"
                : null,
        ]);
    }

    /**
     * Returns a human-readable reason the subdomain can't be used, or null if
     * it's available. Enforces format, reserved list, and uniqueness.
     */
    private function subdomainError(string $subdomain): ?string
    {
        if ($subdomain === '') {
            return 'Please choose a subdomain.';
        }

        if (! preg_match(config('saas.subdomain_regex'), $subdomain)) {
            return 'Use 3-30 lowercase letters, numbers or hyphens (not starting or ending with a hyphen).';
        }

        if (in_array($subdomain, config('saas.reserved_subdomains'), true)) {
            return 'That subdomain is reserved.';
        }

        if (Domain::where('domain', $subdomain)->exists()) {
            return 'That subdomain is already taken.';
        }

        $takenByPending = PendingRegistration::where('subdomain', $subdomain)
            ->where('status', '!=', 'failed')
            ->exists();

        if ($takenByPending) {
            return 'That subdomain is already taken.';
        }

        return null;
    }
}
