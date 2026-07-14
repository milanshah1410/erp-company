<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanLocationRequest;
use App\Http\Resources\SalesmanLocationResource;
use App\Models\Salesman;
use App\Models\SalesmanLocation;
use Illuminate\Http\Request;

/**
 * GPS tracking. The mobile app POSTs periodic location pings; the latest ping
 * per salesman is their live location, and the day's pings form a track for
 * the map.
 */
class SalesmanLocationController extends Controller
{
    /** Location track for a salesman, optionally bounded to a day / range. */
    public function index(Request $request)
    {
        $query = SalesmanLocation::query();

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('date')) {
            $query->whereDate('recorded_at', $request->query('date'));
        }
        if ($request->filled('from')) {
            $query->where('recorded_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('recorded_at', '<=', $request->query('to'));
        }

        $query->orderBy('recorded_at');
        $perPage = min((int) $request->query('per_page', 200), 1000);

        return SalesmanLocationResource::collection($query->paginate($perPage));
    }

    /** Record a location ping. */
    public function store(SalesmanLocationRequest $request)
    {
        $data = $request->validated();
        $data['recorded_at'] = $data['recorded_at'] ?? now();

        $location = SalesmanLocation::create($data);

        return (new SalesmanLocationResource($location))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Live location for every active salesman (or a filtered set) — the latest
     * ping each. Powers the fleet tracking map.
     */
    public function live(Request $request)
    {
        $salesmen = Salesman::query()
            ->where('is_active', true)
            ->with('latestLocation')
            ->get()
            ->map(fn (Salesman $s) => [
                'salesman_id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'location' => $s->latestLocation
                    ? new SalesmanLocationResource($s->latestLocation)
                    : null,
            ])
            ->values();

        return response()->json(['data' => $salesmen]);
    }
}
