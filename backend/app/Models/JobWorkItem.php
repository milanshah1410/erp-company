<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobWorkItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'job_work_id', 'product_id', 'variant_id',
        'finished_product_id', 'finished_variant_id',
        'issued_qty', 'received_qty', 'damage_qty', 'shortage_qty',
        'unit_cost', 'finished_unit_cost',
    ];

    protected $casts = [
        'issued_qty' => 'decimal:3',
        'received_qty' => 'decimal:3',
        'damage_qty' => 'decimal:3',
        'shortage_qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'finished_unit_cost' => 'decimal:4',
    ];

    /** Quantity still out at the vendor: issued − received − damage − shortage. */
    protected function pendingQty(): Attribute
    {
        return Attribute::get(fn () => round(
            (float) $this->issued_qty
            - (float) $this->received_qty
            - (float) $this->damage_qty
            - (float) $this->shortage_qty,
            3,
        ));
    }

    public function jobWork(): BelongsTo
    {
        return $this->belongsTo(JobWork::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function finishedVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'finished_variant_id');
    }
}
