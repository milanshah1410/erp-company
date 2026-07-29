<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EnabledModules;
use App\Support\Modules;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `permission:` middleware, extended with the tenant's entitlement — both
 * halves of it: the module set (App\Support\EnabledModules) and the operator's
 * per-screen menu grants (App\Support\TenantMenuAccess).
 *
 * Holding a permission is no longer enough on its own. If the module behind it is
 * switched off, or the operator revoked the screen, the request is refused before
 * Spatie's own check runs. Registered under the `permission` alias in
 * bootstrap/app.php, which is what makes this exhaustive: the `module:` middleware
 * only ever went on ~57 of the ~1300 tenant routes, so switching a module off hid
 * its menu rows while leaving its endpoints answering normally.
 *
 * Two paths deliberately pass straight through:
 *   - the central guard (`permission:tenants.view,central`) — platform operators
 *     are not tenants and have neither a module set nor a menu of their own;
 *   - tenants with no module selection and no menu rows of their own — nothing has
 *     been withheld from them, the same fail-open rule the nav follows.
 */
class CheckPermission extends PermissionMiddleware
{
    public function handle(Request $request, Closure $next, $permission, ?string $guard = null): Response
    {
        $tenantId = app(TenantContext::class)->id();
        $enabled = EnabledModules::forTenant($tenantId);
        $revoked = TenantMenuAccess::revokedGroups($tenantId);

        if ($enabled !== null || $revoked !== []) {
            $map = EnabledModules::permissionGroupMap();

            // Spatie treats "a|b" as "any of these", so the door is only closed
            // when every listed permission is withheld.
            $names = array_map('trim', explode('|', is_array($permission) ? implode('|', $permission) : (string) $permission));

            $offModule = null;
            $blocked = array_filter($names, function (string $name) use ($enabled, $map, $revoked, &$offModule) {
                $group = EnabledModules::groupOf($name);

                if (! EnabledModules::groupAvailable($group, $enabled, $map)) {
                    $offModule ??= EnabledModules::owningModule($group, $map);

                    return true;
                }

                return TenantMenuAccess::isRevoked($name, $revoked);
            });

            if (count($blocked) === count($names)) {
                // Name the module when that is what closed the door, so the
                // message matches EnsureModuleEnabled's wording on the routes
                // that carry it explicitly.
                $label = $offModule ? (Modules::CATALOGUE[$offModule]['label'] ?? $offModule) : null;

                return response()->json($label ? [
                    'message' => "The {$label} module is not enabled for this workspace.",
                    'error' => 'module_disabled',
                    'modules' => [$offModule],
                ] : [
                    'message' => 'This screen has been disabled for your workspace by the platform administrator.',
                    'error' => 'menu_revoked',
                    'permissions' => array_values($names),
                ], 403);
            }
        }

        return parent::handle($request, $next, $permission, $guard);
    }
}
