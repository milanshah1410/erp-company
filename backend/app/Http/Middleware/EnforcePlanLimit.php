<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use App\Support\TenantLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks create actions once the current tenant hits its effective resource
 * limit, returning a structured upgrade prompt (HTTP 402) rather than a bare 403.
 *
 * Usage:  ->middleware('plan.limit:products')   // products | warehouses | users
 *
 * "Effective" is the plan's cap with any per-tenant override applied — see
 * App\Support\TenantLimits, which owns both the resource map and the merge, so
 * the platform panel's Limits dialog and this gate can never quote different
 * numbers at the same customer.
 */
class EnforcePlanLimit
{
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        // Outside a tenant context there is nothing to meter.
        $tenantId = app(TenantContext::class)->id();
        if (! $tenantId) {
            return $next($request);
        }

        if (! isset(TenantLimits::METERED[$resource])) {
            return $next($request);
        }

        [$model, $limitKey, $label] = TenantLimits::METERED[$resource];

        // No plan on record → don't block (fail open); provisioning always
        // creates a trialing subscription, so this is only a safety net.
        $plan = TenantLimits::planFor($tenantId);
        if (! $plan) {
            return $next($request);
        }

        $limit = TenantLimits::limit($tenantId, $limitKey, $plan);

        // null / non-numeric means unlimited — either the plan sets no cap, or an
        // operator lifted it for this one tenant.
        if ($limit === null || ! is_numeric($limit)) {
            return $next($request);
        }

        $current = TenantLimits::count($model, $tenantId);

        if ($current >= (int) $limit) {
            return response()->json([
                'message' => "You've reached the {$limit}-{$label} limit on your current plan — upgrade to add more {$label}.",
                'error' => 'plan_limit_reached',
                'limit' => (int) $limit,
                'current' => $current,
                'resource' => $label,
                'plan' => $plan->slug,
                'upgrade_url' => '/billing',
            ], 402);
        }

        return $next($request);
    }
}
