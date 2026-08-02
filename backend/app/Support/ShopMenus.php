<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MenuItem;
use App\Models\ShopMenuItem;
use Illuminate\Support\Collection;

/**
 * Shop-wise menu templates — the layer between the global catalogue and a
 * workspace's own navigation.
 *
 *   Menu Catalogue (menu_items, tenant_id NULL)  — every screen the product has
 *   Shop template  (shop_menu_items)             — what *this trade* opens on
 *   Tenant menu    (menu_items, tenant_id set)   — what this workspace sees
 *
 * A template is a curated projection of the catalogue: it names screens that
 * already exist and puts them in a trade's order, under a trade's headings. It
 * never invents a route, which is why a template row carries the catalogue's own
 * permission/feature/module — a screen dragged into a bespoke group keeps the
 * gate it shipped with.
 *
 * Templates are optional per trade. A trade with no template provisions exactly
 * as it always did (the whole catalogue, cloned), so adding this layer changed
 * nothing for the trades nobody has curated yet.
 */
final class ShopMenus
{
    /**
     * Does this trade have a template?
     *
     * Cheap enough to call on the provisioning path — one indexed existence
     * check — and the honest question, since "no template" is a normal state
     * rather than a missing row.
     */
    public static function has(?string $shopProfile): bool
    {
        return $shopProfile !== null
            && $shopProfile !== ''
            && ShopMenuItem::where('shop_profile', $shopProfile)->exists();
    }

    /**
     * Every trade that has a template, with its row/screen counts.
     *
     * One grouped query rather than a count per trade: the platform screen lists
     * all sixty trades and would otherwise fire sixty of them.
     *
     * @return array<string, array{rows: int, screens: int, hidden: int}>
     */
    public static function summary(): array
    {
        return ShopMenuItem::query()
            ->select('shop_profile')
            ->selectRaw('count(*) as rows_total')
            ->selectRaw('sum(case when route is null then 0 else 1 end) as screens_total')
            ->selectRaw('sum(case when is_active = 0 then 1 else 0 end) as hidden_total')
            ->groupBy('shop_profile')
            ->get()
            ->mapWithKeys(static fn ($row) => [$row->shop_profile => [
                'rows' => (int) $row->rows_total,
                'screens' => (int) $row->screens_total,
                'hidden' => (int) $row->hidden_total,
            ]])
            ->all();
    }

    /**
     * A trade's rows, ordered as the sidebar orders them.
     *
     * @return Collection<int, ShopMenuItem>
     */
    public static function rows(string $shopProfile): Collection
    {
        return ShopMenuItem::where('shop_profile', $shopProfile)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'label', 'icon', 'route', 'permission', 'feature', 'module', 'sort_order', 'is_active']);
    }

    /**
     * Clone a trade's template into rows owned by this tenant.
     *
     * The trade twin of TenantMenuAccess::cloneForTenant, and it holds to the
     * same two rules: a tenant that already owns rows is left alone (idempotent,
     * because provisioning retries and the access panel both call it), and parent
     * links are remapped to the freshly-minted ids as the tree is walked
     * top-down.
     *
     * Returns false when the trade has no template — the caller then falls back
     * to cloning the whole catalogue, which is what every workspace got before
     * templates existed.
     */
    public static function cloneForTenant(int $tenantId, ?string $shopProfile): bool
    {
        if (! self::has($shopProfile)) {
            return false;
        }

        if (MenuItem::where('tenant_id', $tenantId)->exists()) {
            return true; // already cloned — nothing to do, but the trade was served
        }

        $byParent = ShopMenuItem::where('shop_profile', $shopProfile)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        $clone = function (?int $templateParentId, ?int $newParentId) use (&$clone, $byParent, $tenantId): void {
            foreach ($byParent->get($templateParentId, collect()) as $row) {
                /** @var ShopMenuItem $row */
                $copy = MenuItem::create([
                    'tenant_id' => $tenantId,
                    'parent_id' => $newParentId,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'feature' => $row->feature,
                    'module' => $row->module,
                    'sort_order' => $row->sort_order,
                    'is_active' => $row->is_active,
                ]);

                $clone($row->id, $copy->id);
            }
        };

        $clone(null, null);

        return true;
    }

    /**
     * Seed a trade's template from the global catalogue, verbatim.
     *
     * What "create a template for this trade" means when the operator has no
     * curated default to start from: they get the full catalogue as a starting
     * tree and prune it by dragging, rather than an empty page.
     *
     * Replaces whatever the trade had. The caller is responsible for asking
     * first — ShopMenuTemplateController does.
     */
    public static function seedFromCatalogue(string $shopProfile): int
    {
        ShopMenuItem::where('shop_profile', $shopProfile)->delete();

        $byParent = MenuItem::whereNull('tenant_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        $count = 0;

        $clone = function (?int $globalParentId, ?int $newParentId) use (&$clone, $byParent, $shopProfile, &$count): void {
            foreach ($byParent->get($globalParentId, collect()) as $row) {
                /** @var MenuItem $row */
                $copy = ShopMenuItem::create([
                    'shop_profile' => $shopProfile,
                    'parent_id' => $newParentId,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'feature' => $row->feature,
                    'module' => $row->module,
                    'sort_order' => $row->sort_order,
                    'is_active' => $row->is_active,
                ]);
                $count++;

                $clone($row->id, $copy->id);
            }
        };

        $clone(null, null);

        return $count;
    }
}
