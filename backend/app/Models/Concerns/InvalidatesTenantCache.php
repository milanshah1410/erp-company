<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\TenantCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-invalidates a model's cache group whenever a row is written or deleted
 * (§17 Performance — Caching). Pair it with TenantCache::rememberVersioned():
 * cache reads under the same group name and any create/update/delete bumps the
 * group's version, so the next read rebuilds from the database.
 *
 * The group defaults to the table name; override cacheGroup() to share one
 * group across related models. Intended for small, read-hot, rarely-written
 * reference data (settings, menu catalogue, lookup lists) where the read/write
 * ratio makes caching clearly worthwhile.
 */
trait InvalidatesTenantCache
{
    public static function bootInvalidatesTenantCache(): void
    {
        $bump = static function (Model $model): void {
            TenantCache::bump($model->cacheGroup());
        };

        static::saved($bump);
        static::deleted($bump);
    }

    /** Cache group this model's reads live under (default: its table name). */
    public function cacheGroup(): string
    {
        return $this->getTable();
    }
}
