<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FollowUp extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'followupable_type', 'followupable_id', 'type', 'due_at', 'title',
        'notes', 'status', 'completed_at', 'outcome', 'assigned_to', 'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function followupable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Pending follow-ups whose due date has already passed. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'pending')->where('due_at', '<', now());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
