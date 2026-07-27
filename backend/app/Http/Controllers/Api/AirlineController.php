<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AirlineRequest;
use App\Http\Resources\AirlineResource;
use App\Models\Airline;
use Illuminate\Http\Request;

/**
 * Airline (air carrier) master — the carrier named on an Air Waybill.
 */
class AirlineController extends Controller
{
    public function index(Request $request)
    {
        $query = Airline::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('icao_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('awb_prefix', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return AirlineResource::collection($query->paginate($perPage));
    }

    public function store(AirlineRequest $request)
    {
        $airline = Airline::create($request->validated());

        return (new AirlineResource($airline))->response()->setStatusCode(201);
    }

    public function show(Airline $airline)
    {
        return new AirlineResource($airline);
    }

    public function update(AirlineRequest $request, Airline $airline)
    {
        $airline->update($request->validated());

        return new AirlineResource($airline);
    }

    public function destroy(Airline $airline)
    {
        $airline->delete();

        return response()->json(['message' => 'Airline deleted.']);
    }
}
