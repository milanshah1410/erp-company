<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MenuItem;

/**
 * Per-tenant menu entitlement — the platform operator's fine-grained twin of
 * module gating (App\Support\EnabledModules).
 *
 * A module switch turns off a whole slice of the product; this turns off a single
 * screen. Both are set from /platform/tenants, and both are resolved here so the
 * three places that must agree never drift:
 *
 *   1. the sidebar        — MenuController already hides inactive rows;
 *   2. the role matrix    — RoleController stops offering the permissions behind
 *                           a revoked screen (existing grants stay in the
 *                           database, hidden, and come back if it is re-enabled);
 *   3. the API            — App\Http\Middleware\CheckPermission rejects the
 *                           request even when a role still holds the permission,
 *                           because a hidden menu row is not a closed door: a
 *                           bookmark or a hand-typed URL still reaches the API.
 *
 * Revocation is expressed as *permission groups* ("stock-transfers"), not single
 * permissions: revoking the Stock Transfers screen has to take create/edit/delete
 * with it, or the tenant keeps everything except the ability to look.
 */
final class TenantMenuAccess
{
    /** How long a tenant's revoked-group set is cached (seconds). */
    private const TTL = 600;

    /**
     * Permission groups this tenant may not use: groups no *reachable* menu row
     * exposes any more.
     *
     * Returns an empty list — gating off, fail open — for a tenant with no menu
     * rows of its own. Those are the tenants provisioned before per-tenant menus,
     * which the nav serves from the global catalogue; nothing has been revoked
     * for them because there is nothing tenant-owned to revoke.
     *
     * @return list<string>
     */
    public static function revokedGroups(?int $tenantId): array
    {
        if ($tenantId === null || ! app(TenantContext::class)->has()) {
            return [];
        }

        // Read on every gated request, so it is cached; a MenuItem write bumps
        // the 'menu_items' version (InvalidatesTenantCache) and the controller
        // that mass-updates rows bumps it explicitly, since a query-builder
        // update fires no model events.
        return TenantCache::rememberVersioned('menu_items', 'revoked_permission_groups', self::TTL, function () use ($tenantId): array {
            $rows = MenuItem::where('tenant_id', $tenantId)->get(['id', 'parent_id', 'permission', 'is_active']);
            if ($rows->isEmpty()) {
                return [];
            }

            $byId = $rows->keyBy('id');

            // A row is only reachable if it and every ancestor is active — the
            // same thing the nav does by walking down from the active roots, so
            // switching off "Inventory" revokes the screens beneath it too.
            $reachable = function (MenuItem $row) use ($byId): bool {
                $cursor = $row;
                $guard = 0;
                while ($cursor && $guard++ < 20) {
                    if (! $cursor->is_active) {
                        return false;
                    }
                    $cursor = $cursor->parent_id ? $byId->get($cursor->parent_id) : null;
                }

                return true;
            };

            // A group survives if *any* row still exposes it. Groups reached from
            // more than one screen (reports.view is the case that matters) are
            // therefore only revoked once every one of those screens is off.
            $exposed = [];
            foreach ($rows as $row) {
                if (! $row->permission) {
                    continue;
                }

                $group = EnabledModules::groupOf($row->permission);
                $exposed[$group] = ($exposed[$group] ?? false) || $reachable($row);
            }

            return array_values(array_keys(array_filter($exposed, static fn (bool $live) => ! $live)));
        });
    }

    /**
     * Is this permission behind a revoked screen?
     *
     * @param  list<string>  $revoked
     */
    public static function isRevoked(string $permission, array $revoked): bool
    {
        return $revoked !== [] && in_array(EnabledModules::groupOf($permission), $revoked, true);
    }

    /**
     * Clone the global menu catalogue (tenant_id NULL) into rows owned by this
     * tenant, so its navigation can be tailored without touching anyone else's.
     *
     * Parent links are remapped to the freshly-created rows. Idempotent: a tenant
     * that already owns rows is left alone. Called at provisioning time and again
     * the first time an operator edits a legacy tenant's access.
     */
    public static function cloneForTenant(int $tenantId): void
    {
        if (MenuItem::where('tenant_id', $tenantId)->exists()) {
            return; // already cloned
        }

        // Group the global rows by parent so we can walk the tree top-down and
        // always know a child's new parent id before inserting the child.
        $byParent = MenuItem::whereNull('tenant_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('parent_id');

        $clone = function (?int $globalParentId, ?int $newParentId) use (&$clone, $byParent, $tenantId): void {
            foreach ($byParent->get($globalParentId, collect()) as $row) {
                /** @var MenuItem $copy */
                $copy = $row->replicate();
                $copy->tenant_id = $tenantId;
                $copy->parent_id = $newParentId;
                $copy->save();

                $clone($row->id, $copy->id);
            }
        };

        $clone(null, null);
    }
}
