<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortRequest;
use App\Http\Resources\PortResource;
use App\Models\Port;
use Illuminate\Http\Request;

/**
 * Port master — sea / air / ICD / CFS. Filterable by `type` and `country_id`
 * so a shipment screen can offer only the ports of the relevant mode.
 */
class PortController extends Controller
{
    public function index(Request $request)
    {
        $query = Port::query()->with('country:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->query('country_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'type', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return PortResource::collection($query->paginate($perPage));
    }

    public function store(PortRequest $request)
    {
        $port = Port::create($request->validated());

        return (new PortResource($port->load('country')))->response()->setStatusCode(201);
    }

    public function show(Port $port)
    {
        return new PortResource($port->load('country'));
    }

    public function update(PortRequest $request, Port $port)
    {
        $port->update($request->validated());

        return new PortResource($port->load('country'));
    }

    public function destroy(Port $port)
    {
        if ($port->chaAgents()->exists() || $port->suppliers()->exists() || $port->customers()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a port that is used by CHA agents or overseas parties. Mark it inactive instead.',
            ], 422);
        }
        $port->delete();

        return response()->json(['message' => 'Port deleted.']);
    }
}
