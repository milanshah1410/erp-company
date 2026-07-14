<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * Vehicle master — the fleet referenced by vehicle and fuel expenses.
 */
class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('registration_no', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('registration_no');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return VehicleResource::collection($query->paginate($perPage));
    }

    public function store(VehicleRequest $request)
    {
        $vehicle = Vehicle::create($request->validated());

        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle)
    {
        return new VehicleResource($vehicle);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle)
    {
        $vehicle->update($request->validated());

        return new VehicleResource($vehicle);
    }

    public function destroy(Vehicle $vehicle)
    {
        if ($vehicle->vehicleExpenses()->exists() || $vehicle->fuelExpenses()->exists()) {
            return response()->json(['message' => 'Cannot delete a vehicle that has expenses booked against it.'], 422);
        }
        $vehicle->delete();

        return response()->json(['message' => 'Vehicle deleted.']);
    }
}
