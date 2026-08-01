<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\ManufacturingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine stop, with its reason.
 *
 * Utilisation without reasons is a number nobody can act on. "The press ran 40%
 * of the shift" is the same figure whether the cause was a broken bearing, an
 * empty material rack or an operator who did not turn up — and those are three
 * different problems belonging to three different people. The reason code is the
 * whole point of the record; the minutes are only its size.
 *
 * A row with no `ended_at` is a machine that is down *now*, which is also how the
 * master's live state is corroborated without a second flag to keep in step.
 */
class MachineDowntime extends Model
{
    use BelongsToTenant;

    /**
     * Reason codes. Split the way a factory splits blame: `breakdown` is the
     * machine's fault and counts against it, `maintenance` is planned and does
     * not, and the rest belong to material, people or the schedule.
     */
    public const REASONS = [
        'breakdown' => 'Breakdown',
        'maintenance' => 'Planned Maintenance',
        'power_failure' => 'Power Failure',
        'no_material' => 'No Material',
        'no_operator' => 'No Operator',
        'changeover' => 'Changeover / Setup',
        'quality_hold' => 'Quality Hold',
        'other' => 'Other',
    ];

    protected $fillable = [
        'machine_id', 'manufacturing_order_id', 'started_at', 'ended_at',
        'minutes', 'reason', 'remarks', 'reported_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** Is the machine still stopped on this row? */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Close the stop and stamp its duration.
     *
     * Clamped at zero: a clock corrected mid-shift, or an end time keyed before
     * the start, would otherwise write a negative stop that quietly cancels out
     * real downtime in the sum.
     */
    public function close(\DateTimeInterface $endedAt): void
    {
        $minutes = (int) round(($endedAt->getTimestamp() - $this->started_at->getTimestamp()) / 60);

        $this->forceFill([
            'ended_at' => $endedAt,
            'minutes' => max(0, $minutes),
        ])->save();
    }
}
