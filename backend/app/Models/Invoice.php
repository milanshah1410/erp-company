<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'invoice_number_prefix';

    protected $fillable = [
        'invoice_number', 'sales_order_id', 'customer_id',
        'invoice_date', 'due_date', 'total', 'paid_amount', 'credited_amount', 'status',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credited_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->due_date)) {
                $termDays = (int) Setting::get('sales_payment_term_days', '30');
                $invoice->due_date = \Carbon\Carbon::parse($invoice->invoice_date)->addDays($termDays);
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceDue(): float
    {
        return round((float) $this->total - (float) $this->paid_amount - (float) $this->credited_amount, 2);
    }

    /** Recompute paid/partial/unpaid from the paid + credited amounts. */
    public function refreshStatus(): void
    {
        $covered = (float) $this->paid_amount + (float) $this->credited_amount;
        $this->status = $covered <= 0
            ? 'unpaid'
            : ($covered + 0.005 >= (float) $this->total ? 'paid' : 'partial');
        $this->save();
    }

}
