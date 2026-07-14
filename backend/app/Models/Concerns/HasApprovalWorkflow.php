<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared light approval workflow for the Expenses sub-modules: draft → pending
 * → approved / rejected, plus a mark-paid flag. Host tables must carry the
 * columns added by the migration's workflowColumns() helper (status, paid_at,
 * rejection_reason, approved_by, approved_at).
 */
trait HasApprovalWorkflow
{
    /** Draft and rejected records are the only editable / deletable states. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function markSubmitted(): void
    {
        $this->update(['status' => 'pending', 'rejection_reason' => null]);
    }

    public function markApproved(?int $userId): void
    {
        $this->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
    }

    public function markRejected(?int $userId, ?string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    public function setPaid(bool $paid): void
    {
        $this->update(['paid_at' => $paid ? now() : null]);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }
}
