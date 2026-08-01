<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ServiceJob;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service appointment — a slot promised to a vehicle.
 *
 * A booking is not a job card, and the distinction is the whole reason this
 * table exists. Most bookings never become work: people ring to ask, reschedule
 * twice, and half of them never turn up. A workshop that opened a job card per
 * phone call would have a worksheet full of vehicles that never arrived, and no
 * way to answer "how many slots did we actually fill this week?".
 *
 * So the two are separate rows and converting is an explicit act
 * (BookingConversionService). Afterwards they stay linked in both directions —
 * the booking keeps `service_job_id`, the job card keeps `booking_id` — so
 * either screen reaches the other without a third table in between.
 */
class VehicleBooking extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'automobile_bookings';

    protected static string $numberSettingKey = 'vehicle_booking_number_prefix';

    /** Statuses that no longer expect the vehicle to arrive. */
    public const CLOSED_STATUSES = ['converted', 'no_show', 'cancelled'];

    protected $fillable = [
        'booking_number', 'vehicle_id', 'customer_id',
        'service_type', 'source', 'status',
        'scheduled_at', 'estimated_ready_at', 'assigned_to', 'warehouse_id',
        'odometer', 'complaint', 'estimated_cost',
        'pickup_required', 'pickup_address',
        'service_job_id', 'reminder_sent_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'estimated_ready_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'odometer' => 'integer',
        'estimated_cost' => 'decimal:2',
        'pickup_required' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults.
     *
     * These are not decoration. A row created without an explicit status gets
     * 'booked' in the database, but the *instance* in memory keeps a null one —
     * and isClosed() and the conversion service both read the instance. Without
     * these, converting a booking straight after creating it refuses it as
     * though it were already spent.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'booked',
        'service_type' => 'general',
        'source' => 'phone',
        'estimated_cost' => 0,
        'pickup_required' => false,
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class, 'vehicle_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The service advisor who owns the slot. */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Is the slot settled one way or the other? */
    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Promised for a time that has passed, with the vehicle still not here.
     *
     * `arrived` counts as still open — the vehicle is on the premises but no job
     * card exists yet, which is exactly the state a service manager wants shown
     * in red rather than quietly filed as handled.
     */
    public function isOverdue(): bool
    {
        return ! $this->isClosed() && $this->scheduled_at?->isPast();
    }
}
