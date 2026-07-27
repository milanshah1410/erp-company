<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChaAgentRequest;
use App\Http\Resources\ChaAgentResource;
use App\Models\ChaAgent;
use Illuminate\Http\Request;

/**
 * Customs House Agent master. License expiry is tracked like the driver
 * licence master, so a lapsed CHA licence can be surfaced.
 */
class ChaAgentController extends Controller
{
    public function index(Request $request)
    {
        $query = ChaAgent::query()->with('port:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('license_no', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if ($request->filled('port_id')) {
            $query->where('port_id', $request->query('port_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'license_expiry', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ChaAgentResource::collection($query->paginate($perPage));
    }

    public function store(ChaAgentRequest $request)
    {
        $agent = ChaAgent::create($request->validated());

        return (new ChaAgentResource($agent->load('port')))->response()->setStatusCode(201);
    }

    public function show(ChaAgent $chaAgent)
    {
        return new ChaAgentResource($chaAgent->load('port'));
    }

    public function update(ChaAgentRequest $request, ChaAgent $chaAgent)
    {
        $chaAgent->update($request->validated());

        return new ChaAgentResource($chaAgent->load('port'));
    }

    public function destroy(ChaAgent $chaAgent)
    {
        $chaAgent->delete();

        return response()->json(['message' => 'CHA agent deleted.']);
    }
}
