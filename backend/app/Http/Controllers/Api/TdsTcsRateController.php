<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TdsTcsRateRequest;
use App\Http\Resources\TdsTcsRateResource;
use App\Models\TdsTcsRate;
use Illuminate\Http\Request;

class TdsTcsRateController extends Controller
{
    public function index(Request $request)
    {
        $query = TdsTcsRate::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('section', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($request->filled('nature')) {
            $query->where('nature', $request->query('nature'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('nature')->orderBy('section');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return TdsTcsRateResource::collection($query->paginate($perPage));
    }

    public function store(TdsTcsRateRequest $request)
    {
        $rate = TdsTcsRate::create($request->validated());

        return (new TdsTcsRateResource($rate))->response()->setStatusCode(201);
    }

    public function show(TdsTcsRate $tdsTcsRate)
    {
        return new TdsTcsRateResource($tdsTcsRate);
    }

    public function update(TdsTcsRateRequest $request, TdsTcsRate $tdsTcsRate)
    {
        $tdsTcsRate->update($request->validated());

        return new TdsTcsRateResource($tdsTcsRate);
    }

    public function destroy(TdsTcsRate $tdsTcsRate)
    {
        $tdsTcsRate->delete();

        return response()->json(['message' => 'TDS/TCS rate deleted.']);
    }
}
