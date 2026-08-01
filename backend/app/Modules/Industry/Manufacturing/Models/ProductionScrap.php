<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material or output that will never be sold, and what it cost.
 *
 * Its own document rather than a stock adjustment on purpose. An adjustment says
 * "the count was wrong"; scrap says "we made it and lost it". A factory that
 * cannot tell those apart cannot tell a counting problem from a process one —
 * and it is the second that costs it money every shift.
 *
 * `scrap_type` carries the one distinction that cannot be inferred later:
 *
 *   material — raw stock that was issued and ruined. It *is* in the warehouse,
 *              so scrapping it must take it out.
 *   finished — output rejected on the line. It was never received into stock
 *              (a manufacturing order only receives what it declares produced),
 *              so posting it out would drive the balance negative for goods that
 *              never arrived.
 *
 * `recoverable_value` exists because regrind, cut-offs, turnings and paper trim
 * are sold by the kilo in most of these trades. Reporting only the gross figure
 * overstates the loss.
 */
class ProductionScrap extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'scrap_number_prefix';

    /** @var array<string, string> */
    public const REASONS = [
        'rejection' => 'QC Rejection',
        'setup_waste' => 'Setup / Make-ready Waste',
        'machine_defect' => 'Machine Defect',
        'material_defect' => 'Material Defect',
        'operator_error' => 'Operator Error',
        'trial_run' => 'Trial Run',
        'expiry' => 'Expired',
        'other' => 'Other',
    ];

    public const TYPES = ['material', 'finished'];

    protected $fillable = [
        'scrap_number', 'scrap_date', 'manufacturing_order_id', 'quality_inspection_id',
        'product_id', 'variant_id', 'warehouse_id', 'quantity',
        'scrap_type', 'reason', 'unit_cost', 'total_value', 'recoverable_value',
        'affects_stock', 'is_posted', 'posted_at', 'remarks', 'created_by',
    ];

    protected $casts = [
        'scrap_date' => 'date',
        'posted_at' => 'datetime',
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_value' => 'decimal:2',
        'recoverable_value' => 'decimal:2',
        'affects_stock' => 'boolean',
        'is_posted' => 'boolean',
    ];

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class, 'quality_inspection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the shop is actually out of pocket, after selling what it can. */
    public function netLoss(): float
    {
        return round((float) $this->total_value - (float) $this->recoverable_value, 2);
    }

    /**
     * Whether this row should move stock. Finished-good rejects never do (see
     * the class note); material scrap does unless the user says otherwise —
     * a shop recording last month's scrap for the record, after the stock was
     * already written off by a count, needs to be able to say so.
     */
    public function shouldMoveStock(): bool
    {
        return $this->scrap_type === 'material' && (bool) $this->affects_stock;
    }
}
