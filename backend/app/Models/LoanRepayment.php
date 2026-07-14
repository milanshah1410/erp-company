<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_loan_id', 'installment_no', 'due_date', 'amount',
        'status', 'paid_on', 'salary_id',
    ];

    protected $casts = [
        'installment_no' => 'integer',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }
}
