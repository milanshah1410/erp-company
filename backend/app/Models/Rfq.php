<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rfq extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'rfqs';

    protected static string $numberSettingKey = 'rfq_number_prefix';

    protected $fillable = [
        'rfq_number', 'purchase_indent_id', 'warehouse_id', 'status',
        'rfq_date', 'response_deadline', 'awarded_supplier_id', 'purchase_order_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'rfq_date' => 'date',
        'response_deadline' => 'date',
    ];

    public function purchaseIndent(): BelongsTo
    {
        return $this->belongsTo(PurchaseIndent::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(RfqSupplier::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class);
    }

    public function awardedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'awarded_supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
