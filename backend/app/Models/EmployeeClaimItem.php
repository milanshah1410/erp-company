<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line on an employee expense claim (one receipt / out-of-pocket item).
 */
class EmployeeClaimItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_claim_id', 'expense_category_id', 'expense_date', 'description', 'amount',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EmployeeClaim::class, 'employee_claim_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
