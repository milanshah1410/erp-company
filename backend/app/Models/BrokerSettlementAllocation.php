<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerSettlementAllocation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'broker_settlement_id', 'broker_commission_id', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BrokerSettlement::class, 'broker_settlement_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'broker_commission_id');
    }
}
