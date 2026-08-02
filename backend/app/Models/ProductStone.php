<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stone — or one matched set of them — set into an ornament.
 *
 * Lines, not a lump sum, because a studded piece is quoted as a build-up and
 * because the four Cs and the certificate belong to a *stone*, not to the ring
 * it happens to sit in. The product's `stone_value` is the total of these and
 * is kept in step by {@see Product::syncStoneTotals()}.
 */
class ProductStone extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /**
     * Carats to grams. Fixed by definition (1 ct = 200 mg), which is how the
     * stone weight in grams — the number that must come *off* the gross weight
     * before the metal rate is applied — is derived rather than typed twice.
     */
    public const GRAMS_PER_CARAT = 0.2;

    /** Only a diamond is graded on all four Cs; the rest are weight and eye. */
    public const GRADED_TYPES = ['diamond'];

    protected $fillable = [
        'product_id', 'stone_type', 'shape', 'pieces', 'carat_weight',
        'cut_grade', 'colour_grade', 'clarity_grade',
        'rate_type', 'rate', 'value',
        'certificate_number', 'certifying_lab', 'certificate_date',
        'setting_type', 'notes',
    ];

    protected $casts = [
        'pieces' => 'integer',
        'carat_weight' => 'decimal:3',
        'rate' => 'decimal:2',
        'value' => 'decimal:2',
        'certificate_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Whether the four-C grades on this line mean anything. */
    public function isGraded(): bool
    {
        return in_array($this->stone_type, self::GRADED_TYPES, true);
    }

    /** Weight of this line in grams — what it contributes to the gross weight. */
    public function gramWeight(): float
    {
        return round((float) $this->carat_weight * self::GRAMS_PER_CARAT, 3);
    }

    /**
     * What the line is worth from its own rate.
     *
     * Kept separate from the stored `value` so a shop can overrule it: a parcel
     * bought as a job lot has a price and no arithmetic, and forcing the sum
     * would quietly restate what the piece actually cost.
     */
    public function computedValue(): float
    {
        $rate = (float) $this->rate;

        return round(match ($this->rate_type) {
            'per_carat' => (float) $this->carat_weight * $rate,
            'per_piece' => (int) $this->pieces * $rate,
            default => $rate,
        }, 2);
    }

    /**
     * How the line reads on a bill or a certificate.
     *
     * "2 × Diamond Round Brilliant 0.75 ct (F / VS1 / Excellent)" — the whole
     * point of the register is that this sentence can be printed, so it is built
     * once here rather than in every template that needs it.
     *
     * Grades in the order the trade says them — colour, then clarity, then cut.
     */
    public function label(): string
    {
        $parts = [ucfirst(str_replace('_', ' ', (string) $this->stone_type))];

        if ($this->shape) {
            $parts[] = $this->shape;
        }

        $line = ((int) $this->pieces > 1 ? $this->pieces.' × ' : '').implode(' ', $parts);

        if ((float) $this->carat_weight > 0) {
            $line .= ' '.rtrim(rtrim(number_format((float) $this->carat_weight, 3), '0'), '.').' ct';
        }

        $grades = array_filter([$this->colour_grade, $this->clarity_grade, $this->cut_grade]);

        return $grades ? $line.' ('.implode(' / ', $grades).')' : $line;
    }

    /** The line as it is snapshotted onto a sale — printable without the master. */
    public function toSnapshot(): array
    {
        return [
            'label' => $this->label(),
            'stone_type' => $this->stone_type,
            'shape' => $this->shape,
            'pieces' => (int) $this->pieces,
            'carat_weight' => (float) $this->carat_weight,
            'cut_grade' => $this->cut_grade,
            'colour_grade' => $this->colour_grade,
            'clarity_grade' => $this->clarity_grade,
            'certificate_number' => $this->certificate_number,
            'certifying_lab' => $this->certifying_lab,
            'value' => (float) $this->value,
        ];
    }
}
