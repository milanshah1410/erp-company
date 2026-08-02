<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An eye test: the powers a pair of spectacles has to be ground to.
 *
 * Recorded per eye. Sphere and cylinder are signed dioptres, axis is the
 * degrees the cylinder sits at, and the near addition only means anything on a
 * bifocal or progressive pair.
 */
class EyePrescription extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'eye_prescription_number_prefix';

    /** Vision types that carry a near addition. */
    public const ADD_VISION_TYPES = ['bifocal', 'progressive'];

    protected $fillable = [
        'prescription_number', 'customer_id',
        'patient_name', 'patient_age', 'patient_gender', 'patient_phone',
        'optometrist_name', 'optometrist_registration_no', 'vision_type',
        'right_sph', 'right_cyl', 'right_axis', 'right_add',
        'left_sph', 'left_cyl', 'left_axis', 'left_add',
        'pd', 'right_pd', 'left_pd',
        'prescribed_on', 'valid_until', 'notes', 'created_by',
        /**
         * The scan of the paper the customer brought in, added by the Optical &
         * Eyewear plugin. Written only through its attachment endpoint — never
         * through the prescription form — but fillable here so the column is
         * reachable by the ordinary model rather than force-filled around it.
         */
        'attachment_path', 'attachment_name', 'attachment_uploaded_at',
    ];

    protected $casts = [
        'patient_age' => 'integer',
        'right_sph' => 'decimal:2',
        'right_cyl' => 'decimal:2',
        'right_axis' => 'integer',
        'right_add' => 'decimal:2',
        'left_sph' => 'decimal:2',
        'left_cyl' => 'decimal:2',
        'left_axis' => 'integer',
        'left_add' => 'decimal:2',
        'pd' => 'decimal:1',
        'right_pd' => 'decimal:1',
        'left_pd' => 'decimal:1',
        'prescribed_on' => 'date',
        'valid_until' => 'date',
        'attachment_uploaded_at' => 'datetime',
    ];

    /** Is the paper it was copied from on file? */
    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(OpticalOrder::class);
    }

    /** Has the test gone stale? A prescription with no validity never expires. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Does this prescription carry a near addition? */
    public function hasAdd(): bool
    {
        return in_array($this->vision_type, self::ADD_VISION_TYPES, true);
    }

    /**
     * The powers as an optician writes them: "-2.25 / -0.75 x 90".
     *
     * Used on the order slip and the lab docket, where a single readable line
     * is less error-prone than eight separate fields.
     */
    public function formatted(string $eye): string
    {
        $sph = $this->{"{$eye}_sph"};
        $cyl = $this->{"{$eye}_cyl"};
        $axis = $this->{"{$eye}_axis"};
        $add = $this->{"{$eye}_add"};

        if ($sph === null && $cyl === null) {
            return '—';
        }

        $parts = [$this->signed($sph)];

        if ($cyl !== null && (float) $cyl != 0.0) {
            $parts[] = $this->signed($cyl).($axis !== null ? " x {$axis}" : '');
        }

        $line = implode(' / ', $parts);

        return $this->hasAdd() && $add !== null && (float) $add != 0.0
            ? "{$line}  Add {$this->signed($add)}"
            : $line;
    }

    /** Optical powers always carry their sign, including a leading plus. */
    private function signed(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $v = (float) $value;

        return ($v > 0 ? '+' : '').number_format($v, 2, '.', '');
    }
}
