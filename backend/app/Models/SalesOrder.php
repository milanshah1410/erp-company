<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'so_number_prefix';

    protected $fillable = [
        'so_number', 'customer_id', 'broker_id', 'salesman_id', 'warehouse_id', 'status', 'channel',
        'order_date', 'subtotal', 'item_discount_total', 'discount_type', 'discount_value',
        'discount_amount', 'tax_amount', 'tcs_section', 'tcs_rate', 'tcs_amount', 'round_off', 'total',
        'notes', 'created_by', 'delivered_at',
        'fulfillment_stage', 'picked_at', 'packed_at', 'shipped_at', 'picked_by', 'packed_by', 'shipped_by',
        'approved_by', 'approved_at', 'rejected_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivered_at' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'item_discount_total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tcs_rate' => 'decimal:2',
        'tcs_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /** Net taxable value (excl. tax), after per-line and overall discounts. */
    public function taxableValue(): float
    {
        return round((float) $this->subtotal - (float) $this->item_discount_total - (float) $this->discount_amount, 2);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** All brokers on this deal (multi-broker), with rate/share overrides. */
    public function brokers(): HasMany
    {
        return $this->hasMany(SalesOrderBroker::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** A portal-placed order awaiting staff approval. */
    public function isB2b(): bool
    {
        return $this->channel === 'b2b';
    }
}
