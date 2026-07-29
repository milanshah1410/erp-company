<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuFavorite;
use App\Models\MenuItem;
use App\Models\MenuRecent;
use App\Models\Subscription;
use App\Models\User;
use App\Support\EnabledModules;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Serves the database-driven navigation (project-stack.md §3): the shared menu
 * catalogue filtered for the current user by role (permission) and subscription
 * (plan feature), plus their personal Favorite and Recent menus.
 *
 * No route in the app is hard-coded in the sidebar — the frontend renders
 * whatever this endpoint returns.
 */
class MenuController extends Controller
{
    private const MAX_RECENTS = 8;

    /**
     * The full menu payload for the signed-in user:
     *   { menu: [...tree], favorites: [...leaves], recents: [...] }
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = $request->user();
        $planFeatures = $this->planFeatures($tenantId);
        $enabledModules = $this->enabledModules($tenantId);

        // Each tenant owns a cloned copy of the navigation catalogue (stamped
        // with its tenant_id at provisioning time), so it can be customised per
        // tenant. Tenants provisioned before per-tenant menus have no rows of
        // their own, so we fall back to the shared global catalogue (tenant_id
        // NULL) for them.
        //
        // The catalogue is tenant-stable and read on every page load, so it is
        // cached (§17 Performance — Caching); a MenuItem write bumps the version
        // (InvalidatesTenantCache) and the 10-minute TTL is a staleness backstop.
        // Per-user favorites/recents below stay live — they change too often.
        //
        // Cache plain attribute arrays, never the Eloquent Collection itself:
        // serializing hydrated models and unserializing them later throws
        // "incomplete object" errors under the database/file cache drivers. We
        // rehydrate into MenuItem instances after the read so the tree logic
        // below keeps working with real models.
        $rows = TenantCache::rememberVersioned('menu_items', 'catalogue', 600, function () use ($tenantId) {
            $base = fn (?int $tid) => MenuItem::query()
                ->where('is_active', true)
                ->when($tid === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->orderBy('sort_order')
                ->get();

            $owned = $base($tenantId);

            return ($owned->isNotEmpty() ? $owned : $base(null))->toArray();
        });

        $items = MenuItem::hydrate($rows);
        $byParent = $items->groupBy('parent_id');
        $favoriteIds = MenuFavorite::where('user_id', $user->id)->pluck('menu_item_id')->all();

        $roots = $byParent->get(null, collect());
        $tree = $roots
            ->map(fn (MenuItem $item) => $this->transform($item, $user, $byParent, $planFeatures, $enabledModules, $favoriteIds))
            ->filter()
            ->values();

        return response()->json([
            'menu' => $tree,
            'favorites' => $this->favorites($user->id),
            'recents' => $this->recents($user->id),
            // Screens this workspace has in its catalogue but the caller may not
            // open — exactly the rows the tree above filtered out (module switched
            // off, screen revoked by the operator, permission not held). The SPA
            // router refuses these, so hiding a menu row is no longer something a
            // typed URL, a bookmark or the back button can walk around.
            'denied_routes' => $this->deniedRoutes($tenantId, $tree->all()),
        ]);
    }

    /**
     * Catalogue routes minus the ones the caller was served.
     *
     * The dashboard is never included: it is the fallback the SPA redirects to,
     * and denying it would leave the app with nowhere to land.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return list<string>
     */
    private function deniedRoutes(?int $tenantId, array $tree): array
    {
        $granted = [];
        $collect = function (iterable $nodes) use (&$collect, &$granted): void {
            foreach ($nodes as $node) {
                if (! empty($node['to'])) {
                    $granted[] = $node['to'];
                }
                $collect($node['children'] ?? []);
            }
        };
        $collect($tree);

        return array_values(array_diff($this->catalogueRoutes($tenantId), $granted, ['/']));
    }

    /**
     * Every route this tenant's menu catalogue defines, including rows switched
     * off — the visible-rows query above filters `is_active`, and a revoked screen
     * is precisely one we need to name here. Same global fallback as the tree, for
     * tenants that own no rows.
     *
     * @return list<string>
     */
    private function catalogueRoutes(?int $tenantId): array
    {
        return TenantCache::rememberVersioned('menu_items', 'catalogue_routes', 600, function () use ($tenantId) {
            $base = fn (?int $tid) => MenuItem::query()
                ->whereNotNull('route')
                ->when($tid === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->pluck('route');

            $owned = $base($tenantId);

            return ($owned->isNotEmpty() ? $owned : $base(null))->unique()->values()->all();
        });
    }

    /**
     * Toggle a menu item in the current user's favorites.
     */
    public function toggleFavorite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
        ]);

        $existing = MenuFavorite::where('user_id', $request->user()->id)
            ->where('menu_item_id', $data['menu_item_id'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MenuFavorite::create([
                'user_id' => $request->user()->id,
                'menu_item_id' => $data['menu_item_id'],
                'sort_order' => MenuFavorite::where('user_id', $request->user()->id)->max('sort_order') + 1,
            ]);
        }

        return response()->json(['favorites' => $this->favorites($request->user()->id)]);
    }

    /**
     * Record that the user visited a screen; keeps only the most recent few.
     */
    public function trackRecent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
        ]);

        MenuRecent::updateOrCreate(
            ['user_id' => $request->user()->id, 'route' => $data['route']],
            ['label' => $data['label'], 'icon' => $data['icon'] ?? null, 'visited_at' => now()],
        );

        // Prune everything past the most recent MAX_RECENTS.
        $keep = MenuRecent::where('user_id', $request->user()->id)
            ->orderByDesc('visited_at')
            ->limit(self::MAX_RECENTS)
            ->pluck('id');
        MenuRecent::where('user_id', $request->user()->id)
            ->whereNotIn('id', $keep)
            ->delete();

        return response()->json(['recents' => $this->recents($request->user()->id)]);
    }

    /**
     * Recursively map a catalogue row into the payload shape, dropping anything
     * the user may not see. A group with no visible children is itself dropped.
     */
    private function transform(MenuItem $item, User $user, Collection $byParent, array $planFeatures, ?array $enabledModules, array $favoriteIds): ?array
    {
        if (! $this->visible($item, $user, $planFeatures, $enabledModules)) {
            return null;
        }

        $children = $byParent->get($item->id, collect())
            ->map(fn (MenuItem $child) => $this->transform($child, $user, $byParent, $planFeatures, $enabledModules, $favoriteIds))
            ->filter()
            ->values();

        // A group (no route) is only useful if it still has visible children.
        $isGroup = $item->route === null;
        if ($isGroup && $children->isEmpty()) {
            return null;
        }

        return [
            'id' => $item->id,
            'label' => $item->label,
            'icon' => $item->icon,
            'to' => $item->route,
            'permission' => $item->permission,
            'is_favorite' => in_array($item->id, $favoriteIds, true),
            'children' => $isGroup ? $children : [],
        ];
    }

    /**
     * A leaf/group is visible when the user holds its permission (if any), the
     * tenant's plan includes its feature (if any), and the module it belongs to
     * is enabled for the tenant (if module gating is in effect).
     */
    private function visible(MenuItem $item, User $user, array $planFeatures, ?array $enabledModules): bool
    {
        if ($item->permission && ! $user->can($item->permission)) {
            return false;
        }

        if ($item->feature) {
            // Absent key or falsy value → feature not included in this plan.
            if (empty($planFeatures[$item->feature])) {
                return false;
            }
        }

        // Module gating (onboarding wizard §3). $enabledModules === null means
        // the tenant never chose a module set → fail open (show everything).
        if ($item->module && $enabledModules !== null && ! in_array($item->module, $enabledModules, true)) {
            return false;
        }

        return true;
    }

    /**
     * The tenant's effective module set (onboarding selection ∩ plan allowance).
     * Shared with RoleController via App\Support\EnabledModules so the nav and
     * the permission matrix always agree on what is switched on.
     *
     * @return array<int, string>|null
     */
    private function enabledModules(?int $tenantId): ?array
    {
        return EnabledModules::forTenant($tenantId);
    }

    /**
     * The current tenant's plan feature map (empty when no plan is on record —
     * fail open so a missing subscription never hides the whole menu).
     */
    private function planFeatures(?int $tenantId): array
    {
        if (! $tenantId) {
            return [];
        }

        $plan = Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first()?->plan;

        return $plan?->feature_limits ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    private function favorites(int $userId): array
    {
        return MenuFavorite::with('menuItem')
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (MenuFavorite $f) => $f->menuItem && $f->menuItem->route)
            ->map(fn (MenuFavorite $f) => [
                'id' => $f->menuItem->id,
                'label' => $f->menuItem->label,
                'icon' => $f->menuItem->icon,
                'to' => $f->menuItem->route,
            ])
            ->values()
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function recents(int $userId): array
    {
        return MenuRecent::where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->limit(self::MAX_RECENTS)
            ->get()
            ->map(fn (MenuRecent $r) => [
                'label' => $r->label,
                'icon' => $r->icon,
                'to' => $r->route,
            ])
            ->all();
    }
}
