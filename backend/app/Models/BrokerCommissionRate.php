<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-broker commission rate override keyed by a single product or a single
 * customer. Used by BrokerCommissionService when accruing: a product-scoped
 * rate wins over a customer-scoped rate, which wins over the broker default.
 */
class BrokerCommissionRate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'broker_id', 'scope', 'product_id', 'customer_id', 'commission_percent',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
