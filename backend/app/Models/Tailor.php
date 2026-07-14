<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tailor extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'mobile', 'address', 'skill',
        'opening_balance', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function works(): HasMany
    {
        return $this->hasMany(TailorWork::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TailorWorkCompletion::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TailorPayment::class);
    }

    /** Total earnings accrued from completed work (ledger credits). */
    public function totalEarned(): float
    {
        return round((float) $this->completions()->sum('amount'), 2);
    }

    /** Total paid out to this tailor (ledger debits). */
    public function totalPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** Unpaid balance owed to the tailor: opening + earned - paid. */
    public function outstandingBalance(): float
    {
        return round((float) $this->opening_balance + $this->totalEarned() - $this->totalPaid(), 2);
    }
}
