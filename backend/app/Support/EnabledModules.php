<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;

/**
 * The tenant's effective module set, and the bridge from a permission group to
 * the module it belongs to.
 *
 * Two callers need this: MenuController (which menu rows to show) and
 * RoleController (which permission groups a tenant may assign). Keeping the
 * resolution here means the nav and the permission matrix can never disagree
 * about what a tenant has switched on.
 */
final class EnabledModules
{
    /** How long the permission-prefix → module map is cached (seconds). */
    private const MAP_TTL = 600;

    /**
     * What the tenant chose in onboarding, narrowed to what its plan sells.
     *
     * Returns null when the tenant never chose a module set, so gating is a
     * no-op (fail open) for tenants provisioned before module selection existed.
     *
     * @return list<string>|null
     */
    public static function forTenant(?int $tenantId): ?array
    {
        $raw = Setting::where('key', 'enabled_modules')->value('value');
        if (empty($raw)) {
            return null;
        }

        $modules = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($modules) || $modules === []) {
            return null;
        }

        $modules = array_values($modules);

        // A downgrade must take effect even though the stored selection still
        // lists the old package's modules.
        $plan = $tenantId
            ? Subscription::with('plan')->where('tenant_id', $tenantId)->latest('id')->first()?->plan
            : null;

        if ($plan) {
            $modules = array_values(array_intersect($modules, $plan->allowedModules()));
        }

        return $modules !== [] ? $modules : null;
    }

    /**
     * Permission group (the part before the last dot — "products", "tailors", …)
     * → module key, derived from the global menu catalogue: every menu row
     * carries a permission, and the module comes from the row or its nearest
     * tagged ancestor. Groups with no menu row (or under an untagged branch) are
     * absent from the map and count as core — always available.
     *
     * @return array<string, string>
     */
    public static function permissionGroupMap(): array
    {
        return Cache::remember('permission_group_modules', self::MAP_TTL, function (): array {
            $rows = MenuItem::whereNull('tenant_id')->get(['id', 'parent_id', 'permission', 'module']);
            $byId = $rows->keyBy('id');

            $moduleOf = function (MenuItem $row) use ($byId): ?string {
                $cursor = $row;
                $guard = 0;
                while ($cursor && $guard++ < 20) {
                    if ($cursor->module) {
                        return $cursor->module;
                    }
                    $cursor = $cursor->parent_id ? $byId->get($cursor->parent_id) : null;
                }

                return null;
            };

            $map = [];
            foreach ($rows as $row) {
                if (! $row->permission) {
                    continue;
                }

                $group = self::groupOf($row->permission);
                $module = $moduleOf($row);

                // A group reachable from an untagged branch stays core: if any
                // menu row exposes it without a module, it can never be hidden.
                if ($module === null) {
                    $map[$group] = null;
                } elseif (! array_key_exists($group, $map)) {
                    $map[$group] = $module;
                }
            }

            return array_filter($map, static fn (?string $m) => $m !== null);
        });
    }

    /** "products.view" → "products"; "dashboard" → "dashboard". */
    public static function groupOf(string $permission): string
    {
        $pos = strrpos($permission, '.');

        return $pos === false ? $permission : substr($permission, 0, $pos);
    }

    /**
     * Is this permission group available to the tenant? Core groups (not tied to
     * any module) and every group when gating is off are always available.
     *
     * @param  list<string>|null  $enabled
     * @param  array<string, string>  $map
     */
    public static function groupAvailable(string $group, ?array $enabled, array $map): bool
    {
        if ($enabled === null) {
            return true;
        }

        $module = $map[$group] ?? null;

        return $module === null || in_array($module, $enabled, true);
    }
}
