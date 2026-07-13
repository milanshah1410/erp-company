<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for an authenticated request. MUST run after
 * `auth:sanctum` so $request->user() is resolved. Reads the user's tenant_id,
 * activates the row-level TenantContext, and points spatie's team scope at the
 * tenant so role/permission checks resolve per tenant.
 *
 * The resolved Tenant model is stashed on the request for EnsureTenantActive.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tenant_id) {
            return response()->json([
                'message' => 'This account is not linked to a workspace.',
                'error' => 'tenant_missing',
            ], 403);
        }

        // Tenant model is landlord-owned (no tenant scope), so this lookup is safe.
        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant) {
            return response()->json([
                'message' => 'Your workspace could not be found.',
                'error' => 'tenant_not_found',
            ], 403);
        }

        app(TenantContext::class)->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
