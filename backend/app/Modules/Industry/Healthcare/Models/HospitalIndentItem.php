<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Batch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on a ward indent, through its whole life: asked for, allowed, issued.
 *
 * Three quantities rather than one because they are three different facts and a
 * store is judged on the distance between them. `issued_quantity` is cumulative,
 * so a line released over two days stays one line.
 */
class HospitalIndentItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'hospital_indent_id', 'product_id', 'variant_id', 'batch_id',
        'requested_quantity', 'approved_quantity', 'issued_quantity',
        'unit_cost', 'issue_value', 'remarks',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:3',
        'approved_quantity' => 'decimal:3',
        'issued_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'issue_value' => 'decimal:2',
    ];

    public function indent(): BelongsTo
    {
        return $this->belongsTo(HospitalIndent::class, 'hospital_indent_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * What the store agreed to release. Falls back to the request so a workspace
     * that approves without trimming quantities never has to restate them.
     */
    public function allowedQuantity(): float
    {
        return (float) ($this->approved_quantity ?? $this->requested_quantity);
    }

    /** Still owed to the ward. */
    public function pendingQuantity(): float
    {
        return max(0.0, round($this->allowedQuantity() - (float) $this->issued_quantity, 3));
    }

    public function isFullyIssued(): bool
    {
        return $this->pendingQuantity() <= 0;
    }
}
