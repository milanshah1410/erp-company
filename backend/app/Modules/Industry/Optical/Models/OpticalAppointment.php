<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EyePrescription;
use App\Models\OpticalOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A slot in the optician's diary.
 *
 * An appointment is not an order and most of them never become one — people
 * ring to ask, reschedule twice, and a good number simply do not turn up. A
 * shop that raised a spectacle order per enquiry would have a workshop list
 * full of pairs nobody asked for, and no way to answer "how many chairs did we
 * actually fill this week?". So this is its own row, and what came of it — a
 * prescription, or a pair being collected — is a link written when it happens.
 *
 * The distinction between purposes is load-bearing rather than descriptive. An
 * eye test occupies the optometrist for twenty minutes; handing over a finished
 * pair occupies the counter for two. Only the first kind can clash, which is
 * what AppointmentBook defends.
 */
class OpticalAppointment extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'optical_appointments';

    protected static string $numberSettingKey = 'optical_appointment_number_prefix';

    /** Purposes that need the optometrist's chair, and therefore cannot overlap. */
    public const CLINICAL_PURPOSES = ['eye_test', 'follow_up'];

    /** Statuses that no longer expect anybody to walk in. */
    public const CLOSED_STATUSES = ['completed', 'no_show', 'cancelled'];

    /** Statuses a slot may be moved to, from where. */
    public const TRANSITIONS = [
        'booked' => ['confirmed', 'arrived', 'completed', 'no_show', 'cancelled'],
        'confirmed' => ['arrived', 'completed', 'no_show', 'cancelled'],
        // Someone standing in the shop can still be sent away unseen, but they
        // cannot become a no-show — they showed.
        'arrived' => ['completed', 'cancelled'],
        'completed' => [],
        'no_show' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'appointment_number', 'customer_id', 'patient_name', 'patient_phone', 'patient_age',
        'purpose', 'status', 'scheduled_at', 'duration_minutes',
        'optometrist_id', 'warehouse_id', 'eye_prescription_id', 'optical_order_id',
        'reminder_sent_at', 'arrived_at', 'completed_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'patient_age' => 'integer',
        'reminder_sent_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Mirrors of the column defaults.
     *
     * A row created without an explicit status gets 'booked' in the database but
     * a null one in memory, and canMoveTo() reads the instance — without these,
     * marking someone arrived immediately after booking them refuses it as
     * though the slot were already spent. The same trap VehicleBooking documents.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'booked',
        'purpose' => 'eye_test',
        'duration_minutes' => 20,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function optometrist(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'optometrist_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(EyePrescription::class, 'eye_prescription_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OpticalOrder::class, 'optical_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Slots still expecting somebody. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /** One day's diary, in the order it will be worked. */
    public function scopeOnDay(Builder $query, Carbon|string $day): Builder
    {
        $date = $day instanceof Carbon ? $day->copy() : Carbon::parse($day);

        return $query
            ->whereBetween('scheduled_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('scheduled_at');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** Does this slot occupy the optometrist rather than the counter? */
    public function isClinical(): bool
    {
        return in_array($this->purpose, self::CLINICAL_PURPOSES, true);
    }

    /** When the chair frees up again. */
    public function endsAt(): ?Carbon
    {
        return $this->scheduled_at?->copy()->addMinutes(max(1, (int) $this->duration_minutes));
    }

    /**
     * Booked for a time that has passed with nobody marked in.
     *
     * `arrived` deliberately does not count: the patient is on the premises and
     * waiting, which a shop wants shown as a queue rather than quietly filed as
     * a slot that went wrong.
     */
    public function isOverdue(): bool
    {
        return ! $this->isClosed()
            && $this->status !== 'arrived'
            && (bool) $this->scheduled_at?->isPast();
    }

    /** May the slot move to this status? */
    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
