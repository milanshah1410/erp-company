<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceEventResource;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Modules\DeviceManager\Support\DeviceEventLog;
use Illuminate\Http\Request;

/**
 * The live device event log.
 *
 * Two read modes: a paginated history for the log screen, and an `after_id`
 * cursor the Device Manager tails — passing the highest id it already holds and
 * getting only what is new, which keeps a 2-second poll cheap no matter how big
 * the log gets.
 */
class DeviceEventController extends Controller
{
    public function __construct(private readonly DeviceEventLog $log) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'device_type' => ['nullable', 'string', 'max:20'],
            'event' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:success,failed,info'],
            'source' => ['nullable', 'in:live,simulator'],
            'after_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DeviceEvent::query()->with(['device:id,name,code', 'user:id,name']);

        foreach (['device_id', 'device_type', 'event', 'status', 'source'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        // Tail mode: only rows newer than what the caller has, oldest first so
        // the client can append them in order.
        if (! empty($filters['after_id'])) {
            $events = $query->where('id', '>', (int) $filters['after_id'])
                ->orderBy('id')
                ->limit(200)
                ->get();

            return DeviceEventResource::collection($events);
        }

        return DeviceEventResource::collection(
            $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 50))
        );
    }

    /** Clear the whole tenant log, or one device's slice of it. */
    public function destroy(Request $request)
    {
        $deviceId = $request->integer('device_id');
        $device = $deviceId ? Device::find($deviceId) : null;

        $deleted = $this->log->clear($device);

        return response()->json([
            'message' => $device
                ? "Cleared {$deleted} event(s) for {$device->name}."
                : "Cleared {$deleted} event(s).",
            'deleted' => $deleted,
        ]);
    }
}
