<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A milestone on a shipment's journey — the Shipment Timeline (SRS §24).
 *
 * Rows come from two places: `system` entries written by Shipment::logEvent when
 * a status, customs state, container move, document or payment changes, and
 * `manual` entries the desk types in (carrier updates, delays, port remarks).
 * Both render on one chronological thread. Not audit-logged — the timeline *is*
 * the log, and every write already carries who and when.
 */
class ShipmentEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shipment_id', 'event_type', 'event_at', 'location', 'description', 'source', 'created_by',
    ];

    protected $casts = [
        'event_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return TradeCatalogue::milestoneLabel($this->event_type);
    }
}
