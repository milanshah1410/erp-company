<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's recently-visited screen (project-stack.md §3 — Recent Menu).
 * Kept to the most recent handful of routes per user (pruned by the controller).
 */
class MenuRecent extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'route', 'label', 'icon', 'visited_at'];

    protected $casts = ['visited_at' => 'datetime'];
}
