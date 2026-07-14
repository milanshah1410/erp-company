<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single consignment on a dispatch plan — one customer drop, optionally
 * linked to a sales order. Carries its own Proof-of-Delivery fields recorded
 * when the drop is completed.
 */
class DispatchItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'dispatch_plan_id', 'customer_id', 'sales_order_id', 'delivery_address',
        'packages', 'weight_kg', 'cod_amount', 'sequence',
        'delivery_status', 'delivered_at', 'received_by', 'receiver_phone',
        'pod_photo_path', 'pod_remarks',
    ];

    protected $casts = [
        'packages' => 'integer',
        'weight_kg' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'sequence' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function dispatchPlan(): BelongsTo
    {
        return $this->belongsTo(DispatchPlan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
