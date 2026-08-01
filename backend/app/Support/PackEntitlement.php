<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Plan;
use App\Models\PlatformPack;
use App\Models\Subscription;
use App\Models\TenantPack;
use Illuminate\Support\Facades\Cache;

/**
 * What the marketplace packs a workspace holds actually entitle it to
 * (Volume 11 §Module Marketplace).
 *
 * Read side only — {@see \App\Services\Billing\PackEntitlementService} owns granting
 * and revoking. Kept static and here rather than on the service because the two
 * callers are {@see EnabledModules} (nav + permission matrix, on every menu read)
 * and {@see TenantLimits} (metered caps, on every create), neither of which should
 * have to resolve a service out of the container to answer a question about
 * entitlement.
 *
 * Two sources are unioned:
 *
 *  - **granted** — a live `tenant_packs` row: bought or comped by an operator.
 *  - **bundled** — a pack whose `included_in_plans` lists the tenant's current plan,
 *    which entitles with no row at all. Resolving it at read time means a plan
 *    change takes effect immediately in both directions; materialising rows would
 *    leave a downgraded tenant holding packs its new tier does not include.
 */
final class PackEntitlement
{
    private const CACHE_TTL = 300;

    /**
     * Module keys the tenant's packs unlock, granted and bundled.
     *
     * @return list<string>
     */
    public static function modulesFor(?int $tenantId): array
    {
        return $tenantId ? self::resolve($tenantId)['modules'] : [];
    }

    /**
     * Additive bumps to the tenant's metered caps, summed across every pack it
     * holds — two +5-seat packs are worth ten seats.
     *
     * @return array<string, int>
     */
    public static function limitBumpsFor(?int $tenantId): array
    {
        return $tenantId ? self::resolve($tenantId)['limit_bumps'] : [];
    }

    /**
     * The packs behind that entitlement, for display: each with how it was
     * acquired.
     *
     * @return list<array{slug: string, name: string, type: string, source: string}>
     */
    public static function packsFor(?int $tenantId): array
    {
        return $tenantId ? self::resolve($tenantId)['packs'] : [];
    }

    /**
     * Live grants (rows), newest first. Not cached — the operator's Assignments
     * screen wants the rows themselves, with their billing history.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TenantPack>
     */
    public static function liveGrants(int $tenantId)
    {
        return TenantPack::query()
            ->with('pack')
            ->where('tenant_id', $tenantId)
            ->live()
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Packs bundled with a plan (entitlement with no purchase).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PlatformPack>
     */
    public static function bundledWith(?Plan $plan)
    {
        if (! $plan) {
            return PlatformPack::query()->whereRaw('1 = 0')->get();
        }

        return PlatformPack::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (PlatformPack $pack) => $pack->isBundledWith($plan))
            ->values();
    }

    public static function flush(int $tenantId): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }

    /**
     * @return array{modules: list<string>, limit_bumps: array<string, int>, packs: list<array<string, string>>}
     */
    private static function resolve(int $tenantId): array
    {
        return Cache::remember(self::cacheKey($tenantId), self::CACHE_TTL, function () use ($tenantId): array {
            $plan = Subscription::with('plan')->where('tenant_id', $tenantId)->latest('id')->first()?->plan;

            $entries = [];

            foreach (self::liveGrants($tenantId) as $grant) {
                if ($grant->pack && $grant->pack->is_active) {
                    $entries[] = [$grant->pack, $grant->source];
                }
            }

            foreach (self::bundledWith($plan) as $pack) {
                $entries[] = [$pack, 'bundled'];
            }

            $modules = [];
            $bumps = [];
            $packs = [];
            $seen = [];

            foreach ($entries as [$pack, $source]) {
                // A pack both bought and bundled counts once, and reads as bundled
                // only if that is how it arrived first.
                if (isset($seen[$pack->slug])) {
                    continue;
                }
                $seen[$pack->slug] = true;

                $modules = array_merge($modules, $pack->moduleKeys());

                foreach ($pack->limitBumps() as $key => $value) {
                    $bumps[$key] = ($bumps[$key] ?? 0) + $value;
                }

                $packs[] = [
                    'slug' => $pack->slug,
                    'name' => $pack->name,
                    'type' => $pack->type,
                    'source' => $source,
                ];
            }

            return [
                'modules' => array_values(array_unique($modules)),
                'limit_bumps' => $bumps,
                'packs' => $packs,
            ];
        });
    }

    private static function cacheKey(int $tenantId): string
    {
        return "tenant_{$tenantId}_pack_entitlement";
    }
}
