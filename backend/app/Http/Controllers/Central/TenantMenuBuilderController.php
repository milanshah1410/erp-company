<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Support\Modules;
use App\Support\TenantCache;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Per-tenant menu *builder* for the SaaS-owner panel
 * (/platform/tenants → Menu Builder).
 *
 * The Access panel next door (App\Http\Controllers\Central\TenantAccessController)
 * answers "may this workspace use this screen?"; this one answers "where does the
 * screen sit, what is it called, and what hangs under it?" — the tenant's own
 * navigation tree, reordered and re-nested by drag & drop:
 *
 *   order   — `sort_order` within a parent;
 *   nesting — `parent_id`, to any depth the sidebar renders (NavFlyoutNode
 *             cascades recursively, so the cap here is sanity, not layout);
 *   naming  — `label` / `icon`, so a tenant can call Customers "Patients";
 *   shape   — new groups the operator invents, and screens moved into them.
 *
 * Every row it writes is tenant-owned (TenantMenuAccess::cloneForTenant is called
 * first, so a legacy tenant gets its own copy of the catalogue before its first
 * edit). The global catalogue (tenant_id NULL) is never touched — one tenant's
 * rearranged menu can't reach another's.
 *
 * Two invariants the tenant-side MenuController depends on, both enforced here
 * rather than left to the UI:
 *
 *   1. a row with children must have no `route`. MenuController treats a row with
 *      a route as a leaf and drops its children on the floor, so a "link with
 *      children" would silently lose the children.
 *   2. a `route` must be one the catalogue already defines, and a `permission`
 *      one that exists. Free-typed values would produce a nav entry the SPA
 *      router refuses (denied_routes) or a permission gate nobody can ever hold.
 *
 * Gated by the operator's own `can_manage_tenant_access` flag — the same lever as
 * the Access panel, because re-parenting a screen is the same power as revoking
 * it (App\Http\Middleware\EnsureTenantAccessManager).
 */
class TenantMenuBuilderController extends Controller
{
    /**
     * How deep the tree may nest. The sidebar cascades to any depth; this only
     * stops a drag mishap from burying a screen 30 panels down.
     */
    private const MAX_DEPTH = 5;

    /**
     * The tenant's navigation tree, plus everything the builder needs to offer:
     * the catalogue of real screens, the permission vocabulary and the module
     * keys.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return app(TenantContext::class)->runWith($tenant->id, function () use ($tenant): JsonResponse {
            // A tenant provisioned before per-tenant menus is served the global
            // catalogue and owns no rows. Clone it now, so what the operator drags
            // is this workspace's menu and not everybody's.
            TenantMenuAccess::cloneForTenant($tenant->id, $tenant->business_type);

            $rows = $this->rows($tenant->id);
            $usedRoutes = $rows->pluck('route')->filter()->unique()->all();

            return response()->json([
                'tenant' => [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                ],
                'tree' => $this->tree($rows),
                // The screens that actually exist, taken from the global
                // catalogue — the operator picks from these when adding a row,
                // so a hand-typed dead link is not possible.
                'screens' => $this->screenCatalogue($usedRoutes),
                'permissions' => Permission::where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
                'modules' => collect(Modules::CATALOGUE)
                    ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label']])
                    ->values(),
                'max_depth' => self::MAX_DEPTH,
                // How many rows this workspace has, so the panel can say whether
                // it is still on the stock catalogue or has been tailored.
                'total' => $rows->count(),
            ]);
        });
    }

    /**
     * Save the tree as sent.
     *
     * The payload is the desired end state, not a delta: rows present are
     * created/updated in the order given, and any tenant row missing from it is
     * deleted. Re-sending the same tree is therefore a no-op.
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['tree' => ['present', 'array']]);

        return app(TenantContext::class)->runWith($tenant->id, function () use ($request, $tenant): JsonResponse {
            TenantMenuAccess::cloneForTenant($tenant->id, $tenant->business_type);

            $existing = $this->rows($tenant->id)->keyBy('id');

            // Routes the catalogue knows about, plus whatever this tenant already
            // points at — a row that was legal yesterday must stay saveable even
            // if the global catalogue has since dropped it.
            $allowedRoutes = MenuItem::whereNull('tenant_id')
                ->whereNotNull('route')
                ->pluck('route')
                ->merge($existing->pluck('route')->filter())
                ->unique()
                ->all();

            $tree = $this->normalise(
                $request->input('tree'),
                0,
                $allowedRoutes,
                Permission::where('guard_name', 'web')->pluck('name')->all(),
                Modules::keys(),
                $existing->keys()->all(),
            );

            $kept = [];

            DB::transaction(function () use ($tree, $tenant, $existing, &$kept): void {
                // Top-down: a child's parent row must exist (and have an id)
                // before the child can point at it, which matters when the parent
                // is a group the operator just invented.
                $walk = function (array $nodes, ?int $parentId) use (&$walk, $tenant, $existing, &$kept): void {
                    foreach ($nodes as $index => $node) {
                        $attributes = [
                            'tenant_id' => $tenant->id,
                            'parent_id' => $parentId,
                            'label' => $node['label'],
                            'icon' => $node['icon'],
                            'route' => $node['route'],
                            'permission' => $node['permission'],
                            'module' => $node['module'],
                            'sort_order' => $index,
                            'is_active' => $node['is_active'],
                        ];

                        // `feature` is deliberately absent: it is a plan gate the
                        // seeder owns, not something this panel edits, so filling
                        // an existing row leaves it as it was.
                        if ($node['id'] !== null) {
                            /** @var MenuItem $row */
                            $row = $existing->get($node['id']);
                            $row->fill($attributes);
                            // Guarded on isDirty: every MenuItem save bumps the
                            // tenant's menu cache version (InvalidatesTenantCache),
                            // and a 240-row tree where the operator moved one item
                            // should cost one bump, not 240.
                            if ($row->isDirty()) {
                                $row->save();
                            }
                        } else {
                            $row = MenuItem::create($attributes);
                        }

                        $kept[] = $row->id;
                        $walk($node['children'], $row->id);
                    }
                };

                $walk($tree, null);

                // Anything the operator dragged out of the tree. Deleting a parent
                // nulls a surviving child's parent_id (the FK is nullOnDelete), but
                // a surviving row is by definition still in the tree with a kept
                // parent, so nothing is orphaned. Favourites pointing at a deleted
                // row cascade away with it.
                $stale = $existing->keys()->diff($kept);
                if ($stale->isNotEmpty()) {
                    MenuItem::where('tenant_id', $tenant->id)->whereIn('id', $stale)->delete();
                }
            });

            // The mass writes above run through Eloquent, but the deletes and the
            // revoked-group set share the 'menu_items' version — bump it once here
            // so the sidebar, the role matrix and CheckPermission all see the new
            // shape immediately.
            TenantCache::bump('menu_items');

            return $this->show($tenant);
        });
    }

    /**
     * Throw the tenant's menu away and re-clone its starting menu.
     *
     * The escape hatch for a tree that has been dragged into a mess: the tenant's
     * own rows go, and with them their favourites, and the stock navigation comes
     * back.
     *
     * "Stock" means this workspace's *trade* template where its trade has one
     * (/platform/shop-menus), and the full catalogue otherwise — the same choice
     * the workspace was provisioned on, so a reset restores what it started with
     * rather than something it has never seen.
     */
    public function reset(Tenant $tenant): JsonResponse
    {
        return app(TenantContext::class)->runWith($tenant->id, function () use ($tenant): JsonResponse {
            DB::transaction(function () use ($tenant): void {
                // Children first: a parent's delete would null its children's
                // parent_id and leave them as stray roots mid-delete.
                MenuItem::where('tenant_id', $tenant->id)->whereNotNull('parent_id')->delete();
                MenuItem::where('tenant_id', $tenant->id)->delete();

                TenantMenuAccess::cloneForTenant($tenant->id, $tenant->business_type);
            });

            TenantCache::bump('menu_items');

            return $this->show($tenant);
        });
    }

    /**
     * This tenant's rows, ordered as the nav orders them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MenuItem>
     */
    private function rows(int $tenantId)
    {
        return MenuItem::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'label', 'icon', 'route', 'permission', 'feature', 'module', 'sort_order', 'is_active']);
    }

    /**
     * Flat rows → the nested shape the builder drags around.
     *
     * @param  Collection<int, MenuItem>  $rows
     * @return list<array<string, mixed>>
     */
    private function tree($rows): array
    {
        $byParent = $rows->groupBy('parent_id');

        $build = function (?int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->map(fn (MenuItem $row) => [
                    'id' => $row->id,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'feature' => $row->feature,
                    'module' => $row->module,
                    'is_active' => (bool) $row->is_active,
                    'children' => $build($row->id),
                ])
                ->values()
                ->all();
        };

        return $build(null);
    }

    /**
     * Every real screen, from the global catalogue: what "Add screen" offers.
     *
     * Carries the row's own permission/module so a screen dropped into a new
     * group keeps its gate — an operator moving Stock Transfers under a custom
     * group should not accidentally strip its permission.
     *
     * @param  list<string>  $usedRoutes
     * @return list<array<string, mixed>>
     */
    private function screenCatalogue(array $usedRoutes): array
    {
        $rows = MenuItem::whereNull('tenant_id')->orderBy('sort_order')->get();
        $labels = $rows->keyBy('id');

        return $rows
            ->filter(fn (MenuItem $row) => $row->route !== null)
            ->map(function (MenuItem $row) use ($labels, $usedRoutes) {
                // The group it lives under in the stock catalogue, so a list of
                // 200-odd screens can be grouped rather than dumped flat.
                $section = $row->parent_id ? $labels->get($row->parent_id) : null;
                while ($section && $section->parent_id) {
                    $section = $labels->get($section->parent_id);
                }

                return [
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'module' => $row->module,
                    'section' => $section?->label ?? 'Other',
                    // Already somewhere in this tenant's tree. Advisory: pointing
                    // two rows at one screen is legal, just rarely intended.
                    'in_menu' => in_array($row->route, $usedRoutes, true),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate and clean one level of the incoming tree, recursing into children.
     *
     * Hand-rolled rather than expressed as `tree.*.children.*.…` wildcards: the
     * tree is arbitrarily deep, and the failures worth naming ("this row has
     * children and a route") are structural, not per-field.
     *
     * @param  list<string>  $allowedRoutes
     * @param  list<string>  $permissions
     * @param  list<string>  $moduleKeys
     * @param  list<int>  $ownedIds
     * @return list<array<string, mixed>>
     */
    private function normalise(mixed $nodes, int $depth, array $allowedRoutes, array $permissions, array $moduleKeys, array $ownedIds): array
    {
        if (! is_array($nodes)) {
            throw ValidationException::withMessages(['tree' => 'Malformed menu tree.']);
        }

        // Guarded on a non-empty level: every leaf recurses into its (empty)
        // children, so checking unconditionally would reject a perfectly legal
        // row sitting at the deepest allowed level.
        if ($nodes !== [] && $depth >= self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'tree' => 'Menu nesting is limited to '.self::MAX_DEPTH.' levels.',
            ]);
        }

        $clean = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                throw ValidationException::withMessages(['tree' => 'Malformed menu row.']);
            }

            $label = trim((string) ($node['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 120) {
                throw ValidationException::withMessages(['tree' => 'Every menu row needs a label of 1–120 characters.']);
            }

            // A new row arrives with no id (or the negative placeholder the panel
            // uses for keying). A positive id must be one of this tenant's own —
            // otherwise the payload is reaching for a global or another tenant's
            // row, and we refuse rather than quietly cloning it.
            $id = $node['id'] ?? null;
            $id = is_numeric($id) ? (int) $id : null;
            if ($id !== null && $id > 0 && ! in_array($id, $ownedIds, true)) {
                throw ValidationException::withMessages([
                    'tree' => "Menu row #{$id} does not belong to this workspace — reload the panel and try again.",
                ]);
            }
            if ($id !== null && $id <= 0) {
                $id = null;
            }

            $children = $this->normalise(
                $node['children'] ?? [],
                $depth + 1,
                $allowedRoutes,
                $permissions,
                $moduleKeys,
                $ownedIds,
            );

            $route = $this->nullableString($node['route'] ?? null);
            if ($route !== null && $children !== []) {
                throw ValidationException::withMessages([
                    'tree' => "\"{$label}\" is a link and cannot hold sub-items — clear its screen to turn it into a group.",
                ]);
            }
            if ($route !== null && ! in_array($route, $allowedRoutes, true)) {
                throw ValidationException::withMessages([
                    'tree' => "\"{$label}\" points at {$route}, which is not a screen this product has.",
                ]);
            }

            $permission = $this->nullableString($node['permission'] ?? null);
            if ($permission !== null && ! in_array($permission, $permissions, true)) {
                throw ValidationException::withMessages([
                    'tree' => "\"{$label}\" is gated by the permission {$permission}, which does not exist.",
                ]);
            }

            $module = $this->nullableString($node['module'] ?? null);
            if ($module !== null && ! in_array($module, $moduleKeys, true)) {
                throw ValidationException::withMessages([
                    'tree' => "\"{$label}\" belongs to the module {$module}, which does not exist.",
                ]);
            }

            $clean[] = [
                'id' => $id,
                'label' => $label,
                'icon' => $this->nullableString($node['icon'] ?? null, 120),
                'route' => $route,
                'permission' => $permission,
                'module' => $module,
                'is_active' => (bool) ($node['is_active'] ?? true),
                'children' => $children,
            ];
        }

        return $clean;
    }

    /** An empty string from a cleared form field means "none", not "". */
    private function nullableString(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
