<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-defined automation rule (project-stack.md §11).
 */
class Workflow extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'trigger',
        'conditions', 'then_actions', 'else_actions',
        'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'then_actions' => 'array',
        'else_actions' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
