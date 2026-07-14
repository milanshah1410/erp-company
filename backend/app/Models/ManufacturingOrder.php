<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManufacturingOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'mo_number_prefix';

    /**
     * The fixed production pipeline (stage_key => display name), in order.
     * A new order seeds all of these; the user removes the ones that don't
     * apply. The frontend mirrors this list in constants/manufacturingStages.
     */
    public const STAGES = [
        'order_received' => 'Order Received',
        'cutting' => 'Cutting',
        'darji' => 'Darji (Tailor)',
        'embroidery' => 'Embroidery',
        'colour_printing' => 'Colour Printing',
        'screen_printing' => 'Screen Printing',
        'heat_transfer' => 'Heat Transfer',
        'quality_check' => 'Quality Check',
        'packing' => 'Packing',
        'ready_stock' => 'Ready Stock',
    ];

    /**
     * The production-cost buckets that make up the finished-product cost,
     * besides raw material. Raw material is auto-derived from the bill of
     * materials (material_cost); these are entered per order. Keyed by the
     * column that stores each bucket's order-level total.
     */
    public const COST_BUCKETS = [
        'tailor_cost' => 'Tailor Cost',
        'printing_cost' => 'Printing Cost',
        'embroidery_cost' => 'Embroidery Cost',
        'packing_cost' => 'Packing Cost',
        'transport_cost' => 'Transport',
        'other_cost' => 'Other Expenses',
    ];

    protected $fillable = [
        'mo_number', 'product_id', 'variant_id', 'bom_id', 'warehouse_id',
        'quantity_to_produce', 'quantity_produced', 'status',
        'order_date', 'expected_completion_date',
        'material_cost', 'tailor_cost', 'printing_cost', 'embroidery_cost',
        'packing_cost', 'transport_cost', 'other_cost', 'stage_cost', 'total_cost',
        'materials_consumed', 'finished_received',
        'started_at', 'completed_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_completion_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'quantity_to_produce' => 'decimal:3',
        'quantity_produced' => 'decimal:3',
        'material_cost' => 'decimal:2',
        'tailor_cost' => 'decimal:2',
        'printing_cost' => 'decimal:2',
        'embroidery_cost' => 'decimal:2',
        'packing_cost' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
        'stage_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'materials_consumed' => 'boolean',
        'finished_received' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ManufacturingOrderMaterial::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ManufacturingOrderStage::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Recompute the finished-product cost and persist it. Raw material is
     * re-derived from the material lines (material_cost); the other buckets
     * are entered on the order and taken as-is. Total = raw material + every
     * bucket. Stage costs are workflow-only and no longer roll up here.
     */
    public function recalcCosts(): void
    {
        $materialCost = round((float) $this->materials()->sum('total_cost'), 2);
        $bucketCost = 0.0;
        foreach (array_keys(self::COST_BUCKETS) as $column) {
            $bucketCost += (float) $this->{$column};
        }

        $this->update([
            'material_cost' => $materialCost,
            'total_cost' => round($materialCost + $bucketCost, 2),
        ]);
    }

    /** The qty the per-unit cost is spread over (produced once known, else target). */
    public function costingQuantity(): float
    {
        return (float) $this->quantity_produced ?: (float) $this->quantity_to_produce;
    }

    /** Cost booked into each produced unit of finished stock. */
    public function unitProductionCost(): float
    {
        $qty = $this->costingQuantity();

        return $qty > 0 ? round((float) $this->total_cost / $qty, 4) : 0.0;
    }

    /**
     * The full costing breakdown: raw material + each named bucket, with both
     * order totals and per-unit figures, ending in the finished-product cost.
     */
    public function costingBreakdown(): array
    {
        $qty = $this->costingQuantity();
        $perUnit = fn (float $v) => $qty > 0 ? round($v / $qty, 4) : 0.0;

        $lines = ['raw_material' => (float) $this->material_cost];
        foreach (array_keys(self::COST_BUCKETS) as $column) {
            $lines[$column] = (float) $this->{$column};
        }

        $total = (float) $this->total_cost;

        return [
            'quantity' => $qty,
            'lines' => array_map(fn ($v) => [
                'total' => round($v, 2),
                'per_unit' => $perUnit($v),
            ], $lines),
            'total' => round($total, 2),
            'per_unit_total' => $perUnit($total),
        ];
    }
}
