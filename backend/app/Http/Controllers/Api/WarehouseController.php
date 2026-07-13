<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::query()->with(['company:id,name', 'branch:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return WarehouseResource::collection($query->paginate($perPage));
    }

    public function store(WarehouseRequest $request)
    {
        $warehouse = Warehouse::create($request->validated());

        return (new WarehouseResource($warehouse->load(['company:id,name', 'branch:id,name'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Warehouse $warehouse)
    {
        return new WarehouseResource($warehouse->load(['company:id,name', 'branch:id,name']));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());

        return new WarehouseResource($warehouse->load(['company:id,name', 'branch:id,name']));
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return response()->json(['message' => 'Warehouse deleted.']);
    }
}
