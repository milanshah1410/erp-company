<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-scoped cache helper. Every key is namespaced with the active tenant id
 * (see App\Support\TenantContext) so one tenant can never read another tenant's
 * cached data — the isolation guarantee the row-level TenantScope gives queries,
 * applied to the cache layer.
 *
 * Driver-agnostic: it goes through Laravel's Cache facade, so it uses whatever
 * store CACHE_STORE points at (database today, redis once installed). The keys it
 * produces look like "tenant_15_dashboard", "tenant_20_permissions", etc.
 *
 * Usage:
 *   TenantCache::remember('dashboard', 300, fn () => $this->buildKpis());
 *   TenantCache::forget('dashboard');            // invalidate for current tenant
 */
class TenantCache
{
    /**
     * Namespace a bare key with the active tenant id, e.g. "dashboard" for
     * tenant 15 becomes "tenant_15_dashboard". Falls back to "shared" when no
     * tenant context is set (console/landlord), keeping the key deterministic.
     */
    public static function key(string $key): string
    {
        $tenantId = app(TenantContext::class)->id();

        return 'tenant_'.($tenantId ?? 'shared').'_'.$key;
    }

    /**
     * Remember a value for the current tenant. $ttl is in seconds; pass null for
     * a value that lives until explicitly forgotten (forever).
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, ?int $ttl, Closure $callback)
    {
        $namespaced = self::key($key);

        return $ttl === null
            ? Cache::rememberForever($namespaced, $callback)
            : Cache::remember($namespaced, $ttl, $callback);
    }

    /** Read a cached value for the current tenant (or $default on miss). */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::get(self::key($key), $default);
    }

    /** Write a value for the current tenant. $ttl in seconds; null = forever. */
    public static function put(string $key, mixed $value, ?int $ttl): void
    {
        if ($ttl === null) {
            Cache::forever(self::key($key), $value);
        } else {
            Cache::put(self::key($key), $value, $ttl);
        }
    }

    /** Invalidate a cached key for the current tenant. */
    public static function forget(string $key): void
    {
        Cache::forget(self::key($key));
    }
}
