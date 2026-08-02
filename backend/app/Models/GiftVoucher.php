<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gift voucher — money the shop has already collected against goods it has not
 * yet sold.
 *
 * Which is why it carries a `balance` rather than a used flag: a ₹2,000 voucher
 * against a ₹1,450 bill is the ordinary case and leaves the shop owing ₹550. The
 * balance is the drawn-down face value, and `redemptions` is the signed ledger
 * that produced it.
 *
 * Sits under the Loyalty & Offers module beside coupons and points, not under
 * any one trade: a gift shop, a supermarket and a garment showroom all sell the
 * same instrument.
 */
class GiftVoucher extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'customer_id', 'recipient_name', 'recipient_phone',
        'face_value', 'balance', 'issued_on', 'expires_on', 'status',
        'issued_invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'face_value' => 'decimal:2',
        'balance' => 'decimal:2',
        'issued_on' => 'date',
        'expires_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (GiftVoucher $voucher) {
            if ($voucher->code) {
                $voucher->code = strtoupper(preg_replace('/\s+/', '', $voucher->code) ?: '');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'issued_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftVoucherRedemption::class);
    }

    /**
     * Has this lapsed? Derived from the date rather than read off `status`,
     * because nothing sweeps the table overnight — a voucher that expired at
     * midnight must not be spendable at nine the next morning merely because no
     * job has run.
     */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    /** Can this be put against a bill right now? */
    public function isSpendable(): bool
    {
        return $this->refusalReason() === null;
    }

    /**
     * The counter's answer. Null when the voucher is good, otherwise a sentence
     * that can be read out to the person holding it — "expired on 12 Jun 2026"
     * is a far better thing to be able to say than "invalid".
     */
    public function refusalReason(): ?string
    {
        if ($this->status === 'cancelled') {
            return 'This voucher was cancelled.';
        }

        if ($this->isExpired()) {
            return 'This voucher expired on '.$this->expires_on->format('d M Y').'.';
        }

        if ((float) $this->balance <= 0) {
            return 'This voucher has already been fully redeemed.';
        }

        return null;
    }

    /**
     * Bring `status` back in line with the balance and the calendar.
     *
     * Called after every movement rather than on a schedule: the column exists
     * so the register can be filtered and counted cheaply, and the moment it
     * disagrees with the balance it is worse than not having it.
     */
    public function refreshStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $this->status = match (true) {
            (float) $this->balance <= 0 => 'redeemed',
            $this->isExpired() => 'expired',
            default => 'active',
        };

        $this->save();
    }

    /**
     * A fresh voucher code — four groups of four, from an alphabet with the
     * characters that get misread at a counter (0/O, 1/I, 5/S) taken out.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRTUVWXY2346789';
        $groups = [];

        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
