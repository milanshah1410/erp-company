<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's pinned menu item (project-stack.md §3 — Favorite Menu).
 */
class MenuFavorite extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'menu_item_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
