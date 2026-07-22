<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'subscription_id', 'amount', 'currency', 'status',
        'payment_gateway_ref', 'paid_at',
        'refund_amount', 'refund_reason', 'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
