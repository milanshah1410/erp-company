<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'date', 'amount', 'monthly_recovery', 'recovered',
        'reason', 'status', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'monthly_recovery' => 'decimal:2',
        'recovered' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Remaining amount still to be recovered from future payslips. */
    public function outstanding(): float
    {
        return round((float) $this->amount - (float) $this->recovered, 2);
    }
}
