<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered practitioner's prescription — the authority a chemist needs
 * before handing over a Schedule H, H1 or X medicine.
 *
 * Kept per patient rather than per sale because one slip is often dispensed
 * more than once (a repeat), and the register has to show every dispense
 * against the same authority.
 */
class Prescription extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'prescription_number_prefix';

    protected $fillable = [
        'prescription_number', 'customer_id',
        'patient_name', 'patient_age', 'patient_gender', 'patient_phone',
        'doctor_name', 'doctor_registration_no', 'hospital',
        'prescribed_on', 'valid_until', 'notes', 'attachment_path', 'created_by',
    ];

    protected $casts = [
        'patient_age' => 'integer',
        'prescribed_on' => 'date',
        'valid_until' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function dispenses(): HasMany
    {
        return $this->hasMany(PrescriptionDispense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * May this slip still be dispensed against today?
     *
     * A prescription with no `valid_until` is single-use: it authorises one
     * dispensing event and nothing after it. Anything with an explicit date is
     * a repeat, good until that date passes.
     */
    public function isDispensable(): bool
    {
        if ($this->valid_until !== null) {
            return ! $this->valid_until->isPast();
        }

        return ! $this->dispenses()->exists();
    }

    /** Why it cannot be used, for a message the counter can act on. */
    public function blockedReason(): ?string
    {
        if ($this->isDispensable()) {
            return null;
        }

        return $this->valid_until !== null
            ? "Prescription {$this->prescription_number} expired on {$this->valid_until->toDateString()}."
            : "Prescription {$this->prescription_number} has already been dispensed and is not marked as repeatable.";
    }
}
