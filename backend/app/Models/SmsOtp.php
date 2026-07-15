<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time password issued over SMS. Not tenant-scoped (see the migration):
 * the portal-login flow resolves the customer cross-tenant before any tenant
 * context is established, so verification matches on phone + purpose and the
 * code is only ever stored hashed.
 */
class SmsOtp extends Model
{
    protected $fillable = [
        'tenant_id', 'customer_id', 'phone', 'purpose', 'code_hash',
        'attempts', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
