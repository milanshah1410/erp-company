<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    use HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'so_number_prefix';

    protected $fillable = [
        'so_number', 'customer_id', 'warehouse_id', 'status', 'channel',
        'order_date', 'subtotal', 'tax_amount', 'total',
        'notes', 'created_by', 'delivered_at',
        'fulfillment_stage', 'picked_at', 'packed_at', 'shipped_at', 'picked_by', 'packed_by', 'shipped_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivered_at' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
