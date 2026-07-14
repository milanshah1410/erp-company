<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBonus extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'month', 'type', 'amount', 'remarks',
        'status', 'approved_by', 'approved_at', 'salary_id',
    ];

    protected $casts = [
        'month' => 'date',
        'amount' => 'decimal:2',
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
}
