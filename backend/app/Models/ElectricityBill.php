<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An electricity bill for a branch / connection. Captures meter readings so
 * units consumed and effective per-unit cost can be trended over billing
 * periods.
 */
class ElectricityBill extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'electricity_bill_number_prefix';

    protected $fillable = [
        'bill_number', 'branch_id', 'meter_number', 'provider', 'connection_name',
        'bill_date', 'billing_period_start', 'billing_period_end', 'due_date',
        'previous_reading', 'current_reading', 'units_consumed', 'amount',
        'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'due_date' => 'date',
        'previous_reading' => 'decimal:2',
        'current_reading' => 'decimal:2',
        'units_consumed' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
