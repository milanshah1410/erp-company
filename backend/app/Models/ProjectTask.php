<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit of work on a project, optionally nested one level under another task.
 *
 * `progress_percent` and `status` are kept in step by the model rather than by
 * whoever is clicking: marking a task done that still reads 40% complete is a
 * discrepancy nobody would go back and fix.
 */
class ProjectTask extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'project_id', 'parent_id', 'assigned_to', 'title', 'description',
        'status', 'priority', 'estimated_hours', 'progress_percent',
        'start_date', 'due_date', 'completed_at', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'progress_percent' => 'integer',
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class);
    }

    /**
     * Keep status and progress consistent, whichever one the caller moved.
     * Done implies 100% and stamps the completion time; moving off done clears
     * it and pulls a stuck 100% back to 99 so the two never contradict.
     */
    public function syncProgress(): void
    {
        if ($this->status === 'done') {
            $this->progress_percent = 100;
            $this->completed_at = $this->completed_at ?? now();

            return;
        }

        $this->completed_at = null;

        if ($this->progress_percent >= 100) {
            $this->progress_percent = 99;
        }
    }

    /** Past its due date and not finished. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== 'done';
    }
}
