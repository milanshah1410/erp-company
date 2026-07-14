<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bill of Materials: the reusable recipe of raw-material components for a
 * finished product. Components are stored per output_quantity of the finished
 * good and scaled on explosion to a target production quantity.
 */
class Bom extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'bom_number_prefix';

    protected $fillable = [
        'bom_number', 'name', 'product_id', 'variant_id', 'output_quantity',
        'is_default', 'is_active', 'material_cost', 'notes', 'created_by',
    ];

    protected $casts = [
        'output_quantity' => 'decimal:3',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'material_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute and persist the snapshot material cost from the component lines. */
    public function recalcCost(): void
    {
        $this->update([
            'material_cost' => round((float) $this->items()->sum('total_cost'), 2),
        ]);
    }

    /**
     * Explode the recipe to a target production quantity: each component's
     * quantity is scaled from the BOM's output_quantity and grossed up by its
     * wastage allowance. Returns material lines shaped for a manufacturing
     * order (product_id, variant_id, quantity, unit_cost, remarks).
     *
     * @return array<int, array<string, mixed>>
     */
    public function explode(float $targetQuantity): array
    {
        $base = (float) $this->output_quantity ?: 1.0;
        $factor = $targetQuantity / $base;

        return $this->items->map(function (BomItem $item) use ($factor) {
            $qty = (float) $item->quantity * $factor;
            $qty *= 1 + ((float) $item->wastage_percent / 100);

            return [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => round($qty, 3),
                'unit_cost' => (float) $item->unit_cost,
                'remarks' => $item->remarks,
            ];
        })->all();
    }
}
