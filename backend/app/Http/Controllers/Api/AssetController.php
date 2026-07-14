<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\Request;

/**
 * Fixed-asset master — referenced by asset maintenance and (optionally)
 * factory maintenance.
 */
class AssetController extends Controller
{
    public function index(Request $request)
    {
        $query = Asset::query()->with('branch:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('asset_code');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return AssetResource::collection($query->paginate($perPage));
    }

    public function store(AssetRequest $request)
    {
        $asset = Asset::create($request->validated());

        return (new AssetResource($asset->load('branch')))->response()->setStatusCode(201);
    }

    public function show(Asset $asset)
    {
        return new AssetResource($asset->load('branch'));
    }

    public function update(AssetRequest $request, Asset $asset)
    {
        $asset->update($request->validated());

        return new AssetResource($asset->load('branch'));
    }

    public function destroy(Asset $asset)
    {
        if ($asset->maintenances()->exists()) {
            return response()->json(['message' => 'Cannot delete an asset that has maintenance records.'], 422);
        }
        $asset->delete();

        return response()->json(['message' => 'Asset deleted.']);
    }
}
