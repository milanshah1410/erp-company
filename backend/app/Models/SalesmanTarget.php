<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesmanTarget extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'salesman_id', 'period_year', 'period_month',
        'target_amount', 'target_orders', 'incentive_percent', 'notes',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'target_amount' => 'decimal:2',
        'target_orders' => 'integer',
        'incentive_percent' => 'decimal:2',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /** The effective incentive rate: this target's override, else the salesman default. */
    public function effectiveIncentivePercent(): float
    {
        return (float) ($this->incentive_percent ?? $this->salesman?->default_incentive_percent ?? 0);
    }
}
