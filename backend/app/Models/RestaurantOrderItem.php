<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dish on a ticket.
 *
 * `status` is what decides whether the line can still be changed. Up to
 * `pending` it is just something a waiter keyed in; once it is `sent` the
 * kitchen has it, and from then on the only way off the bill is a void with a
 * reason — never a delete.
 */
class RestaurantOrderItem extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'restaurant_order_id', 'kitchen_order_ticket_id', 'product_id', 'variant_id',
        'quantity', 'unit_price', 'discount_type', 'discount_value', 'discount_amount',
        // Which happy hour priced this line, if one did. Both the audit trail and
        // the flag that tells an offer discount from a manager's manual one —
        // MenuOfferService re-evaluates the former and never touches the latter.
        'menu_happy_hour_id',
        'tax_percent', 'tax_amount', 'line_total',
        'status', 'void_reason', 'instructions', 'course', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'course' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'restaurant_order_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenOrderTicket::class, 'kitchen_order_ticket_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Has the kitchen seen this line? */
    public function isFired(): bool
    {
        return in_array($this->status, ['sent', 'served'], true);
    }

    /** Can it still be edited or removed outright? */
    public function isEditable(): bool
    {
        return $this->status === 'pending';
    }
}
