<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One age band on the shop's own scale — "18m–3y", "3+", "8–12", "14+".
 *
 * A catalogue and not an enum because the bands are a merchandising decision:
 * they follow how the shop lays out its shelves and how its suppliers grade
 * their ranges, and no two toy shops divide the same range the same way.
 *
 * Everything is held in months. Years are the unit the sign above the shelf
 * uses, but the decisions are made in months at the bottom of the range — the
 * whole under-three rule turns on 36 of them — and a scale that stores years
 * cannot express "18 months to 3 years" at all.
 */
class AgeGrade extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'toy_age_grades';

    protected $fillable = [
        'code', 'label', 'min_months', 'max_months',
        'warning', 'requires_supervision', 'description',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'min_months' => 'integer',
        'max_months' => 'integer',
        'requires_supervision' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'min_months' => 0,
        'requires_supervision' => false,
        'is_active' => true,
    ];

    /**
     * Articles graded into this band.
     *
     * Declared here rather than on Product so the dependency points plugin →
     * core and never back; the reverse relation is attached at boot by
     * ToysPlugin.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'toy_age_grade_id');
    }

    /** "18 months – 3 years" / "3 years and over", for a label or a picker. */
    public function range(): string
    {
        $from = self::describeMonths($this->min_months ?? 0);

        if ($this->max_months === null) {
            return ($this->min_months ?? 0) > 0 ? "{$from} and over" : 'All ages';
        }

        return $from.' – '.self::describeMonths($this->max_months);
    }

    /** Is a child of this many months old inside the band? */
    public function suits(int $months): bool
    {
        return $months >= (int) ($this->min_months ?? 0)
            && ($this->max_months === null || $months <= (int) $this->max_months);
    }

    /**
     * Is this band below the floor a safety classification imposes?
     *
     * The one check worth making automatically. A shop that grades a toy with
     * small parts as suitable from eighteen months has not made a typing
     * mistake — it has mislabelled something that a toddler will put in their
     * mouth — and neither field on its own can catch it.
     */
    public function conflictsWithFloor(?int $floorMonths): bool
    {
        return $floorMonths !== null && (int) ($this->min_months ?? 0) < $floorMonths;
    }

    /** 18 → "18 months"; 36 → "3 years"; 30 → "2 years 6 months". */
    public static function describeMonths(int $months): string
    {
        if ($months < 24) {
            return $months.' '.($months === 1 ? 'month' : 'months');
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;
        $text = $years.' '.($years === 1 ? 'year' : 'years');

        return $rest > 0 ? $text.' '.$rest.' '.($rest === 1 ? 'month' : 'months') : $text;
    }
}
