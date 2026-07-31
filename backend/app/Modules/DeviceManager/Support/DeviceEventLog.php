<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Support;

use App\Models\Device;
use App\Models\DeviceEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes the live event log.
 *
 * Kept out of the drivers on purpose: a brand adapter stays a pure translator,
 * and every event — live or simulated, success or failure — is stamped
 * identically here. That uniformity is what makes the log usable for diagnosing
 * a counter over the phone.
 */
class DeviceEventLog
{
    public function record(
        Device $device,
        string $event,
        string $status = 'success',
        ?string $message = null,
        array $payload = [],
        string $direction = 'in',
        ?int $durationMs = null,
    ): DeviceEvent {
        $entry = DeviceEvent::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'device_type' => $device->type,
            'driver' => $device->driver,
            'event' => $event,
            'direction' => $direction,
            'status' => $status,
            'source' => $device->isSimulated() ? 'simulator' : 'live',
            'message' => $message === null ? null : mb_substr($message, 0, 500),
            'payload' => $payload === [] ? null : $payload,
            'duration_ms' => $durationMs,
            'user_id' => Auth::id(),
            'occurred_at' => now(),
        ]);

        // Counter, not a relation count: the device grid shows traffic per device
        // and must not fan out a COUNT(*) per row.
        DB::table('devices')->where('id', $device->id)->update([
            'events_count' => DB::raw('events_count + 1'),
            'last_seen_at' => now(),
        ]);

        return $entry;
    }

    /** Drop a tenant's log, optionally for one device only. */
    public function clear(?Device $device = null): int
    {
        $query = DeviceEvent::query();
        if ($device !== null) {
            $query->where('device_id', $device->id);
        }

        return $query->delete();
    }
}
