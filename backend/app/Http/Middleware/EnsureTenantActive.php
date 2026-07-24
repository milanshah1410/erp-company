<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspension enforcement, with two severities depending on who suspended:
 *
 * - Billing suspension (trial lapsed, payment halted): grace period. The
 *   workspace goes read-only — GET/HEAD still work so the user can see their
 *   data and the upgrade screen; writes are rejected 402. Billing/subscription
 *   endpoints stay writable so they can recover.
 * - Operator suspension (SaaS-admin action): hard lock. Every request is
 *   rejected 403 regardless of method — only logout still works. Fresh logins
 *   are additionally blocked at the auth controllers.
 */
class EnsureTenantActive
{
    private const WRITABLE_WHILE_SUSPENDED = [
        'api/subscription',
        'api/subscription/change-plan',
        'api/subscription/confirm-payment',
        'api/subscription/pay-now',
        'api/logout',
    ];

    /** Still reachable under an operator hard lock. */
    private const ALLOWED_WHILE_LOCKED = [
        'api/logout',
        'api/portal/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Set by the ResolveTenant middleware, which must run before this one.
        $tenant = $request->attributes->get('tenant');

        if (! $tenant || $tenant->status !== 'suspended') {
            return $next($request);
        }

        if ($tenant->isOperatorSuspended()) {
            foreach (self::ALLOWED_WHILE_LOCKED as $path) {
                if ($request->is($path)) {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'This workspace has been suspended by the platform administrator. Please contact support.',
                'error' => 'tenant_suspended',
                'reason' => 'operator',
            ], 403);
        }

        if (! $this->isReadOnlyRequest($request)) {
            return response()->json([
                'message' => 'Your subscription has lapsed. The workspace is read-only until you choose a plan.',
                'error' => 'tenant_suspended',
                'reason' => 'billing',
                'upgrade_url' => '/billing',
            ], 402);
        }

        return $next($request);
    }

    private function isReadOnlyRequest(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        foreach (self::WRITABLE_WHILE_SUSPENDED as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
