<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single movement in a broker's wallet. amount is signed: positive credits
 * the broker (the company owes them more — e.g. commission earned or a top-up),
 * negative debits (a reversal, a settlement payout, or an advance drawn).
 * balance_after snapshots the running wallet balance after this entry.
 */
class BrokerWalletTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'broker_id', 'type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
