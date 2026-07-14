<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One broker attached to a sales order, with optional rate overrides and the
 * share_percent of the deal they earn on. Multiple rows = "multi broker per
 * invoice"; these are copied onto the invoice when the order is delivered.
 */
class SalesOrderBroker extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sales_order_id', 'broker_id', 'commission_percent', 'fixed_commission', 'share_percent',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'fixed_commission' => 'decimal:2',
        'share_percent' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}
