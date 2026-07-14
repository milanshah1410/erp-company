<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cash or bank account the business holds. Cash-type accounts feed the Cash
 * Book, bank-type accounts feed the Bank Book. The opening balance seeds the
 * running total of the respective book.
 */
class BankAccount extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'name', 'type', 'is_petty_cash', 'bank_name', 'account_number', 'ifsc', 'branch_name',
        'opening_balance', 'opening_balance_date', 'branch_id', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_petty_cash' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
