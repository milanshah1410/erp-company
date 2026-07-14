<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'salary_structure_id', 'month',
        'basic', 'hra', 'conveyance', 'medical', 'special_allowance', 'other_earnings',
        'overtime_amount', 'bonus_amount', 'gross', 'allowances',
        'pf_employee', 'pf_employer', 'esi_employee', 'esi_employer',
        'professional_tax', 'tds', 'advance_deduction', 'loan_deduction', 'other_deductions',
        'deductions', 'net_salary',
        'working_days', 'present_days', 'paid_days', 'lop_days', 'auto_generated',
        'status', 'paid_on', 'remarks',
    ];

    protected $casts = [
        'month' => 'date',
        'paid_on' => 'date',
        'basic' => 'decimal:2',
        'hra' => 'decimal:2',
        'conveyance' => 'decimal:2',
        'medical' => 'decimal:2',
        'special_allowance' => 'decimal:2',
        'other_earnings' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'gross' => 'decimal:2',
        'allowances' => 'decimal:2',
        'pf_employee' => 'decimal:2',
        'pf_employer' => 'decimal:2',
        'esi_employee' => 'decimal:2',
        'esi_employer' => 'decimal:2',
        'professional_tax' => 'decimal:2',
        'tds' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'loan_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'working_days' => 'decimal:1',
        'present_days' => 'decimal:1',
        'paid_days' => 'decimal:1',
        'lop_days' => 'decimal:1',
        'auto_generated' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }
}
