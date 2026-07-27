<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A settlement of a shipment's goods value (SRS §24): `inbound` for export
 * proceeds realised from the overseas buyer, `outbound` for a remittance to the
 * overseas supplier. Logistics-vendor bills are settled on the expense row
 * itself, not here — this table tracks only the trade value, which is what the
 * Pending Payments board ages.
 *
 * `amount` is in the payment's own currency; `base_amount` is the converted
 * figure and is what Shipment::syncPaymentStatus sums, so a partial remittance in
 * a different currency still lands correctly against the invoice.
 */
class ShipmentPayment extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'shipment_id', 'direction', 'payment_date', 'currency', 'exchange_rate',
        'amount', 'base_amount', 'mode', 'bank_account_id', 'reference', 'remarks', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
    ];

    /** base_amount is always derived, never taken from the client. */
    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $payment->base_amount = round((float) $payment->amount * (float) $payment->exchange_rate, 2);
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
