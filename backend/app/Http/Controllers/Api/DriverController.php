<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use Illuminate\Http\Request;

/**
 * Driver master — referenced by dispatch plans. License-expiry is tracked so
 * expiring licences can be surfaced.
 */
class DriverController extends Controller
{
    public function index(Request $request)
    {
        $query = Driver::query()->with('employee:id,first_name,last_name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('license_no', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return DriverResource::collection($query->paginate($perPage));
    }

    public function store(DriverRequest $request)
    {
        $driver = Driver::create($request->validated());

        return (new DriverResource($driver))->response()->setStatusCode(201);
    }

    public function show(Driver $driver)
    {
        return new DriverResource($driver->load('employee'));
    }

    public function update(DriverRequest $request, Driver $driver)
    {
        $driver->update($request->validated());

        return new DriverResource($driver);
    }

    public function destroy(Driver $driver)
    {
        if ($driver->dispatchPlans()->exists()) {
            return response()->json(['message' => 'Cannot delete a driver linked to dispatch plans.'], 422);
        }
        $driver->delete();

        return response()->json(['message' => 'Driver deleted.']);
    }
}
