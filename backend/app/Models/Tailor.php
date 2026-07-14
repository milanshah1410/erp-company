<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tailor extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'contractor_id', 'code', 'name', 'mobile', 'address', 'skill',
        'opening_balance', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

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

    public function bonuses(): HasMany
    {
        return $this->hasMany(TailorBonus::class);
    }

    public function alterations(): HasMany
    {
        return $this->hasMany(TailorAlteration::class);
    }

    /** Piece-rate earnings accrued from completed work (ledger credit). */
    public function totalPieceEarnings(): float
    {
        return round((float) $this->completions()->sum('amount'), 2);
    }

    /** Approved/paid bonuses (ledger credit). */
    public function totalBonus(): float
    {
        return round((float) $this->bonuses()->whereIn('status', ['approved', 'paid'])->sum('amount'), 2);
    }

    /** Total earnings credited to the tailor: piece work + approved bonuses. */
    public function totalEarned(): float
    {
        return round($this->totalPieceEarnings() + $this->totalBonus(), 2);
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
