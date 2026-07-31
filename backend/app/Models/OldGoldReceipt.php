<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scrap gold taken in from a customer: weighed, purity-tested, and valued at
 * the shop's buy rate.
 *
 * Stays unsettled until it is set off against a bill, because a customer often
 * sells old jewellery on one visit and buys on the next — an open receipt is a
 * credit they are holding.
 */
class OldGoldReceipt extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'old_gold_receipt_number_prefix';

    protected $fillable = [
        'receipt_number', 'customer_id', 'invoice_id', 'sales_order_id',
        'metal', 'purity', 'fineness', 'description',
        'gross_weight', 'net_weight', 'deduction_percent', 'chargeable_weight',
        'rate_per_gram', 'amount', 'received_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'fineness' => 'integer',
        'gross_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'deduction_percent' => 'decimal:3',
        'chargeable_weight' => 'decimal:3',
        'rate_per_gram' => 'decimal:2',
        'amount' => 'decimal:2',
        'received_on' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Has this scrap already been set off against a bill? */
    public function isSettled(): bool
    {
        return $this->invoice_id !== null;
    }
}
