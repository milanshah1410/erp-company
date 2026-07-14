<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-fuel vehicle expense — service, repair, insurance, tax, tolls, etc. —
 * booked against a vehicle. Fuel is tracked separately (see FuelExpense).
 */
class VehicleExpense extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'vehicle_expense_number_prefix';

    protected $fillable = [
        'expense_number', 'vehicle_id', 'branch_id', 'expense_date', 'expense_type',
        'odometer', 'amount', 'payee', 'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'odometer' => 'integer',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

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
