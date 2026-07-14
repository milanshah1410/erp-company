<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One broker attached to an invoice, with optional rate overrides and the
 * share_percent of the deal they earn on. Copied from the sales order at
 * delivery (see BrokerCommissionService::copyBrokersToInvoice).
 */
class InvoiceBroker extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'broker_id', 'commission_percent', 'fixed_commission', 'share_percent',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'fixed_commission' => 'decimal:2',
        'share_percent' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}
