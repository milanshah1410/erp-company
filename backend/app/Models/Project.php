<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A budgeted piece of work.
 *
 * Lifecycle: planning → active → completed, with on_hold and cancelled
 * available throughout. What makes it an ERP record rather than a task list is
 * the money: `budget_amount` is what it was sold for, and its actual cost is the
 * labour logged on timesheets plus the expenses booked against it
 * (ProjectCostingService owns that arithmetic).
 */
class Project extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'project_number_prefix';

    /** Statuses that no longer accept new work — tasks, hours or billing. */
    public const CLOSED_STATUSES = ['completed', 'cancelled'];

    /**
     * The cost sheet, attached by the controller for payloads that render it.
     *
     * A declared property rather than a dynamic one: assigning `$project->costing`
     * on a bare Eloquent model would land in the attribute bag and a later save()
     * would try to write a column that does not exist.
     *
     * @var array<string, mixed>|null
     */
    public ?array $costing = null;

    protected $fillable = [
        'project_number', 'name', 'customer_id', 'manager_id',
        'status', 'priority', 'billing_type',
        'budget_amount', 'estimated_hours', 'hourly_rate',
        'start_date', 'end_date', 'actual_start_date', 'actual_end_date',
        'description', 'notes', 'created_by',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'estimated_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Can approved hours on this project turn into an invoice?
     *
     * Only time-and-material work bills by the hour: a fixed-price project was
     * already sold for its budget, and internal work is never billed at all.
     */
    public function billsByTheHour(): bool
    {
        return $this->billing_type === 'time_and_material' && $this->customer_id !== null;
    }

    /** The rate a timesheet defaults to when the entry does not set its own. */
    public function defaultRate(): float
    {
        return (float) $this->hourly_rate;
    }
}
