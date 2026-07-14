<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broker extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'mobile', 'address', 'gst_number', 'pan_number',
        'commission_percent', 'fixed_commission', 'tds_percent',
        'opening_balance', 'wallet_balance', 'is_active',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'fixed_commission' => 'decimal:2',
        'tds_percent' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // A new broker's wallet opens at its opening balance.
        static::creating(function (Broker $broker) {
            if ($broker->wallet_balance === null || (float) $broker->wallet_balance == 0.0) {
                $broker->wallet_balance = (float) ($broker->opening_balance ?? 0);
            }
        });
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(BrokerCommission::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BrokerSettlement::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(BrokerCommissionRate::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(BrokerWalletTransaction::class);
    }

    /** Net commission earned (accruals minus reversals) that has not yet been settled. */
    public function outstandingCommission(): float
    {
        $earned = (float) $this->commissions()->sum('commission_amount');
        $settled = (float) $this->settlements()->sum('amount');

        return round((float) $this->opening_balance + $earned - $settled, 2);
    }
}
