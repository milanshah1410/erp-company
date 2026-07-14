<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the B2B customer-portal API. Runs after `auth:sanctum`: the resolved
 * user must be a Customer whose token carries the 'portal' ability, and whose
 * portal access is still enabled. This keeps staff bearer tokens (a User
 * tokenable) out of the portal, and portal tokens out of the staff API (which
 * is separately gated by spatie permissions a Customer never holds).
 */
class EnsurePortalCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Customer || ! $request->user()->tokenCan('portal')) {
            return response()->json([
                'message' => 'This endpoint is only available to B2B portal accounts.',
                'error' => 'not_a_portal_customer',
            ], 403);
        }

        if (! $user->canUsePortal()) {
            return response()->json([
                'message' => 'Your portal access has been disabled. Please contact us.',
                'error' => 'portal_disabled',
            ], 403);
        }

        return $next($request);
    }
}
