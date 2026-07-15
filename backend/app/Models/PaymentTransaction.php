<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One hosted payment link / attempt to collect an invoice online (SRS §16).
 * Created "created", moves to "pending" once the customer starts checkout at a
 * gateway, then "paid" (reconciled into a {@see PaymentVoucher}) or a terminal
 * failure state. The `public_token` is the only identifier exposed on the
 * unauthenticated customer pay page.
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'customer_id', 'payment_gateway_account_id', 'payment_voucher_id',
        'gateway', 'public_token', 'amount', 'currency', 'status',
        'gateway_order_id', 'gateway_payment_id', 'checkout_url', 'meta',
        'failure_reason', 'expires_at', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $txn) {
            if (empty($txn->public_token)) {
                $txn->public_token = self::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = 'pl_'.Str::lower(Str::random(40));
        } while (self::withoutGlobalScopes()->where('public_token', $token)->exists());

        return $token;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayAccount::class, 'payment_gateway_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'failed', 'expired', 'cancelled'], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast() && ! $this->isPaid();
    }

    /** The absolute customer-facing pay-page URL for this link. */
    public function payUrl(): string
    {
        return config('payments.pay_page_base').'/pay/'.$this->public_token;
    }
}
