<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryRouteRequest;
use App\Http\Resources\DeliveryRouteResource;
use App\Models\DeliveryRoute;
use Illuminate\Http\Request;

/**
 * Delivery-route master — referenced by dispatch plans to standardise
 * recurring deliveries and carry a default freight charge.
 */
class DeliveryRouteController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryRoute::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return DeliveryRouteResource::collection($query->paginate($perPage));
    }

    public function store(DeliveryRouteRequest $request)
    {
        $route = DeliveryRoute::create($request->validated());

        return (new DeliveryRouteResource($route))->response()->setStatusCode(201);
    }

    public function show(DeliveryRoute $deliveryRoute)
    {
        return new DeliveryRouteResource($deliveryRoute);
    }

    public function update(DeliveryRouteRequest $request, DeliveryRoute $deliveryRoute)
    {
        $deliveryRoute->update($request->validated());

        return new DeliveryRouteResource($deliveryRoute);
    }

    public function destroy(DeliveryRoute $deliveryRoute)
    {
        if ($deliveryRoute->dispatchPlans()->exists()) {
            return response()->json(['message' => 'Cannot delete a route linked to dispatch plans.'], 422);
        }
        $deliveryRoute->delete();

        return response()->json(['message' => 'Delivery route deleted.']);
    }
}
