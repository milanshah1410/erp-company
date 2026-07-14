<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasApprovalWorkflow;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maintenance / servicing event on a fixed asset — repair, preventive
 * service, calibration, AMC, etc. Tracks the next-service-due date so upcoming
 * servicing can be surfaced.
 */
class AssetMaintenance extends Model
{
    use BelongsToTenant, HasApprovalWorkflow, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'asset_maintenance_number_prefix';

    protected $fillable = [
        'maintenance_number', 'asset_id', 'branch_id', 'maintenance_type',
        'maintenance_date', 'next_service_date', 'performed_by', 'amount',
        'payment_method', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'next_service_date' => 'date',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
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
