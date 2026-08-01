<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One test or panel on an order — the billable line.
 *
 * Its `status` tracks the sample rather than the money: a single order can have
 * blood collected and running while the urine sample is still awaited, and the
 * collection worklist is built from exactly that.
 */
class LabOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'lab_order_id', 'lab_test_id', 'test_code', 'test_name', 'sample_type',
        'unit_price', 'discount_type', 'discount_value', 'discount_amount',
        'tax_percent', 'tax_amount', 'line_total',
        'status', 'collected_at', 'completed_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'collected_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }

    /** The parameters reported under this line (a panel's components, or itself). */
    public function results(): HasMany
    {
        return $this->hasMany(LabOrderResult::class);
    }
}
