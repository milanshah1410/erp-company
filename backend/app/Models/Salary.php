<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'month', 'basic', 'allowances', 'deductions',
        'net_salary', 'status', 'paid_on', 'remarks',
    ];

    protected $casts = [
        'month' => 'date',
        'paid_on' => 'date',
        'basic' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
