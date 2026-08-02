<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ServiceContract;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A support call — someone rang, and something is not working.
 *
 * A call is not a job card, and the difference is the whole reason this table
 * exists. Most calls are answered on the phone, talked through, or fixed by
 * remote desktop, and never become bench work at all; a shop that opened a job
 * card per complaint would have a worksheet full of machines that never arrived
 * and a job book that could not be trusted. So a call is logged, worked and
 * closed here, and *converting* it into a service job is a deliberate act taken
 * only when the machine is actually coming in (see ServiceCallConversionService).
 *
 * `attended` is a separate state from `resolved` on purpose. An engineer who has
 * been to site, diagnosed a dead PSU and ordered one has attended the call and
 * not resolved it. Collapsing the two is how a support desk loses sight of the
 * calls that are genuinely waiting on something.
 */
class ServiceCall extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'computer_it_service_calls';

    protected static string $numberSettingKey = 'it_service_call_number_prefix';

    /** Statuses that still count as work in hand. */
    public const OPEN_STATUSES = ['logged', 'scheduled', 'assigned', 'attended'];

    /** Statuses a call may still be turned into bench work from. */
    public const CONVERTIBLE_STATUSES = ['logged', 'scheduled', 'assigned', 'attended'];

    /**
     * The response promise, in hours, per priority.
     *
     * Deliberately hard-coded rather than configurable: a per-tenant SLA matrix
     * is a support-contract feature and belongs with the contract, not with the
     * call. These are the default trade expectations, and the stored
     * `sla_due_at` may be overridden per call by whoever logs it.
     */
    public const SLA_HOURS = [
        'critical' => 4,
        'high' => 8,
        'normal' => 24,
        'low' => 48,
    ];

    protected $fillable = [
        'call_number', 'customer_id', 'asset_id', 'contract_id', 'warranty_id',
        'type', 'source', 'priority', 'status',
        'logged_at', 'sla_due_at', 'scheduled_at', 'attended_at', 'closed_at',
        'assigned_to', 'site_address', 'contact_person', 'contact_phone',
        'complaint', 'resolution', 'is_chargeable', 'service_job_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'attended_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_chargeable' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so a call created through the API reads
     * the same in memory as it does after a reload — the conversion service
     * checks `status` against CONVERTIBLE_STATUSES on a row it may have just
     * made, and a null there would refuse a perfectly good call.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'onsite',
        'source' => 'phone',
        'priority' => 'normal',
        'status' => 'logged',
        'is_chargeable' => true,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'asset_id');
    }

    /** The AMC being claimed under — a core service contract. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'contract_id');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(AssetWarranty::class, 'warranty_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    /** The bench work this call turned into, once it did. */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The response promise for a priority, in hours. */
    public static function slaHours(string $priority): int
    {
        return self::SLA_HOURS[$priority] ?? self::SLA_HOURS['normal'];
    }

    /** When a call logged now at this priority is promised a response by. */
    public static function slaDueFrom(string $priority, ?Carbon $loggedAt = null): Carbon
    {
        return ($loggedAt ?? now())->copy()->addHours(self::slaHours($priority));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Has the response promise been missed?
     *
     * Measured against `attended_at`, not against closure: the promise a support
     * desk makes is that somebody will *get there*, not that the fault will be
     * gone — a dead motherboard takes as long as the part takes, and counting
     * that against the desk would make the number meaningless.
     */
    public function isSlaBreached(?Carbon $on = null): bool
    {
        if (! $this->sla_due_at) {
            return false;
        }

        $reference = $this->attended_at ?? ($on ?? now());

        return $reference->gt($this->sla_due_at);
    }

    /** Minutes left on the response promise; negative once it has been missed. */
    public function slaMinutesRemaining(?Carbon $on = null): ?int
    {
        if (! $this->sla_due_at || $this->attended_at) {
            return null;
        }

        $on ??= now();

        return (int) round($on->diffInMinutes($this->sla_due_at, false));
    }
}
