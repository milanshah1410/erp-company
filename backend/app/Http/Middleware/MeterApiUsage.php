<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use App\Support\UsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts one API call per tenant request (Volume 11 §"Tenant Usage" → API Calls).
 *
 * Metering happens in terminate(), after the response has been sent, so the
 * counter can never add latency to the call it is measuring — and a metering
 * failure can never turn a successful request into a 500 (UsageMeter::hit
 * swallows its own errors for the same reason).
 *
 * What counts as an API call: any request that resolved to a tenant, whatever
 * the outcome. Failed and rejected calls count too — they consumed the platform
 * just the same, and a tenant hammering a 422 in a loop is exactly the pattern
 * this number exists to make visible.
 */
class MeterApiUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // ResolveTenant runs earlier in the stack; if it found nobody there is
        // nothing to attribute the call to (public routes, login, webhooks).
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId !== null) {
            UsageMeter::hit(UsageMeter::API_CALLS, 1, $tenantId);
        }
    }
}
