<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved dashboard widget arrangement (project-stack.md §5).
 */
class DashboardLayout extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'widgets'];

    protected $casts = ['widgets' => 'array'];
}
