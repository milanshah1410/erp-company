<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A key/value setting owned by the SaaS operator (central DB).
 *
 * The tenant-side {@see Setting} equivalent is scoped to a workspace; this one is
 * the platform's own — its billing identity, tax defaults and invoice series
 * config. Reads are cached because the billing profile is touched on every
 * invoice we raise; every write clears the cache.
 */
class PlatformSetting extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    private const CACHE_KEY = 'platform_settings_map';

    private const CACHE_TTL = 600;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    /**
     * Every setting as a key => value map.
     *
     * @return array<string, mixed>
     */
    public static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => self::query()
            ->pluck('value', 'key')
            ->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::map()[$key] ?? null;

        return $value === null ? $default : $value;
    }

    public static function put(string $key, mixed $value): void
    {
        // `value` is cast to array, so a scalar round-trips through json_encode
        // fine — but only if it is passed through the cast, not a raw update().
        $row = self::firstOrNew(['key' => $key]);
        $row->value = $value;
        $row->save();

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Write several settings at once (one cache bust, not one per key).
     *
     * @param  array<string, mixed>  $values
     */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $row = self::firstOrNew(['key' => $key]);
            $row->value = $value;
            $row->save();
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
