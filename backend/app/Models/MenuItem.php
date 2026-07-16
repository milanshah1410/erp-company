<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\InvalidatesTenantCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single navigation entry. A row with a NULL `route` is a group/section that
 * holds child rows; a row with a `route` is a clickable leaf.
 *
 * This is a shared catalogue (like permissions): global rows carry tenant_id
 * NULL and are visible to every tenant. It therefore deliberately does NOT use
 * the BelongsToTenant trait (whose fail-closed scope would hide the NULL rows);
 * the MenuController filters by tenant explicitly.
 */
class MenuItem extends Model
{
    // A MenuItem write (admin edit / reseed) invalidates the cached catalogue
    // the MenuController serves. cacheGroup() is overridden below to match the
    // 'menu_items' group used there. The catalogue spans tenants (NULL rows),
    // but bumping the current tenant's version plus the 10-min TTL keeps every
    // tenant's copy fresh enough.
    use InvalidatesTenantCache;

    public function cacheGroup(): string
    {
        return 'menu_items';
    }

    protected $fillable = [
        'tenant_id', 'parent_id', 'label', 'icon', 'route',
        'permission', 'feature', 'module', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
