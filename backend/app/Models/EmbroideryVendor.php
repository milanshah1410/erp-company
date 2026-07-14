<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmbroideryVendor extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'mobile', 'address', 'gst_number',
        'opening_balance', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function works(): HasMany
    {
        return $this->hasMany(EmbroideryWork::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(EmbroideryReceipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmbroideryPayment::class);
    }

    /** Total earnings accrued from received work (ledger credits). */
    public function totalEarned(): float
    {
        return round((float) $this->receipts()->sum('amount'), 2);
    }

    /** Total paid out to this vendor (ledger debits). */
    public function totalPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** Unpaid balance owed to the vendor: opening + earned - paid. */
    public function outstandingBalance(): float
    {
        return round((float) $this->opening_balance + $this->totalEarned() - $this->totalPaid(), 2);
    }
}
