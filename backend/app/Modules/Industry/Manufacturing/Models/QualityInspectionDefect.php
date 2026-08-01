<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reason a lot was marked down, with how many pieces it accounted for.
 *
 * This is the only actionable part of an inspection. A rejection percentage
 * tells a factory it has a problem; a count by defect tells it where — and the
 * top two or three lines are almost always most of the loss.
 *
 * The defect itself is free text rather than a fixed list: "short shot", "broken
 * stitch", "shade variation" and "under weight" belong to four different trades,
 * and a shared enum would be wrong for all of them.
 */
class QualityInspectionDefect extends Model
{
    use BelongsToTenant;

    /** Severity drives what happens next, which is why a count alone is not enough. */
    public const SEVERITIES = ['minor', 'major', 'critical'];

    protected $fillable = [
        'quality_inspection_id', 'defect', 'quantity', 'severity', 'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class, 'quality_inspection_id');
    }
}
