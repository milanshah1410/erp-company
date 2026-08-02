<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on a gift voucher.
 *
 * Signed: a spend is positive, and a reversal — goods returned against a bill
 * the voucher paid for — is negative. One arithmetic rule (balance = face value
 * − sum of amounts) rather than a spends table and a credits table that have to
 * be kept in agreement.
 */
class GiftVoucherRedemption extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'gift_voucher_id', 'amount', 'invoice_id', 'sales_order_id',
        'redeemed_at', 'reference', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
