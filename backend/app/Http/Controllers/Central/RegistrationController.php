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
use App\Services\Billing\CouponService;
use App\Support\Modules;
use App\Support\ProvisionSteps;
use App\Support\ShopProfiles;
use App\Support\Subdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    /**
     * Live promo-code check for the signup / pricing form.
     * GET /api/check-coupon?code=LAUNCH50&plan_slug=business
     *
     * Public and unauthenticated, so it deliberately reveals nothing beyond
     * whether this code discounts this plan and by how much: no redemption
     * counts, no other plans' pricing, and no distinction between "never existed"
     * and "was withdrawn". Never 422s — a wrong code is an answer, not an error,
     * and the form shows the reason inline.
     */
    public function checkCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['valid' => false, 'reason' => 'Enter a promo code.']);
        }

        $plan = Plan::findBySlug((string) $request->query('plan_slug', ''));

        try {
            $quote = $coupons->quote($code, (float) ($plan?->price ?? 0), [
                'plan_id' => $plan?->id,
                'purchase_type' => 'subscription',
                'is_new_tenant' => true,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'valid' => false,
                'reason' => $e->errors()['coupon_code'][0] ?? 'That code cannot be used.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'code' => $quote['coupon']->code,
            'description' => $quote['label'],
            // Only meaningful once a plan is named; 0 otherwise.
            'discount' => $quote['discount'],
            'plan_slug' => $plan?->slug,
        ]);
    }

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
     * Live workspace-address check for the signup form (§5).
     * GET /api/check-subdomain?subdomain=acme
     *
     * Also answers `?company=Acme Traders` with a suggestion, so the field can
     * pre-fill itself from the company name the buyer already typed.
     */
    public function checkSubdomain(Request $request): JsonResponse
    {
        $raw = (string) $request->query('subdomain', '');

        // No address typed yet — suggest one from the company name.
        if (trim($raw) === '' && ($company = (string) $request->query('company', '')) !== '') {
            $suggested = Subdomain::suggest($company);
            $free = $suggested !== '' && Subdomain::error($suggested) === null
                ? $suggested
                : (Subdomain::alternatives($suggested, 1)[0] ?? '');

            return response()->json([
                'available' => false,
                'suggestion' => $free,
                'root_domain' => Subdomain::rootDomain(),
            ]);
        }

        $sub = Subdomain::normalize($raw);
        $reason = Subdomain::error($raw);

        return response()->json([
            'subdomain' => $sub,
            'available' => $reason === null,
            'reason' => $reason,
            // Only worth offering alternatives when the address itself is legal
            // and merely taken — a malformed one needs fixing, not swapping.
            'alternatives' => $reason !== null && Subdomain::isTaken($sub)
                ? Subdomain::alternatives($sub)
                : [],
            'root_domain' => Subdomain::rootDomain(),
            'url' => $reason === null ? Subdomain::url($sub) : null,
        ]);
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
            // Workspace address. Optional on the wire — API clients that don't
            // pick one get a suggestion derived from the company name.
            'subdomain' => ['nullable', 'string', 'max:'.Subdomain::MAX],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'plan_slug' => ['nullable', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
            // Promo code from the pricing page. Validated below rather than here:
            // an unknown or expired code needs a reason, not "invalid".
            'coupon_code' => ['nullable', 'string', 'max:40'],

            // Onboarding-wizard payload (steps 1-6). Consumed by ProvisionTenant.
            'config' => ['nullable', 'array'],
            'config.industry' => ['nullable', 'string', 'max:50'],
            'config.shop_profile' => ['nullable', 'string', Rule::in(ShopProfiles::keys())],
            'config.company' => ['nullable', 'array'],
            'config.modules' => ['nullable', 'array'],
            'config.modules.*' => ['string', Rule::in(Modules::keys())],
            'config.users' => ['nullable', 'array', 'max:20'],
            'config.users.*.name' => ['required_with:config.users', 'string', 'max:255'],
            'config.users.*.email' => ['required_with:config.users', 'email', 'max:255', 'distinct:ignore_case'],
            'config.users.*.role' => ['nullable', 'string', 'max:50'],
            'config.branch' => ['nullable', 'array'],
            'config.warehouse' => ['nullable', 'array'],
            'config.financial_year' => ['nullable', 'array'],
            'config.currency' => ['nullable', 'array'],
            'config.fy_start_month' => ['nullable', 'integer', 'between:1,12'],
            'config.number_prefixes' => ['nullable', 'array'],
            'config.tax' => ['nullable', 'array'],
            'config.load_demo' => ['nullable', 'boolean'],
            'config.demo_count' => ['nullable', 'integer'],
        ]);

        // findBySlug: a retired slug still arriving from an old pricing link
        // resolves to the tier that replaced it (Plan::LEGACY_SLUGS).
        $plan = Plan::findBySlug($data['plan_slug'] ?? null);

        // A signup that named its trade but sent no module list gets the trade's
        // preset, narrowed to what the package sells. Covers older wizard builds
        // and API clients that only answer "what kind of shop is this?". Narrowed
        // rather than rejected: the caller never picked these, so a preset that
        // reaches past a Starter plan should quietly shrink, not 422.
        if (empty($data['config']['modules']) && ! empty($data['config']['shop_profile'])) {
            $preset = ShopProfiles::modulesFor($data['config']['shop_profile']);
            $data['config']['modules'] = $plan
                ? array_values(array_intersect($preset, $plan->allowedModules()))
                : $preset;
        }

        // The Core ERP set is every workspace's floor, whatever the wizard sent
        // and whatever the package is — a signup that unticked half the grid still
        // gets Sales, Purchase, Inventory, Accounts, CRM and the assistant. Added
        // before the plan check below, which is a no-op for core because
        // Plan::allowedModules() unions it too.
        //
        // Only when a selection was actually made: a payload with no module list
        // and no trade stores nothing, and a tenant with no stored selection is
        // ungated entirely (EnabledModules::forTenant returns null) — which is
        // more than core, not less.
        if (! empty($data['config']['modules'])) {
            $data['config']['modules'] = Modules::withCore($data['config']['modules']);
        }

        // Module set must fit the chosen package: only Enterprise ('*') unlocks
        // the whole catalogue, so a tampered payload can't smuggle in modules the
        // plan does not sell.
        $chosenModules = $data['config']['modules'] ?? [];
        if ($plan && $chosenModules) {
            $extra = array_values(array_diff($chosenModules, $plan->allowedModules()));
            if ($extra !== []) {
                $labels = array_map(
                    static fn (string $k) => Modules::CATALOGUE[$k]['label'] ?? $k,
                    $extra,
                );

                return response()->json([
                    'message' => 'Some selected modules are not available on the ' . $plan->name . ' plan.',
                    'errors' => ['config.modules' => [
                        implode(', ', $labels) . ' need a higher plan. Upgrade to Enterprise for the full module set.',
                    ]],
                ], 422);
            }
        }

        // Workspace address (§5). Re-validated here, not just in the browser:
        // the live check is a convenience, this is the gate. An unpicked address
        // falls back to the company-name suggestion, then to a free variant of
        // it, so a signup never fails purely for want of a subdomain.
        $subdomain = Subdomain::normalize((string) ($data['subdomain'] ?? ''));
        if ($subdomain === '') {
            $suggested = Subdomain::suggest($data['company_name']);
            $subdomain = $suggested !== '' && Subdomain::error($suggested) === null
                ? $suggested
                : (Subdomain::alternatives($suggested ?: 'workspace', 1)[0] ?? '');
        }
        if ($reason = Subdomain::error($subdomain)) {
            return response()->json([
                'message' => 'That workspace address is not available.',
                'errors' => ['subdomain' => [$reason]],
            ], 422);
        }

        $email = strtolower(trim($data['email']));
        if ($reason = $this->emailError($email)) {
            return response()->json([
                'message' => 'That email address is not available.',
                'errors' => ['email' => [$reason]],
            ], 422);
        }

        // Extra-user emails must be free too (email is the global login identity)
        // and must not clash with the owner's.
        foreach ($data['config']['users'] ?? [] as $i => $u) {
            $uEmail = strtolower(trim((string) ($u['email'] ?? '')));
            if ($uEmail === $email || $this->emailError($uEmail)) {
                return response()->json([
                    'message' => 'A team member email is not available.',
                    'errors' => ["config.users.{$i}.email" => ['This email is already in use.']],
                ], 422);
            }
        }

        // A promo code is checked at signup so a bad one is reported here, on the
        // form that typed it, rather than silently failing at provisioning. It is
        // not redeemed yet — the coupon is spent when the first invoice is raised,
        // which may be after a 14-day trial the signup never converts.
        $coupon = null;
        if (trim((string) ($data['coupon_code'] ?? '')) !== '') {
            $coupon = app(CouponService::class)->validate($data['coupon_code'], [
                'plan_id' => $plan?->id,
                'amount' => (float) ($plan?->price ?? 0),
                'purchase_type' => 'subscription',
                // No tenant exists yet, so this signup is a first subscription by
                // definition.
                'is_new_tenant' => true,
            ]);
        }

        $code = (string) random_int(100000, 999999);

        $config = $data['config'] ?? [];
        if ($coupon) {
            // Carried on the wizard payload ProvisionTenant already consumes, so
            // no extra column is needed on the pending row.
            $config['coupon_code'] = $coupon->code;
        }

        $registration = PendingRegistration::create([
            'company_name' => $data['company_name'],
            'subdomain' => $subdomain,
            'admin_name' => $data['admin_name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed via cast
            // Store the resolved slug, so a registration that sat in the queue
            // records the tier it will actually be provisioned on.
            'plan_slug' => $plan?->slug ?? 'starter',
            'config' => $config ?: null,
            'verification_code' => $code,
            'verification_token' => Str::random(48),
            'status' => 'pending_verification',
        ]);

        Notification::route('mail', $registration->email)
            ->notify(new VerifyTenantEmail($registration, $code));

        return response()->json([
            'message' => 'Account created. Check your email for the verification code.',
            'registration_id' => $registration->id,
            'subdomain' => $subdomain,
            'workspace_url' => Subdomain::url($subdomain),
            // Surfaced only in local/dev so the flow is testable without real SMTP.
            'dev_code' => app()->environment('local') ? $code : null,
        ], 201);
    }

    /**
     * Stash a company logo chosen during signup (before any tenant exists) on
     * the public disk. ProvisionTenant later records the URL as a tenant
     * setting. Returns the stored path + a public URL for the wizard preview.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('pending-logos', 'public');

        return response()->json([
            'logo_path' => $path,
            'logo_url' => Storage::disk('public')->url($path),
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
            'company_name' => $registration->company_name,
            'subdomain' => $registration->subdomain,
            // The eight onboarding phases with what each one actually did, so
            // the provisioning screen reports real progress (Volume 11
            // §"Onboarding Automation"). All-pending until the job starts.
            'steps' => ProvisionSteps::forRegistration($registration),
            'workspace_url' => $registration->subdomain ? Subdomain::url($registration->subdomain) : null,
            'login_url' => $registration->status === 'active'
                ? rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/login'
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
