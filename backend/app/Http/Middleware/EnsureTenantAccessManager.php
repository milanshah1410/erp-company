<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CentralUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the per-tenant access panel on the operator's own
 * `central_users.can_manage_tenant_access` flag.
 *
 * This deliberately replaces `permission:tenants.edit,central` on those routes
 * rather than stacking on top of it. Changing what a paying workspace is
 * entitled to is granted per operator, not per role — and a role permission in
 * front of the flag would make the flag useless, since only super-admin and
 * saas-admin carry `tenants.edit`: a flagged support operator would be refused
 * before the flag was ever looked at.
 *
 * Super-admins pass implicitly (CentralUser::canManageTenantAccess), and they are
 * the only ones who may grant the flag to anyone else.
 */
class EnsureTenantAccessManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();

        if (! $operator instanceof CentralUser || ! $operator->canManageTenantAccess()) {
            return response()->json([
                'message' => 'Your operator account is not permitted to manage tenant access. Ask a super-admin to enable "Manage tenant access" for you.',
                'error' => 'tenant_access_not_permitted',
            ], 403);
        }

        return $next($request);
    }
}
