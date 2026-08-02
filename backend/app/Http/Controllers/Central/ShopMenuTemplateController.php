<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\ShopMenuItem;
use App\Models\Tenant;
use App\Support\Modules;
use App\Support\ShopMenus;
use App\Support\ShopProfiles;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Database\Seeders\ShopMenuSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Shop-wise menu templates, as the SaaS owner maintains them
 * (/platform/shop-menus). Guarded by the same `menus.*` central permissions as
 * the catalogue next door.
 *
 * Three menu surfaces now exist in the platform panel, and they answer three
 * different questions:
 *
 *   Menu Catalogue   — is this screen part of the product at all?
 *   Shop Menus (here)— what does a *Grocery* workspace open on?
 *   Tenants → Builder— where does the screen sit in *this one* workspace?
 *
 * This one sits in the middle, and it is the only one whose edits are free: a
 * template is not anybody's live navigation. Changing it steers every *future*
 * signup of that trade and nothing else, which is why the tree here can be
 * dragged into any shape without a confirmation. Reaching existing workspaces is
 * a separate, explicit act — `apply` below — for the same reason
 * MenuCatalogueController keeps `apply_to_tenants` opt-in: retro-fitting a menu
 * onto a live workspace overwrites whatever its operator arranged there.
 *
 * The two invariants the tenant-side nav depends on are enforced here as well,
 * because a template becomes a tenant menu verbatim:
 *   1. a row with children carries no `route` (MenuController treats a routed row
 *      as a leaf and would drop the children);
 *   2. a `route` is one the catalogue defines and a `permission` one that exists.
 */
class ShopMenuTemplateController extends Controller
{
    /** Same nesting cap as the per-tenant builder, for the same reason. */
    private const MAX_DEPTH = 5;

    /**
     * Every trade, whether or not it has a template — the picker on the left of
     * the screen.
     *
     * Trades without a template are listed too, and say so: "no template — signs
     * up on the full catalogue" is the state an operator most wants to see and
     * fix, so hiding those rows would hide the work.
     */
    public function index(): JsonResponse
    {
        $summary = ShopMenus::summary();
        $tenantCounts = Tenant::query()
            ->whereNotNull('business_type')
            ->selectRaw('business_type, count(*) as total')
            ->groupBy('business_type')
            ->pluck('total', 'business_type');

        $shops = collect(ShopProfiles::options())
            // 'custom' is "Something else" — the absence of a trade. A template
            // for it would be a second global catalogue under another name.
            ->reject(fn (array $shop) => $shop['key'] === 'custom')
            ->map(fn (array $shop) => [
                'key' => $shop['key'],
                'label' => $shop['label'],
                'icon' => $shop['icon'],
                'description' => $shop['description'],
                'industry' => $shop['industry'],
                'rows' => $summary[$shop['key']]['rows'] ?? 0,
                'screens' => $summary[$shop['key']]['screens'] ?? 0,
                'hidden' => $summary[$shop['key']]['hidden'] ?? 0,
                'has_template' => isset($summary[$shop['key']]),
                // Whether "Reset to default" has a shipped default to go back to.
                'ships_default' => ShopMenuSeeder::ships($shop['key']),
                // Workspaces already on this trade — what an `apply` would reach.
                'tenants' => (int) ($tenantCounts[$shop['key']] ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => $shops,
            'stats' => [
                'trades' => $shops->count(),
                'with_template' => $shops->where('has_template', true)->count(),
                'catalogue_screens' => MenuItem::whereNull('tenant_id')->whereNotNull('route')->count(),
            ],
        ]);
    }

    /**
     * One trade's tree, plus everything the builder needs to offer.
     */
    public function show(string $shopProfile): JsonResponse
    {
        $shop = $this->shop($shopProfile);
        $rows = ShopMenus::rows($shopProfile);
        $usedRoutes = $rows->pluck('route')->filter()->unique()->all();

        return response()->json([
            'shop' => $shop,
            'tree' => $this->tree($rows),
            'has_template' => $rows->isNotEmpty(),
            'ships_default' => ShopMenuSeeder::ships($shopProfile),
            // Every screen the product has — what "Add screen" offers.
            'screens' => $this->screenCatalogue($usedRoutes),
            'permissions' => Permission::where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'modules' => collect(Modules::CATALOGUE)
                ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label']])
                ->values(),
            // The modules this trade's preset switches on, so the panel can warn
            // when a template names a screen the trade will not have enabled.
            'trade_modules' => ShopProfiles::modulesFor($shopProfile),
            'max_depth' => self::MAX_DEPTH,
            'total' => $rows->count(),
            'tenants' => Tenant::where('business_type', $shopProfile)->count(),
        ]);
    }

    /**
     * Save the tree as sent.
     *
     * The payload is the desired end state, not a delta: rows present are
     * created/updated in the order given, rows missing from it are deleted.
     * Re-sending the same tree is a no-op.
     */
    public function update(Request $request, string $shopProfile): JsonResponse
    {
        $this->shop($shopProfile);
        $request->validate(['tree' => ['present', 'array']]);

        $existing = ShopMenus::rows($shopProfile)->keyBy('id');

        // Routes the catalogue knows, plus whatever this template already points
        // at — a template that was legal yesterday must stay saveable even if the
        // catalogue has since dropped a screen.
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

        DB::transaction(function () use ($tree, $shopProfile, $existing, &$kept): void {
            // Top-down: a child's parent must exist before the child can point at
            // it, which matters when the parent is a group just invented here.
            $walk = function (array $nodes, ?int $parentId) use (&$walk, $shopProfile, $existing, &$kept): void {
                foreach ($nodes as $index => $node) {
                    $attributes = [
                        'shop_profile' => $shopProfile,
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
                    // catalogue owns, so filling an existing row leaves it as it
                    // was and a new row inherits it from the screen picker.
                    if ($node['id'] !== null) {
                        /** @var ShopMenuItem $row */
                        $row = $existing->get($node['id']);
                        $row->fill($attributes)->save();
                    } else {
                        $row = ShopMenuItem::create($attributes + ['feature' => $node['feature']]);
                    }

                    $kept[] = $row->id;
                    $walk($node['children'], $row->id);
                }
            };

            $walk($tree, null);

            // Anything dragged out of the tree. Children are deleted before their
            // parent would cascade them, so the ids in $kept stay meaningful.
            $stale = $existing->keys()->diff($kept);
            if ($stale->isNotEmpty()) {
                ShopMenuItem::where('shop_profile', $shopProfile)->whereIn('id', $stale)->delete();
            }
        });

        return $this->show($shopProfile);
    }

    /**
     * Start a template for a trade that has none, or re-apply its shipped
     * default.
     *
     * `source=default` re-seeds from ShopMenuSeeder — only possible for a trade
     * that ships one. `source=catalogue` copies the whole catalogue, which is the
     * honest starting point for a trade nobody has curated: prune by dragging
     * rather than build from an empty page.
     */
    public function reset(Request $request, string $shopProfile): JsonResponse
    {
        $this->shop($shopProfile);

        $data = $request->validate([
            'source' => ['nullable', 'in:default,catalogue'],
        ]);

        $source = $data['source'] ?? 'default';

        if ($source === 'default') {
            $written = (new ShopMenuSeeder)->reseed($shopProfile);

            if ($written < 0) {
                return response()->json([
                    'message' => 'This trade ships no default menu — start from the full catalogue instead.',
                ], 422);
            }
        } else {
            $written = ShopMenus::seedFromCatalogue($shopProfile);
        }

        activity('central-menus')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'shop_profile' => $shopProfile, 'source' => $source, 'rows' => $written])
            ->event('reset')
            ->log("Operator reset the {$shopProfile} shop menu from the {$source}");

        return $this->show($shopProfile);
    }

    /**
     * Drop a trade's template — that trade goes back to signing up on the full
     * catalogue.
     */
    public function destroy(Request $request, string $shopProfile): JsonResponse
    {
        $this->shop($shopProfile);

        // Counted before the deletes: they run in two passes (leaves, then roots)
        // and the second pass's return value would report only the roots.
        $deleted = ShopMenuItem::where('shop_profile', $shopProfile)->count();

        // Children first. The self-FK cascades either way, but deleting leaves
        // explicitly keeps the order deterministic across drivers.
        ShopMenuItem::where('shop_profile', $shopProfile)->whereNotNull('parent_id')->delete();
        ShopMenuItem::where('shop_profile', $shopProfile)->delete();

        activity('central-menus')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'shop_profile' => $shopProfile])
            ->event('deleted')
            ->log("Operator removed the {$shopProfile} shop menu template");

        return response()->json(['deleted' => $deleted] + $this->show($shopProfile)->getData(true));
    }

    /**
     * Push this template onto the workspaces already on this trade.
     *
     * Deliberately not part of saving. A template steers the *next* signup for
     * free; reaching a live workspace throws away whatever its operator arranged
     * in Tenants → Menu Builder, along with their users' favourites (the FK
     * cascades). So it is its own button, it names the count before it runs, and
     * it is scoped to the trade rather than "all tenants".
     */
    public function apply(Request $request, string $shopProfile): JsonResponse
    {
        $this->shop($shopProfile);

        if (! ShopMenus::has($shopProfile)) {
            return response()->json(['message' => 'This trade has no template to apply.'], 422);
        }

        $data = $request->validate([
            'tenant_ids' => ['nullable', 'array'],
            'tenant_ids.*' => ['integer'],
        ]);

        $tenants = Tenant::where('business_type', $shopProfile)
            ->when(! empty($data['tenant_ids']), fn ($q) => $q->whereIn('id', $data['tenant_ids']))
            ->pluck('id');

        $context = app(TenantContext::class);
        $applied = 0;

        foreach ($tenants as $tenantId) {
            $context->runWith((int) $tenantId, function () use ($tenantId, $shopProfile, &$applied): void {
                DB::transaction(function () use ($tenantId, $shopProfile): void {
                    // Leaves before parents, or a parent's nullOnDelete leaves its
                    // children as stray roots mid-delete.
                    MenuItem::where('tenant_id', $tenantId)->whereNotNull('parent_id')->delete();
                    MenuItem::where('tenant_id', $tenantId)->delete();

                    ShopMenus::cloneForTenant($tenantId, $shopProfile);
                });

                // The deletes above run through Eloquent, but the clone's mass
                // writes and the revoked-group set share this version — bump once
                // so the sidebar, the role matrix and CheckPermission agree.
                TenantCache::bump('menu_items');
                $applied++;
            });
        }

        activity('central-menus')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'shop_profile' => $shopProfile, 'tenants_applied' => $applied])
            ->event('applied')
            ->log("Operator applied the {$shopProfile} shop menu to {$applied} workspace(s)");

        return response()->json([
            'applied' => $applied,
            'message' => $applied === 0
                ? 'No workspace is on this trade yet.'
                : "Applied to {$applied} workspace".($applied === 1 ? '' : 's').'.',
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * The trade, or a 404 — a template can only exist for a trade that does.
     *
     * @return array<string, mixed>
     */
    private function shop(string $shopProfile): array
    {
        $shop = collect(ShopProfiles::options())->firstWhere('key', $shopProfile);

        abort_if($shop === null || $shopProfile === 'custom', 404, 'No such shop type.');

        return [
            'key' => $shop['key'],
            'label' => $shop['label'],
            'icon' => $shop['icon'],
            'description' => $shop['description'],
            'industry' => $shop['industry'],
        ];
    }

    /**
     * Flat rows → the nested shape the builder drags around.
     *
     * @param  Collection<int, ShopMenuItem>  $rows
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $rows): array
    {
        $byParent = $rows->groupBy('parent_id');

        $build = function (?int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->map(fn (ShopMenuItem $row) => [
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
     * Every real screen, from the global catalogue.
     *
     * Carries the row's own permission/feature/module so a screen dropped into a
     * bespoke group keeps the gate it shipped with.
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
                $section = $row->parent_id ? $labels->get($row->parent_id) : null;
                while ($section && $section->parent_id) {
                    $section = $labels->get($section->parent_id);
                }

                return [
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'feature' => $row->feature,
                    'module' => $row->module,
                    'section' => $section?->label ?? 'Other',
                    'in_menu' => in_array($row->route, $usedRoutes, true),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate and clean one level of the incoming tree, recursing into children.
     *
     * Hand-rolled rather than `tree.*.children.*.…` wildcards: the tree is
     * arbitrarily deep, and the failures worth naming ("this row has children and
     * a route") are structural, not per-field.
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
        // children, so an unconditional check would reject a legal row sitting at
        // the deepest allowed level.
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
            // keys by). A positive id must be one of this template's own —
            // otherwise the payload is reaching into another trade's rows.
            $id = $node['id'] ?? null;
            $id = is_numeric($id) ? (int) $id : null;
            if ($id !== null && $id > 0 && ! in_array($id, $ownedIds, true)) {
                throw ValidationException::withMessages([
                    'tree' => "Menu row #{$id} does not belong to this shop type — reload the screen and try again.",
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
                'feature' => $this->nullableString($node['feature'] ?? null),
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
