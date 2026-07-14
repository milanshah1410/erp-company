<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee expense claim: a set of out-of-pocket line items submitted by a
 * staff member for reimbursement. Runs through the shared approval workflow;
 * marking it paid records the reimbursement.
 */
class EmployeeClaim extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'employee_claim_number_prefix';

    protected $fillable = [
        'claim_number', 'employee_id', 'branch_id', 'claim_date', 'title',
        'total_amount', 'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeClaimItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Recompute the header total from its line items. */
    public function recalcTotal(): void
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $this->update(['total_amount' => round((float) $items->sum('amount'), 2)]);
    }
}
