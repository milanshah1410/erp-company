<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class Invoice extends Model
{
    use BelongsToTenant, HasCustomFields, HasDocumentNumber, LogsAuditActivity, Searchable;

    protected static string $numberSettingKey = 'invoice_number_prefix';

    protected $fillable = [
        'invoice_number', 'sales_order_id', 'customer_id', 'broker_id',
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

    /**
     * Fields for the shared `invoices` index. The customer's name/company are
     * denormalised in so an invoice is findable by who it was billed to; the
     * date is also emitted as a unix timestamp for sortable recency.
     */
    public function toSearchableArray(): array
    {
        // Scout indexing runs on the queue with no tenant context, where the
        // fail-closed TenantScope would throw. This invoice already belongs to
        // its tenant, so resolve its customer with the scope bypassed rather
        // than via the (scoped) relation.
        $customer = $this->relationLoaded('customer')
            ? $this->customer
            : Customer::withoutGlobalScopes()->find($this->customer_id);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'invoice_number' => $this->invoice_number,
            'customer_name' => $customer?->name,
            'customer_company' => $customer?->company_name,
            'status' => $this->status,
            'total' => (float) $this->total,
            'invoice_date' => optional($this->invoice_date)->toDateString(),
            'invoice_date_ts' => optional($this->invoice_date)->getTimestamp(),
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** All brokers on this invoice (multi-broker), with rate/share overrides. */
    public function brokers(): HasMany
    {
        return $this->hasMany(InvoiceBroker::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function eInvoice(): HasOne
    {
        return $this->hasOne(EInvoice::class);
    }

    public function eWayBill(): HasOne
    {
        return $this->hasOne(EWayBill::class);
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
