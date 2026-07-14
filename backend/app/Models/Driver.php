<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery driver. Master referenced by dispatch plans; optionally linked to
 * an HR employee record. License-expiry is tracked for compliance alerts.
 */
class Driver extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'phone', 'license_no', 'license_type', 'license_expiry',
        'employee_id', 'joining_date', 'address', 'is_active',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'joining_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dispatchPlans(): HasMany
    {
        return $this->hasMany(DispatchPlan::class);
    }
}
