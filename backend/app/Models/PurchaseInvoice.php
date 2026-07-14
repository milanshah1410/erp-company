<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'invoice_number', 'purchase_order_id', 'supplier_id',
        'invoice_date', 'due_date', 'subtotal', 'tax_amount', 'total',
        'tds_section', 'tds_rate', 'tds_amount',
        'paid_amount', 'payment_status',
        'status', 'match_status', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'tds_rate' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function balanceDue(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    /** Recompute unpaid/partial/paid from the paid amount. */
    public function refreshPaymentStatus(): void
    {
        $paid = (float) $this->paid_amount;
        $this->payment_status = $paid <= 0
            ? 'unpaid'
            : ($paid + 0.005 >= (float) $this->total ? 'paid' : 'partial');
        $this->save();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
