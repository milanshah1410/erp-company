<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spare part (or service line) on a repair job.
 *
 * `is_issued` tracks whether this line has actually left the warehouse. A
 * technician plans parts up front but fits them over the course of a repair, so
 * issuing is per line and re-issuing is refused rather than double-posted.
 */
class ServiceJobItem extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'service_job_id', 'product_id', 'variant_id', 'quantity', 'unit_price',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_percent', 'tax_amount', 'line_total', 'is_issued',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'is_issued' => 'boolean',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
