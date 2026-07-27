<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A container (or air ULD) moving under a shipment — the Container Status board
 * (SRS §24). Its `status` walks the physical chain planned → stuffed → gated in
 * → loaded → in transit → discharged → gated out → delivered → empty returned,
 * each move stamping `status_updated_at` and appending a shipment timeline event.
 *
 * `detention_free_until` is the last day the carrier's free days cover; past it
 * the box accrues detention/demurrage, which the board flags so the desk can act
 * before the invoice arrives.
 */
class ShipmentContainer extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** The physical chain, in order — the UI offers "next" from here. */
    public const STATUSES = [
        'planned', 'stuffed', 'gated_in', 'loaded', 'in_transit',
        'discharged', 'gated_out', 'delivered', 'empty_returned',
    ];

    /** Container status → shipment timeline milestone. */
    public const STATUS_MILESTONES = [
        'stuffed' => 'stuffing_done',
        'gated_in' => 'gated_in',
        'loaded' => 'stuffing_done',
        'in_transit' => 'in_transit',
        'discharged' => 'discharged',
        'gated_out' => 'gated_out',
        'delivered' => 'delivered',
        'empty_returned' => 'empty_returned',
    ];

    protected $fillable = [
        'shipment_id', 'container_number', 'container_type', 'seal_number', 'status',
        'current_location', 'status_updated_at', 'packages',
        'gross_weight_kg', 'net_weight_kg', 'cbm',
        'free_days', 'detention_free_until', 'empty_returned_on',
        'is_damaged', 'damage_remarks', 'remarks',
    ];

    protected $casts = [
        'status_updated_at' => 'datetime',
        'detention_free_until' => 'date',
        'empty_returned_on' => 'date',
        'is_damaged' => 'boolean',
        'gross_weight_kg' => 'decimal:3',
        'net_weight_kg' => 'decimal:3',
        'cbm' => 'decimal:3',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** True once free days have run out and the box is still not returned. */
    public function isAccruingDetention(): bool
    {
        return $this->detention_free_until !== null
            && $this->status !== 'empty_returned'
            && $this->detention_free_until->isPast();
    }

    /** Days past the free-days window (0 when still inside it). */
    public function detentionDays(): int
    {
        if (! $this->isAccruingDetention()) {
            return 0;
        }

        return (int) round($this->detention_free_until->diffInDays(now()->startOfDay(), false));
    }
}
