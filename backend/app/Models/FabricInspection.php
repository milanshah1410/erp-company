<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A 4-point-system inspection of a fabric roll. Defect occurrences accumulate
 * penalty points; the score per 100 m² determines the fabric grade and a
 * pass / reject result.
 */
class FabricInspection extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'fabric_inspection_number_prefix';

    protected $fillable = [
        'inspection_number', 'fabric_roll_id', 'inspected_length', 'inspected_width',
        'total_points', 'points_per_100sqm', 'grade', 'result',
        'inspector', 'inspection_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'inspected_length' => 'decimal:3',
        'inspected_width' => 'decimal:2',
        'total_points' => 'decimal:2',
        'points_per_100sqm' => 'decimal:2',
        'inspection_date' => 'date',
    ];

    public function fabricRoll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(FabricInspectionDefect::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 4-point score: total penalty points normalised to 100 square metres of
     * inspected fabric. Width is converted to metres from its unit so the
     * area denominator is in m². Returns 0 when the inspected area is unknown.
     */
    public static function scorePer100Sqm(float $totalPoints, float $lengthMeters, ?float $width, string $widthUnit): float
    {
        $widthMeters = match ($widthUnit) {
            'inch' => (float) $width * 0.0254,
            'cm' => (float) $width / 100,
            default => (float) $width,
        };

        $areaSqm = $lengthMeters * $widthMeters;
        if ($areaSqm <= 0) {
            return 0;
        }

        return round(($totalPoints / $areaSqm) * 100, 2);
    }

    /** Conventional 4-point grading: ≤20 → A, ≤40 → B, otherwise C. */
    public static function gradeFor(float $per100Sqm): string
    {
        return match (true) {
            $per100Sqm <= 20 => 'A',
            $per100Sqm <= 40 => 'B',
            default => 'C',
        };
    }
}
