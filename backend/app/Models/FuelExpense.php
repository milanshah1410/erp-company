<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fuel fill for a vehicle: litres × rate, with an odometer reading so mileage
 * (km per litre) can be derived across consecutive fills per vehicle.
 */
class FuelExpense extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'fuel_expense_number_prefix';

    protected $fillable = [
        'expense_number', 'vehicle_id', 'branch_id', 'fuel_date', 'fuel_type',
        'litres', 'rate_per_litre', 'amount', 'odometer', 'station',
        'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'fuel_date' => 'date',
        'litres' => 'decimal:3',
        'rate_per_litre' => 'decimal:2',
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
