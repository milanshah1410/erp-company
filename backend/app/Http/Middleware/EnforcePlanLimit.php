<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Warehouse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks create actions once the current tenant hits its plan's feature limit,
 * returning a structured upgrade prompt (HTTP 402) rather than a bare 403.
 *
 * Usage:  ->middleware('plan.limit:products')   // products | warehouses | users
 */
class EnforcePlanLimit
{
    /** resource key => [Eloquent model, feature_limits key, human label] */
    private const RESOURCES = [
        'products' => [Product::class, 'max_products', 'products'],
        'warehouses' => [Warehouse::class, 'max_warehouses', 'warehouses'],
        'users' => [User::class, 'max_users', 'users'],
    ];

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        // Outside a tenant context there is nothing to meter.
        if (! function_exists('tenant') || ! tenant()) {
            return $next($request);
        }

        if (! isset(self::RESOURCES[$resource])) {
            return $next($request);
        }

        [$model, $limitKey, $label] = self::RESOURCES[$resource];

        $subscription = Subscription::with('plan')
            ->where('tenant_id', tenant()->getTenantKey())
            ->latest('id')
            ->first();

        // No plan on record → don't block (fail open); provisioning always
        // creates a trialing subscription, so this is only a safety net.
        $plan = $subscription?->plan;
        if (! $plan) {
            return $next($request);
        }

        $limit = $plan->limit($limitKey);

        // null / non-numeric means unlimited.
        if ($limit === null || ! is_numeric($limit)) {
            return $next($request);
        }

        $current = $model::query()->count();

        if ($current >= (int) $limit) {
            return response()->json([
                'message' => "You've reached the {$limit}-{$label} limit on the {$plan->name} plan — upgrade to add more {$label}.",
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
