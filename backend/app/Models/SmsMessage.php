<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound SMS log. Mirrors WhatsappMessage: every send (whether it actually
 * hit a gateway or was recorded by the no-op `log` driver) leaves a row here so
 * staff have a full audit trail. OTP bodies are logged with the code masked.
 */
class SmsMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'customer_id', 'phone', 'template', 'body',
        'provider', 'status', 'provider_message_id', 'error', 'created_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
