<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An open ticket: everything one table (or one takeaway bag) has ordered.
 *
 * Unlike a counter sale this lives for the length of a meal. Items are added in
 * rounds, each round is fired to the kitchen as a KOT, and the whole thing is
 * settled once at the end — at which point it leaves through the ordinary sales
 * spine like every other document in this system.
 */
class RestaurantOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'restaurant_order_number_prefix';

    protected $fillable = [
        'order_number', 'order_type', 'restaurant_table_id', 'customer_id', 'waiter_id',
        'status', 'guest_count',
        // Which membership card discounted this bill, if one did (F&B plugin).
        'customer_membership_id',
        // Counter queue (F&B plugin): a QSR has no tables, it has token numbers.
        'token_number', 'token_status', 'token_called_at', 'token_collected_at', 'token_date',
        'subtotal', 'item_discount_total', 'discount_type', 'discount_value', 'discount_amount',
        'service_charge_percent', 'service_charge', 'tax_amount', 'round_off', 'total',
        // Parcel / delivery (Food & Beverage plugin): flat charges the customer
        // sees on the bill, plus where the food is going and who is taking it.
        'packaging_charge', 'delivery_charge', 'delivery_address', 'delivery_phone',
        'rider_id', 'dispatched_at', 'delivered_at',
        'sales_order_id', 'invoice_id', 'opened_at', 'settled_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'guest_count' => 'integer',
        'subtotal' => 'decimal:2',
        'item_discount_total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'service_charge_percent' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'packaging_charge' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
        'opened_at' => 'datetime',
        'settled_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'token_number' => 'integer',
        'token_date' => 'date',
        'token_called_at' => 'datetime',
        'token_collected_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Who is carrying a delivery order out. */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'waiter_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenOrderTicket::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Lines that count towards the bill: everything except voided ones.
     *
     * A void is deliberately kept on the ticket rather than deleted — the food
     * was cooked and somebody has to answer for it — so every money calculation
     * has to exclude it explicitly.
     */
    public function billableItems()
    {
        return $this->items()->where('status', '!=', 'void');
    }

    /** Anything keyed in but not yet sent to the kitchen. */
    public function pendingItems()
    {
        return $this->items()->where('status', 'pending');
    }
}
