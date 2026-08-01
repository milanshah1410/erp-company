<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Models;

use App\Models\Batch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An accept/reject decision on a lot.
 *
 * One document serves incoming, in-process, final and pre-dispatch checks
 * because the act is identical — offer a lot, sample it, decide — and only the
 * thing in front of the inspector changes. Three tables would have meant three
 * places to fix the same reporting question.
 *
 * The numbers are kept as three separate quantities rather than one "rejected"
 * figure, because they lead to three different places: accepted stock ships,
 * rework goes back to the floor and may still be sold, rejection is a loss that
 * becomes a scrap note. Collapsing rework into rejection would overstate what
 * the factory lost and hide what it spent fixing.
 */
class QualityInspection extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'qc_number_prefix';

    /** Where in the flow the judgement is made. */
    public const STAGES = [
        'incoming' => 'Incoming (material received)',
        'in_process' => 'In-process (on the line)',
        'final' => 'Final (finished output)',
        'pre_dispatch' => 'Pre-dispatch (before it leaves)',
    ];

    /**
     * Outcomes. `conditional` is a real answer, not a hedge: material taken
     * under deviation — off-shade fabric at a discount, a batch released against
     * a customer waiver — is neither a pass nor a rejection, and recording it as
     * either loses the deviation that someone agreed to.
     */
    public const RESULTS = ['pending', 'passed', 'failed', 'conditional'];

    protected $fillable = [
        'inspection_number', 'stage', 'inspected_on',
        'manufacturing_order_id', 'goods_receipt_id',
        'product_id', 'variant_id', 'batch_id',
        'lot_quantity', 'sample_size', 'accepted_quantity', 'rejected_quantity',
        'rework_quantity', 'result', 'inspector_id', 'remarks', 'created_by',
    ];

    protected $casts = [
        'inspected_on' => 'date',
        'lot_quantity' => 'decimal:3',
        'sample_size' => 'decimal:3',
        'accepted_quantity' => 'decimal:3',
        'rejected_quantity' => 'decimal:3',
        'rework_quantity' => 'decimal:3',
    ];

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
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

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'inspector_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(QualityInspectionDefect::class);
    }

    /** Scrap notes raised off the back of this inspection. */
    public function scraps(): HasMany
    {
        return $this->hasMany(ProductionScrap::class);
    }

    /**
     * Rejected as a share of the lot. Null when the lot quantity is unknown —
     * an unknown rejection rate reported as 0% would read as a clean lot.
     */
    public function rejectionPercent(): ?float
    {
        $lot = (float) $this->lot_quantity;

        return $lot > 0 ? round((float) $this->rejected_quantity / $lot * 100, 2) : null;
    }

    /**
     * The result the quantities imply, used when the inspector has not stated
     * one outright.
     *
     * Any rejection at all fails the lot rather than only a majority: a QC
     * document that silently passes a lot with rejects in it is worse than no
     * document, because it is the record someone will rely on later.
     */
    public function impliedResult(): string
    {
        if ((float) $this->rejected_quantity > 0) {
            return 'failed';
        }

        return (float) $this->rework_quantity > 0 ? 'conditional' : 'passed';
    }
}
