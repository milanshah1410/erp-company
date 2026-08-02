<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PosCounter;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A basket being filled at an unattended lane.
 *
 * Emphatically not a sale. The cart holds what the customer scanned and the
 * state of whether a human still has to look at it; when it is paid for, the
 * ordinary POS checkout creates the sales order and the invoice, and the cart
 * merely records which ones they were.
 *
 * That split is the trial room's, and it is here for the same reason: two paths
 * that both create sales will price, tax, post stock and award points slightly
 * differently within a release or two, and the one nobody uses daily is the one
 * that rots. So the lane is a way of *building a basket*, and there is exactly
 * one way of turning a basket into money.
 */
class SelfCheckoutCart extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'counter_id', 'cart_number', 'status', 'customer_id', 'warehouse_id',
        'sales_order_id', 'invoice_id', 'approved_by', 'approved_at',
        'completed_at', 'notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'scanning',
    ];

    public function counter(): BelongsTo
    {
        return $this->belongsTo(PosCounter::class, 'counter_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SelfCheckoutCartItem::class, 'cart_id');
    }

    /** The lines that will actually be billed — voided ones are struck out, not deleted. */
    public function liveItems(): HasMany
    {
        return $this->items()->where('is_voided', false);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Still being worked — the customer has not walked away and it is not billed. */
    public function isLive(): bool
    {
        return in_array($this->status, ['scanning', 'awaiting_approval', 'ready'], true);
    }
}
