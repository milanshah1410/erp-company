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

    protected $fillable = [
        'mo_number', 'product_id', 'variant_id', 'warehouse_id',
        'quantity_to_produce', 'quantity_produced', 'status',
        'order_date', 'expected_completion_date',
        'material_cost', 'stage_cost', 'total_cost',
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
     * Recompute cost roll-ups from the current material and stage rows and
     * persist them. Material cost = sum of line totals; stage cost = sum of
     * per-stage costs; total = both.
     */
    public function recalcCosts(): void
    {
        $materialCost = round((float) $this->materials()->sum('total_cost'), 2);
        $stageCost = round((float) $this->stages()->sum('cost'), 2);

        $this->update([
            'material_cost' => $materialCost,
            'stage_cost' => $stageCost,
            'total_cost' => round($materialCost + $stageCost, 2),
        ]);
    }

    /** Cost booked into each produced unit of finished stock. */
    public function unitProductionCost(): float
    {
        $qty = (float) $this->quantity_produced ?: (float) $this->quantity_to_produce;

        return $qty > 0 ? round((float) $this->total_cost / $qty, 4) : 0.0;
    }
}
