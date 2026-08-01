<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reported parameter: the row a doctor actually reads.
 *
 * Everything clinical about it is snapshotted from the test master at the moment
 * the order is registered — unit, printed range, numeric bounds. A lab revises
 * its reference ranges when it changes analyser or kit, and a report signed last
 * March has to keep saying what it said in March. Reading the range through to
 * the master would quietly re-interpret old reports every time it changed.
 *
 * The value is text and the number is derived from it, not the other way round:
 * "Negative", "Trace" and "<0.01" are all legitimate results that no decimal
 * column can hold.
 *
 * Audited, unlike the order line beside it, because amending a released value is
 * the one edit here anyone would ever want to prove.
 */
class LabOrderResult extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'lab_order_id', 'lab_order_item_id', 'lab_test_id',
        'parameter', 'unit', 'reference_range', 'ref_low', 'ref_high',
        'result_value', 'numeric_value', 'flag', 'remarks', 'sort_order',
    ];

    protected $casts = [
        'ref_low' => 'decimal:4',
        'ref_high' => 'decimal:4',
        'numeric_value' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabOrderItem::class, 'lab_order_item_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }

    /**
     * The numeric part of a reported value, or null when there isn't one.
     *
     * Tolerates the shapes results are genuinely written in: "12.4 mg/dL",
     * "<0.01", "> 200". A leading comparator is dropped and the magnitude kept —
     * "<0.01" is below any lower bound worth flagging on, so treating it as 0.01
     * gives the right answer for the wrong-looking reason; anything with no
     * digits at all stays null and is judged as qualitative.
     */
    public static function parseNumeric(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/-?\d+(\.\d+)?/', $value, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    /** Is this outside the range it was measured against? */
    public function isAbnormal(): bool
    {
        return in_array($this->flag, ['low', 'high', 'abnormal'], true);
    }
}
