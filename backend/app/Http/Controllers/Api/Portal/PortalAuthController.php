<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Authentication for the B2B customer portal (SRS §14). Mirrors the staff
 * AuthController but authenticates a Customer against its portal credentials
 * and issues a Sanctum token limited to the 'portal' ability.
 */
class PortalAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Login runs before any tenant context exists. Portal-enabled customers
        // have a globally-unique email (enforced when access is granted), so
        // look up across all tenants and expect at most one match.
        $customer = Customer::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $credentials['email'])
            ->where('portal_enabled', true)
            ->first();

        if (! $customer || empty($customer->portal_password)
            || ! Hash::check($credentials['password'], $customer->portal_password)) {
            activity('portal-auth')
                ->withProperties(['ip' => $request->ip(), 'email' => $credentials['email']])
                ->event('login_failed')->log('Failed B2B portal login');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        // Establish the tenant so tenant-scoped reads (settings, products,
        // stock, pricing) resolve for the rest of the request.
        app(TenantContext::class)->set($customer->tenant_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($customer->tenant_id);

        $token = $customer->createToken('portal', ['portal'])->plainTextToken;
        $customer->forceFill(['portal_last_login_at' => now()])->saveQuietly();

        activity('portal-auth')->causedBy($customer)
            ->withProperties(['ip' => $request->ip()])->event('login')->log('B2B portal login');

        return response()->json([
            'token' => $token,
            'customer' => $this->payload($customer),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** Identity + credit summary + storefront branding for the portal session. */
    private function payload(Customer $customer): array
    {
        $tenant = Tenant::find($customer->tenant_id);

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'company_name' => $customer->company_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'type' => $customer->type,
            'price_level' => $customer->effectivePriceLevel(),
            'credit_limit' => (float) $customer->credit_limit,
            'outstanding_balance' => $customer->outstandingBalance(),
            'available_credit' => $customer->availableCredit(),
            'business_name' => $tenant?->company_name,
            'currency_symbol' => Setting::currencySymbol(),
            'currency_code' => Setting::currencyCode(),
        ];
    }
}
