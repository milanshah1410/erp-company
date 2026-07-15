<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketplace order pulled from an e-commerce channel. Sits in an inbox until
 * it is imported into the sales pipeline (creating a SalesOrder on the
 * 'ecommerce' channel) or ignored.
 */
class ChannelOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'channel_integration_id', 'external_order_id', 'external_order_number',
        'order_status', 'financial_status', 'customer_name', 'customer_email', 'customer_phone',
        'shipping_address', 'total', 'currency', 'placed_at', 'items', 'raw',
        'import_status', 'sales_order_id', 'import_error',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'items' => 'array',
        'raw' => 'array',
        'total' => 'decimal:2',
        'placed_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ChannelIntegration::class, 'channel_integration_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
