<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerCommission extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'broker_id', 'invoice_id', 'sales_return_id', 'type',
        'base_amount', 'commission_percent', 'fixed_commission',
        'commission_amount', 'settled_amount', 'status', 'notes',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'fixed_commission' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'settled_amount' => 'decimal:2',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BrokerSettlementAllocation::class);
    }

    /** Outstanding (unsettled) amount on this accrual row. */
    public function outstanding(): float
    {
        return round((float) $this->commission_amount - (float) $this->settled_amount, 2);
    }

    /** Recompute pending/partial/paid from settled vs commission amount. */
    public function refreshStatus(): void
    {
        $settled = (float) $this->settled_amount;
        $this->status = $settled <= 0
            ? 'pending'
            : ($settled + 0.005 >= (float) $this->commission_amount ? 'paid' : 'partial');
        $this->save();
    }
}
