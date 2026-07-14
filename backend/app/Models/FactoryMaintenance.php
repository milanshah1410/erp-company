<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A factory / plant maintenance record — upkeep of a section, machine or the
 * premises. Optionally references an asset; captures downtime for reporting.
 */
class FactoryMaintenance extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'factory_maintenance_number_prefix';

    protected $fillable = [
        'maintenance_number', 'branch_id', 'asset_id', 'area', 'maintenance_type',
        'maintenance_date', 'performed_by', 'downtime_hours', 'amount',
        'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'downtime_hours' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
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
