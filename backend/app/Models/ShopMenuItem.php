<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of one trade's starting menu.
 *
 * The shape mirrors MenuItem exactly — same columns, same "NULL route means a
 * group" convention — because a template row's whole job is to become a MenuItem
 * when a workspace of that trade is provisioned (App\Support\ShopMenus::
 * cloneForTenant).
 *
 * Unlike MenuItem this is not tenant-scoped at all: templates are product data,
 * owned by the SaaS operator and edited only from /platform/shop-menus. Nothing
 * on the tenant side reads this table — by the time a workspace exists, its rows
 * have already been copied into `menu_items`.
 */
class ShopMenuItem extends Model
{
    protected $fillable = [
        'shop_profile', 'parent_id', 'label', 'icon', 'route',
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
