<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'loan_number', 'principal', 'interest_rate', 'tenure_months',
        'emi', 'total_payable', 'repaid', 'disbursed_on', 'reason',
        'status', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'tenure_months' => 'integer',
        'emi' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'repaid' => 'decimal:2',
        'disbursed_on' => 'date',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Outstanding balance still owed on the loan. */
    public function outstanding(): float
    {
        return round((float) $this->total_payable - (float) $this->repaid, 2);
    }
}
