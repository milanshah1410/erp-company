<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'po_number_prefix';

    protected $fillable = [
        'po_number', 'supplier_id', 'warehouse_id', 'status',
        'order_date', 'expected_date', 'subtotal', 'tax_amount', 'total',
        'notes', 'created_by', 'approved_by', 'approved_at',
        'first_approved_by', 'first_approved_at', 'requires_second_approval',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'first_approved_at' => 'datetime',
        'requires_second_approval' => 'boolean',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    /** Recompute the received/partially_received/received status from line items. */
    public function refreshReceiptStatus(): void
    {
        $items = $this->items;
        $anyReceived = $items->sum('received_quantity') > 0;
        $allReceived = $items->every(fn ($i) => $i->received_quantity >= $i->quantity);

        if ($allReceived) {
            $this->status = 'received';
        } elseif ($anyReceived) {
            $this->status = 'partially_received';
        }
        $this->save();
    }

}
