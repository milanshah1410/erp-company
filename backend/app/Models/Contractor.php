<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contractor is a subcontractor who supplies several tailors. Tailors point at
 * an optional contractor; the money ledger stays at the tailor level and the
 * contractor simply rolls its tailors' figures up for reporting and settlement.
 */
class Contractor extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'mobile', 'address', 'gstin', 'pan',
        'opening_balance', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function tailors(): HasMany
    {
        return $this->hasMany(Tailor::class);
    }

    /** Sum of earnings accrued across all of this contractor's tailors. */
    public function totalEarned(): float
    {
        return round($this->tailors->sum(fn (Tailor $t) => $t->totalEarned()), 2);
    }

    /** Sum of payouts made across all of this contractor's tailors. */
    public function totalPaid(): float
    {
        return round($this->tailors->sum(fn (Tailor $t) => $t->totalPaid()), 2);
    }

    /** Contractor opening balance plus the outstanding owed to each tailor. */
    public function outstandingBalance(): float
    {
        return round(
            (float) $this->opening_balance
            + $this->tailors->sum(fn (Tailor $t) => $t->outstandingBalance()),
            2
        );
    }
}
