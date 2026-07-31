<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours one person spent on a project on one day.
 *
 * The rate is snapshotted at entry, not read through to the project: a rate
 * change next quarter must not silently restate what last quarter's work cost.
 * `amount` is the same snapshot arithmetic stored once, so cost rollups are a
 * SUM rather than a per-row multiplication.
 */
class ProjectTimesheet extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Only approved hours may be invoiced. */
    public const BILLABLE_STATUS = 'approved';

    protected $fillable = [
        'project_id', 'project_task_id', 'employee_id', 'work_date', 'hours',
        'description', 'is_billable', 'hourly_rate', 'amount',
        'status', 'approved_by', 'approved_at', 'invoice_id', 'created_by',
    ];

    protected $casts = [
        'work_date' => 'date',
        'hours' => 'decimal:2',
        'is_billable' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Recompute the stored value from the hours and the snapshotted rate. */
    public function recalculate(): void
    {
        $this->amount = round((float) $this->hours * (float) $this->hourly_rate, 2);
    }

    /** Approved, billable, and not already carried out on an invoice. */
    public function isInvoiceable(): bool
    {
        return $this->status === self::BILLABLE_STATUS
            && $this->is_billable
            && $this->invoice_id === null;
    }
}
