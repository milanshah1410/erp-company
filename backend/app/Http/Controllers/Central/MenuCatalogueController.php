<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Support\Modules;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The master menu catalogue, as the SaaS owner maintains it
 * (/platform/menus). Guarded by the `menus.*` central permissions.
 *
 * This is the *global* navigation (menu_items with tenant_id NULL) — the single
 * catalogue every new workspace is cloned from at provisioning time
 * (App\Support\TenantMenuAccess::cloneForTenant). Switching a row off here is a
 * product decision: "this screen is not part of what we ship". It is deliberately
 * a different surface from the two tenant-scoped panels next door:
 *
 *   Tenants → Access       — may *this* workspace use this screen?
 *   Tenants → Menu Builder — where does the screen sit in *this* workspace's tree?
 *   Menu Catalogue (here)  — is the screen part of the product at all?
 *
 * The reach caveat is the whole reason this controller is more than an
 * `update(['is_active' => …])`, and the UI states it plainly: a workspace that
 * owns cloned rows reads its own copy, not this one, so a global toggle by itself
 * only reaches the next signup plus the legacy tenants still served the global
 * catalogue. `apply_to_tenants` is the opt-in that pushes the same switch down
 * into every existing workspace's copy — off by default, because retro-actively
 * removing a screen from a live workspace is a decision, not a side effect.
 *
 * No cascade to children is applied, on purpose: an inactive parent already hides
 * everything under it, in the sidebar (MenuController drops a group with no
 * visible children) and in the API gate alike (TenantMenuAccess::revokedGroups
 * walks ancestors). Flipping the descendants too would silently lose the tenant's
 * own per-child state when the parent is switched back on.
 */
class MenuCatalogueController extends Controller
{
    /**
     * The whole global catalogue, flat and depth-stamped.
     *
     * Flat rather than nested because this screen is a list to search and switch,
     * not a tree to drag — `depth` is enough for the UI to indent by, and a flat
     * payload survives filtering (a matching leaf keeps its row when its parent
     * does not match).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = MenuItem::whereNull('tenant_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'label', 'icon', 'route', 'permission', 'feature', 'module', 'sort_order', 'is_active']);

        $byParent = $rows->groupBy('parent_id');
        $overrides = $this->tenantOverrides();

        $flat = [];

        // Depth-first from the roots, so the flat list reads in the same order
        // the sidebar renders and a child always follows its parent.
        $walk = function (?int $parentId, int $depth, ?string $section) use (&$walk, $byParent, $overrides, &$flat): void {
            foreach ($byParent->get($parentId, collect()) as $row) {
                /** @var MenuItem $row */
                $children = $byParent->get($row->id, collect());
                $key = $this->overrideKey($row);

                $flat[] = [
                    'id' => $row->id,
                    'parent_id' => $row->parent_id,
                    'depth' => $depth,
                    // The top-level group this row lives under, so the list can be
                    // grouped/filtered by section without the client rebuilding
                    // the tree.
                    'section' => $section ?? $row->label,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'feature' => $row->feature,
                    'module' => $row->module,
                    'sort_order' => $row->sort_order,
                    'is_active' => (bool) $row->is_active,
                    'is_group' => $row->route === null,
                    'children_count' => $children->count(),
                    // How the workspaces that own a copy of this row have it set.
                    // Without this the screen would claim a reach it does not
                    // have: "active here, off in 12 workspaces" is the honest
                    // reading of a global row.
                    'tenants_with_row' => $overrides[$key]['total'] ?? 0,
                    'tenants_off' => $overrides[$key]['off'] ?? 0,
                ];

                $walk($row->id, $depth + 1, $section ?? $row->label);
            }
        };

        $walk(null, 0, null);

        return response()->json([
            'data' => $flat,
            // Reference lists so the filters render without extra round-trips.
            'modules' => Modules::options(),
            'sections' => collect($flat)->pluck('section')->unique()->values(),
            'stats' => [
                'total' => count($flat),
                'active' => count(array_filter($flat, static fn (array $r) => $r['is_active'])),
                'groups' => count(array_filter($flat, static fn (array $r) => $r['is_group'])),
                'screens' => count(array_filter($flat, static fn (array $r) => ! $r['is_group'])),
                // The two numbers behind the reach caveat above.
                'tenants_total' => Tenant::count(),
                'tenants_with_own_menu' => count($this->tenantsOwningRows()),
            ],
        ]);
    }

    /**
     * Switch one catalogue row on or off.
     *
     * Accepts the desired state rather than flipping blind, so a stale list in
     * front of the operator cannot turn "activate" into "deactivate".
     */
    public function toggle(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'apply_to_tenants' => ['nullable', 'boolean'],
        ]);

        if ($menuItem->tenant_id !== null) {
            return response()->json([
                'message' => 'That row belongs to a single workspace — edit it from Tenants → Menu Builder.',
            ], 422);
        }

        $active = array_key_exists('is_active', $data) && $data['is_active'] !== null
            ? (bool) $data['is_active']
            : ! $menuItem->is_active;

        $menuItem->update(['is_active' => $active]);

        $applied = ! empty($data['apply_to_tenants'])
            ? $this->applyToTenants([$menuItem], $active)
            : 0;

        // Tenants that own no rows of their own are served this catalogue, so
        // their cached copy is now stale even when nothing was pushed down.
        $this->bumpTenants($this->tenantsWithoutRows());

        activity('central-menus')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'menu_item_id' => $menuItem->id,
                'is_active' => $active,
                'tenants_applied' => $applied,
            ])
            ->event($active ? 'activated' : 'deactivated')
            ->log('Operator '.($active ? 'activated' : 'deactivated')." menu item \"{$menuItem->label}\"");

        return response()->json([
            'data' => ['id' => $menuItem->id, 'is_active' => $active],
            'tenants_applied' => $applied,
        ]);
    }

    /**
     * Switch a set of rows at once — how an operator retires a whole section.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'is_active' => ['required', 'boolean'],
            'apply_to_tenants' => ['nullable', 'boolean'],
        ]);

        $active = (bool) $data['is_active'];

        // Scoped to global rows: a tenant id smuggled into the list is simply not
        // found, rather than quietly edited.
        $rows = MenuItem::whereNull('tenant_id')->whereIn('id', $data['ids'])->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'None of those rows are part of the shared catalogue.'], 422);
        }

        MenuItem::whereNull('tenant_id')
            ->whereIn('id', $rows->pluck('id'))
            ->update(['is_active' => $active]);

        $applied = ! empty($data['apply_to_tenants'])
            ? $this->applyToTenants($rows->all(), $active)
            : 0;

        $this->bumpTenants($this->tenantsWithoutRows());

        activity('central-menus')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'menu_item_ids' => $rows->pluck('id')->all(),
                'is_active' => $active,
                'tenants_applied' => $applied,
            ])
            ->event($active ? 'activated' : 'deactivated')
            ->log('Operator '.($active ? 'activated' : 'deactivated').' '.$rows->count().' menu item(s)');

        return response()->json([
            'updated' => $rows->count(),
            'tenants_applied' => $applied,
        ]);
    }

    /**
     * Push the same switch into every workspace's own copy of these rows.
     *
     * A copy is matched by `route` for a screen and by label for a group, because
     * that is all a clone preserves — ids are re-minted per tenant, and a tenant
     * may have re-parented or renamed the row since. A renamed group therefore
     * falls out of the match, which is the safe direction: we skip it rather than
     * switch off a row the operator can no longer recognise as the same one.
     *
     * @param  list<MenuItem>  $rows
     * @return int  how many tenant rows were changed
     */
    private function applyToTenants(array $rows, bool $active): int
    {
        $routes = [];
        $groupLabels = [];

        foreach ($rows as $row) {
            if ($row->route !== null) {
                $routes[] = $row->route;
            } else {
                $groupLabels[] = $row->label;
            }
        }

        $match = fn ($query) => $query
            ->whereNotNull('tenant_id')
            ->where(function ($q) use ($routes, $groupLabels) {
                if ($routes !== []) {
                    $q->orWhereIn('route', $routes);
                }
                if ($groupLabels !== []) {
                    $q->orWhere(fn ($g) => $g->whereNull('route')->whereIn('label', $groupLabels));
                }
            });

        if ($routes === [] && $groupLabels === []) {
            return 0;
        }

        // Which workspaces are about to change, read before the write — their
        // menu caches have to be bumped afterwards, and the mass update below
        // fires no model events to do it for us.
        $tenantIds = $match(MenuItem::query())
            ->where('is_active', '!=', $active)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $changed = $match(MenuItem::query())->update(['is_active' => $active]);

        $this->bumpTenants($tenantIds);

        return (int) $changed;
    }

    /**
     * How each catalogue row is set across the workspaces that own a copy.
     *
     * One grouped query rather than a count per row: the catalogue runs to a few
     * hundred rows and this screen is read on every page load.
     *
     * @return array<string, array{total: int, off: int}>
     */
    private function tenantOverrides(): array
    {
        $rows = MenuItem::query()
            ->whereNotNull('tenant_id')
            ->select('route', 'label')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = 0 then 1 else 0 end) as off')
            // Grouped by the same key applyToTenants() matches on, so the number
            // shown is the number a push would touch.
            ->groupBy('route', 'label')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = $row->route !== null ? 'route:'.$row->route : 'group:'.$row->label;
            $out[$key]['total'] = ($out[$key]['total'] ?? 0) + (int) $row->total;
            $out[$key]['off'] = ($out[$key]['off'] ?? 0) + (int) $row->off;
        }

        return $out;
    }

    /** The key a global row is matched to its tenant copies by. */
    private function overrideKey(MenuItem $row): string
    {
        return $row->route !== null ? 'route:'.$row->route : 'group:'.$row->label;
    }

    /**
     * Tenants that own a cloned menu — the ones a global toggle does NOT reach.
     *
     * @return list<int>
     */
    private function tenantsOwningRows(): array
    {
        return MenuItem::whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Tenants still served the global catalogue — the ones a global toggle
     * reaches immediately, and whose cached copy therefore has to be dropped.
     *
     * Two queries across the central/tenant connection line rather than a join:
     * `tenants` and `menu_items` do not live on the same connection.
     *
     * @return list<int>
     */
    private function tenantsWithoutRows(): array
    {
        $owning = $this->tenantsOwningRows();

        return Tenant::query()
            ->when($owning !== [], fn ($q) => $q->whereNotIn('id', $owning))
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Drop the cached navigation for these workspaces.
     *
     * The cache version counter is per tenant (TenantCache keys are namespaced by
     * tenant id), so bumping it means entering each tenant's context in turn.
     *
     * @param  list<int>  $tenantIds
     */
    private function bumpTenants(array $tenantIds): void
    {
        $context = app(TenantContext::class);

        foreach (array_unique($tenantIds) as $tenantId) {
            $context->runWith((int) $tenantId, static fn () => TenantCache::bump('menu_items'));
        }
    }
}
