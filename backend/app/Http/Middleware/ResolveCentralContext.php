<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CentralUser;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the platform-operator context for an authenticated central
 * request. MUST run after `auth:central` so $request->user() is resolved.
 *
 * Central operators are not tenant-scoped, so — unlike ResolveTenant — this
 * pins Spatie's team scope to the reserved central sentinel team, which is
 * where every central role/permission lives. It also fails closed on a
 * deactivated operator.
 */
class ResolveCentralContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();

        if (! $operator || ! $operator->is_active) {
            return response()->json([
                'message' => 'This operator account is disabled.',
                'error' => 'operator_inactive',
            ], 403);
        }

        // Central roles/permissions live under the sentinel team. Set it
        // explicitly so a stale tenant team id can never leak in from another
        // request on a pooled worker.
        app(PermissionRegistrar::class)->setPermissionsTeamId(CentralUser::TEAM_ID);

        return $next($request);
    }
}
