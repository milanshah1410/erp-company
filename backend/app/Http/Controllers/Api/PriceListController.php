<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceListRequest;
use App\Http\Resources\PriceListResource;
use App\Models\PriceList;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    public function index(Request $request)
    {
        $query = PriceList::query()->with(['product', 'variant']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('level')) {
            $query->where('level', $request->query('level'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['level', 'price', 'min_quantity', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return PriceListResource::collection($query->paginate($perPage));
    }

    public function store(PriceListRequest $request)
    {
        $priceList = PriceList::create($request->validated());

        return (new PriceListResource($priceList->load(['product', 'variant'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PriceList $priceList)
    {
        return new PriceListResource($priceList->load(['product', 'variant']));
    }

    public function update(PriceListRequest $request, PriceList $priceList)
    {
        $priceList->update($request->validated());

        return new PriceListResource($priceList->load(['product', 'variant']));
    }

    public function destroy(PriceList $priceList)
    {
        $priceList->delete();

        return response()->json(['message' => 'PriceList deleted.']);
    }
}
