<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Models;

use App\Models\Asset;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ManufacturingOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine on the shop floor.
 *
 * A factory schedules by machine. "Can we take this order?" is answered by which
 * press is free, not by whether the product exists — so the plant has to be a
 * record with a state, a rate and a history, rather than a line in a note.
 *
 * Deliberately separate from the fixed-asset register even though the same steel
 * appears on both: Accounts asks what it is worth and how it depreciates,
 * Production asks whether it is running and what an hour on it costs. Linking
 * them by `asset_id` keeps one machine with two honest answers; merging them
 * would force a leased machine to be capitalised before it could be loaded.
 */
class Machine extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Shop-floor states, in the order the filter offers them. */
    public const STATUSES = ['running', 'idle', 'breakdown', 'maintenance', 'retired'];

    protected $fillable = [
        'code', 'name', 'machine_type', 'warehouse_id', 'department_id', 'asset_id',
        'operator_id', 'manufacturer', 'model_number', 'serial_number', 'installed_on',
        'capacity_per_hour', 'capacity_uom', 'hourly_cost', 'power_kw',
        'status', 'last_service_on', 'next_service_due',
        'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'installed_on' => 'date',
        'last_service_on' => 'date',
        'next_service_due' => 'date',
        'capacity_per_hour' => 'decimal:3',
        'hourly_cost' => 'decimal:2',
        'power_kw' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The fixed-asset row for the same machine, when it is on the register. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(MachineDowntime::class)->orderByDesc('started_at');
    }

    /** Production runs loaded on this machine. */
    public function manufacturingOrders(): HasMany
    {
        return $this->hasMany(ManufacturingOrder::class);
    }

    /** The stop currently open, if the machine is down right now. */
    public function openDowntime(): ?MachineDowntime
    {
        return $this->downtimes()->whereNull('ended_at')->first();
    }

    /**
     * Hours this machine was stopped between two datetimes, counting only stops
     * that have been closed.
     *
     * Open stops are excluded on purpose: an unclosed downtime has no duration
     * yet, and treating "still down" as zero minutes understates the loss less
     * misleadingly than guessing at now() and then changing the answer every
     * time the report is run.
     */
    public function downtimeHours(string $from, string $to): float
    {
        $minutes = (int) $this->downtimes()
            ->whereNotNull('ended_at')
            ->whereBetween('started_at', [$from, $to])
            ->sum('minutes');

        return round($minutes / 60, 2);
    }

    /**
     * What one hour on this machine costs a run. Zero is a legitimate answer —
     * a shop that has not rated its plant yet simply charges nothing to the
     * machine bucket, rather than being blocked from recording the machine.
     */
    public function hourlyRate(): float
    {
        return (float) $this->hourly_cost;
    }

    /**
     * Hours this machine needs to make `$quantity`, from its rated capacity.
     * Null when no capacity is set: an unknown answer, reported as unknown.
     */
    public function hoursFor(float $quantity): ?float
    {
        $capacity = (float) $this->capacity_per_hour;

        return $capacity > 0 ? round($quantity / $capacity, 2) : null;
    }
}
