<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarketplaceItem;
use Illuminate\Support\Facades\Cache;

/**
 * The runtime half of the marketplace: given a catalogue key and a tenant, may
 * this workspace use it? (Volume 11 §"Integration Marketplace" / §"Device
 * Marketplace".)
 *
 * Every tenant-facing surface that offers a connector or a device brand asks
 * here first — both to filter the picker and to refuse the write. Filtering
 * alone would be theatre: a tenant that already knows the key could still POST
 * it. So the two call sites are always paired (list + store).
 *
 * Three things can withhold an offering:
 *
 *   1. it is roadmap-only (`status: planned`) — nothing behind it works, so the
 *      gate refuses regardless of what the row says;
 *   2. the operator switched it off platform-wide;
 *   3. the operator restricted it to plans this tenant is not on.
 *
 * Withholding is packaging, not security: an already-connected integration keeps
 * working. Disabling Meesho stops new connections and hides it from the picker —
 * it does not sever a tenant mid-season. Pulling a live connection is the
 * tenant's action (or a support one), never a silent side-effect of a price-list
 * edit.
 *
 * The row map is cached for ten minutes, like the module map, so the gate costs
 * nothing on the hot path. Writes from the platform panel bust it.
 */
final class MarketplaceGate
{
    private const CACHE_KEY = 'marketplace_items_map_v1';

    private const TTL = 600;

    /** Drop the cached map — call after any operator write. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * May this tenant connect $key?
     *
     * $tenantId null means "is it on sale at all" — used by surfaces that run
     * without tenant context.
     */
    public static function allowsIntegration(string $key, ?int $tenantId = null): bool
    {
        if (! IntegrationCatalogue::isLive($key)) {
            return false;
        }

        return self::allows(MarketplaceItem::KIND_INTEGRATION, $key, $tenantId);
    }

    /**
     * May this tenant configure this device?
     *
     * Both gates apply: withdrawing the barcode category withdraws every brand
     * in it, and withdrawing one brand leaves the rest of the category standing.
     */
    public static function allowsDevice(string $type, ?string $driver = null, ?int $tenantId = null): bool
    {
        if (! self::allows(MarketplaceItem::KIND_DEVICE, MarketplaceItem::deviceKey($type), $tenantId)) {
            return false;
        }

        if ($driver === null || $driver === '') {
            return true;
        }

        return self::allows(MarketplaceItem::KIND_DEVICE, MarketplaceItem::deviceKey($type, $driver), $tenantId);
    }

    /**
     * Every integration key this tenant may connect, catalogue order preserved.
     *
     * @return string[]
     */
    public static function integrationKeys(?int $tenantId = null): array
    {
        return array_values(array_filter(
            IntegrationCatalogue::keys(),
            fn (string $key) => self::allowsIntegration($key, $tenantId),
        ));
    }

    /**
     * Filter a list of catalogue entries down to what this tenant may use.
     *
     * @template T of array<string, mixed>
     *
     * @param  iterable<T>  $entries
     * @param  callable(T): string  $keyOf
     * @return list<T>
     */
    public static function filterIntegrations(iterable $entries, callable $keyOf, ?int $tenantId = null): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (self::allowsIntegration($keyOf($entry), $tenantId)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The row-level decision, shared by both kinds.
     *
     * A key with no row yet (a connector shipped since the last panel visit)
     * fails open — the code catalogues it, so it is sold until an operator says
     * otherwise. MarketplaceItem::syncCatalogue() materialises the row the next
     * time the panel is opened.
     */
    private static function allows(string $kind, string $key, ?int $tenantId): bool
    {
        $item = self::map()["{$kind}:{$key}"] ?? null;

        if ($item === null) {
            return true;
        }

        if (! $item['is_enabled']) {
            return false;
        }

        $slugs = $item['plan_slugs'];
        if (! is_array($slugs) || $slugs === []) {
            return true;
        }

        $planSlug = $tenantId === null ? null : self::planSlug($tenantId);

        return $planSlug === null || in_array($planSlug, $slugs, true);
    }

    /**
     * kind:key => {is_enabled, plan_slugs}, cached.
     *
     * @return array<string, array{is_enabled: bool, plan_slugs: array<int, string>|null}>
     */
    private static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function (): array {
            $map = [];

            foreach (MarketplaceItem::query()->get(['kind', 'key', 'is_enabled', 'plan_slugs']) as $item) {
                $map["{$item->kind}:{$item->key}"] = [
                    'is_enabled' => (bool) $item->is_enabled,
                    'plan_slugs' => $item->plan_slugs,
                ];
            }

            return $map;
        });
    }

    /**
     * The tenant's plan slug, memoised per request — one gate check per field on
     * a device form would otherwise re-query the subscription for each.
     *
     * @var array<int, string|null>
     */
    private static array $planSlugs = [];

    private static function planSlug(int $tenantId): ?string
    {
        if (! array_key_exists($tenantId, self::$planSlugs)) {
            self::$planSlugs[$tenantId] = TenantLimits::planFor($tenantId)?->slug;
        }

        return self::$planSlugs[$tenantId];
    }
}
