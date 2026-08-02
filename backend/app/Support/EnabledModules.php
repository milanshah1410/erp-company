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
     * What the tenant chose in onboarding, narrowed to what its plan sells and
     * widened by the Core ERP set every workspace gets.
     *
     * Core is unioned here rather than only at write time so it reaches tenants
     * provisioned before it existed, and so no stored selection, downgrade or
     * operator edit can leave a workspace without Sales, Purchase, Inventory,
     * Accounts, CRM or the assistant.
     *
     * Returns null when the tenant never chose a module set, so gating is a
     * no-op (fail open) for tenants provisioned before module selection existed.
     *
     * @return list<string>|null
     */
    public static function forTenant(?int $tenantId): ?array
    {
        // Settings are tenant-scoped and the scope fails closed, so outside a
        // tenant context there is nothing to read — and nothing to gate.
        if (! app(TenantContext::class)->has()) {
            return null;
        }

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
            // The plan is the ceiling, plus whatever the platform operator has
            // comped this workspace on /platform/tenants → Access, plus whatever
            // its marketplace packs unlock. Without that union an operator could
            // switch a module on and watch it come straight back off, since this
            // narrowing runs on every read.
            $allowed = array_merge($plan->allowedModules(), self::operatorGrants(), self::packModules());
            $modules = array_values(array_intersect($modules, $allowed));
        }

        // Core last, so it survives the narrowing above unconditionally. A
        // selection that narrows to nothing now reads as core-only rather than as
        // "no selection, show everything" — the tenant did choose, and what it
        // chose no longer includes anything its plan sells.
        return Modules::withCore($modules);
    }

    /**
     * Modules the platform operator granted beyond what the plan sells.
     *
     * Kept in its own setting rather than folded into `enabled_modules` so a
     * later downgrade still narrows everything else: only what an operator
     * deliberately comped survives it.
     *
     * @return list<string>
     */
    public static function operatorGrants(): array
    {
        $raw = Setting::where('key', 'module_grants')->value('value');
        if (empty($raw)) {
            return [];
        }

        $grants = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($grants) ? array_values($grants) : [];
    }

    /**
     * Modules unlocked by the workspace's marketplace packs (Volume 11 §Module
     * Marketplace).
     *
     * Read from the `pack_modules` setting rather than from the pack tables:
     * PackEntitlementService projects the tenant's live grants into it whenever one
     * changes, which keeps this — a hot path, hit on every menu and permission
     * read — one tenant-scoped lookup instead of a cross-connection join.
     *
     * @return list<string>
     */
    public static function packModules(): array
    {
        $raw = Setting::where('key', 'pack_modules')->value('value');
        if (empty($raw)) {
            return [];
        }

        $modules = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($modules) ? array_values($modules) : [];
    }

    /**
     * Permission group (the part before the last dot — "products", "tailors", …)
     * → the modules that expose it, derived from the global menu catalogue: every
     * menu row carries a permission, and the module comes from the row or its
     * nearest tagged ancestor. Groups with no menu row (or under an untagged
     * branch) are absent from the map and count as core — always available.
     *
     * A group can be owned by several modules, and is then available while *any*
     * owner is enabled. POS is why this is a list rather than one key: a counter
     * sale is a sale, so the POS row carries `sales.create` while living under the
     * Sales branch. Collapsing that to "core, never gate" (the previous rule) left
     * every `sales.*` endpoint open even for a workspace with both Sales and POS
     * switched off.
     *
     * @return array<string, list<string>>
     */
    public static function permissionGroupMap(): array
    {
        // Cache key is versioned: the stored shape changed from string to list,
        // and a warm cache from the old shape would be read as garbage.
        return Cache::remember('permission_group_modules_v2', self::MAP_TTL, function (): array {
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
                // null is sticky — once core, a later tagged row can't re-gate it.
                if ($module === null) {
                    $map[$group] = null;

                    continue;
                }

                if (! array_key_exists($group, $map)) {
                    $map[$group] = [$module];
                } elseif (is_array($map[$group]) && ! in_array($module, $map[$group], true)) {
                    $map[$group][] = $module;
                }
            }

            return array_map(
                static fn (array $owners) => array_values($owners),
                array_filter($map, static fn (?array $owners) => $owners !== null),
            );
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
     * any module) and every group when gating is off are always available; an
     * owned group needs at least one of its owning modules switched on.
     *
     * @param  list<string>|null  $enabled
     * @param  array<string, list<string>>  $map
     */
    public static function groupAvailable(string $group, ?array $enabled, array $map): bool
    {
        if ($enabled === null) {
            return true;
        }

        $owners = $map[$group] ?? [];

        return $owners === [] || array_intersect($owners, $enabled) !== [];
    }

    /**
     * The module to name when refusing this permission group — the first owner, or
     * null for a core group. Used for the 403 message, nothing else.
     *
     * @param  array<string, list<string>>  $map
     */
    public static function owningModule(string $group, array $map): ?string
    {
        return ($map[$group] ?? [])[0] ?? null;
    }
}
