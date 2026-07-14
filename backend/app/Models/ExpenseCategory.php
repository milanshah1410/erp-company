<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company expense head (Office Rent, Salary, Electricity, GST Payment, …).
 * Seeded with a sensible default set per tenant but fully editable.
 */
class ExpenseCategory extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
