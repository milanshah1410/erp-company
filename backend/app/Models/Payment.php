<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'invoice_id', 'amount', 'mode', 'payment_date', 'notes', 'created_by',
        // The till shift this money passed through. Null for anything not taken
        // at a counter — a bank receipt, a collection in the field, a payment
        // link settled online. Cash-up sums off this column, so a refund handed
        // over today lands on today's drawer even when the bill is months old.
        'pos_session_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosCounterSession::class, 'pos_session_id');
    }
}
