<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FabricInspectionDefect extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fabric_inspection_id', 'defect_type', 'severity', 'size_range', 'points', 'count',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'count' => 'integer',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FabricInspection::class, 'fabric_inspection_id');
    }

    /** Penalty points contributed by this defect line: points × count. */
    public function getLinePointsAttribute(): float
    {
        return round((float) $this->points * (int) $this->count, 2);
    }
}
