<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HsnCodeRequest;
use App\Http\Resources\HsnCodeResource;
use App\Models\HsnCode;
use Illuminate\Http\Request;

class HsnCodeController extends Controller
{
    public function index(Request $request)
    {
        $query = HsnCode::query();

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderBy('code');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return HsnCodeResource::collection($query->paginate($perPage));
    }

    public function store(HsnCodeRequest $request)
    {
        $hsnCode = HsnCode::create($request->validated());

        return (new HsnCodeResource($hsnCode))->response()->setStatusCode(201);
    }

    public function show(HsnCode $hsnCode)
    {
        return new HsnCodeResource($hsnCode);
    }

    public function update(HsnCodeRequest $request, HsnCode $hsnCode)
    {
        $hsnCode->update($request->validated());

        return new HsnCodeResource($hsnCode);
    }

    public function destroy(HsnCode $hsnCode)
    {
        $hsnCode->delete();

        return response()->json(['message' => 'HSN code deleted.']);
    }
}
