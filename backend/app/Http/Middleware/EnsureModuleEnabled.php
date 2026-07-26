<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EnabledModules;
use App\Support\Modules;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an endpoint behind the tenant's enabled module set (onboarding wizard §3).
 *
 * MenuController already hides a module's menu rows, but a hidden row is not a
 * closed door: a bookmark, a favorite, a stale recent or a hand-typed URL still
 * reaches the API. This is the enforcement half of the same rule, so the two can
 * never disagree — both resolve through App\Support\EnabledModules.
 *
 * Usage:  ->middleware('module:inventory')
 *         ->middleware('module:gst,purchase')   // any one of them is enough
 *
 * The multi-key form exists for screens that read across modules — the GST report
 * toggles between input (purchase) and output (sales) tax, so either module keeps
 * it reachable.
 *
 * Fails open when the tenant never chose a module set (EnabledModules::forTenant
 * returns null) — the same rule the nav follows for tenants provisioned before
 * module selection existed.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $enabled = EnabledModules::forTenant(app(TenantContext::class)->id());

        if ($enabled === null || $modules === []) {
            return $next($request);
        }

        foreach ($modules as $module) {
            if (in_array($module, $enabled, true)) {
                return $next($request);
            }
        }

        $labels = array_map(
            static fn (string $m) => Modules::CATALOGUE[$m]['label'] ?? $m,
            $modules,
        );

        return response()->json([
            'message' => count($labels) === 1
                ? "The {$labels[0]} module is not enabled for this workspace."
                : 'None of these modules are enabled for this workspace: '.implode(', ', $labels).'.',
            'error' => 'module_disabled',
            'modules' => array_values($modules),
        ], 403);
    }
}
