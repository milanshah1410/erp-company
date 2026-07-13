<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReturn extends Model
{
    use HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'return_number_prefix';

    protected $fillable = [
        'return_number', 'warehouse_id', 'return_type', 'customer_id', 'supplier_id', 'goods_receipt_id',
        'debit_note_number', 'status', 'return_date', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
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
