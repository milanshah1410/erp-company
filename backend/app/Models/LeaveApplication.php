<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApplication extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'from_date', 'to_date', 'days', 'half_day',
        'reason', 'status', 'approved_by', 'approved_at', 'remarks',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'days' => 'decimal:1',
        'half_day' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
