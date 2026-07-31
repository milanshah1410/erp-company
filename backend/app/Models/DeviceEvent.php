<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the live device event log: a connection test, a scan, a print
 * job, a weight reading, a tag read or a punch pull — live or simulated.
 *
 * Deliberately append-only and free of an audit trail of its own: this is
 * high-volume hardware telemetry, cleared from the UI rather than versioned.
 */
class DeviceEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'device_id', 'device_type', 'driver', 'event', 'direction', 'status',
        'source', 'message', 'payload', 'duration_ms', 'user_id', 'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
