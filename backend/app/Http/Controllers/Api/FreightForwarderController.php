<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FreightForwarderRequest;
use App\Http\Resources\FreightForwarderResource;
use App\Models\FreightForwarder;
use Illuminate\Http\Request;

/**
 * Freight forwarder master — books carrier space and consolidates cargo.
 */
class FreightForwarderController extends Controller
{
    public function index(Request $request)
    {
        $query = FreightForwarder::query()->with('country:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->query('country_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'city', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return FreightForwarderResource::collection($query->paginate($perPage));
    }

    public function store(FreightForwarderRequest $request)
    {
        $forwarder = FreightForwarder::create($request->validated());

        return (new FreightForwarderResource($forwarder->load('country')))->response()->setStatusCode(201);
    }

    public function show(FreightForwarder $freightForwarder)
    {
        return new FreightForwarderResource($freightForwarder->load('country'));
    }

    public function update(FreightForwarderRequest $request, FreightForwarder $freightForwarder)
    {
        $freightForwarder->update($request->validated());

        return new FreightForwarderResource($freightForwarder->load('country'));
    }

    public function destroy(FreightForwarder $freightForwarder)
    {
        $freightForwarder->delete();

        return response()->json(['message' => 'Freight forwarder deleted.']);
    }
}
