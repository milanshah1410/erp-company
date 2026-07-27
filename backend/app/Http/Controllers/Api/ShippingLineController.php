<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingLineRequest;
use App\Http\Resources\ShippingLineResource;
use App\Models\ShippingLine;
use Illuminate\Http\Request;

/**
 * Shipping line (ocean carrier) master — the carrier named on a Bill of Lading.
 */
class ShippingLineController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingLine::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('scac_code', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShippingLineResource::collection($query->paginate($perPage));
    }

    public function store(ShippingLineRequest $request)
    {
        $line = ShippingLine::create($request->validated());

        return (new ShippingLineResource($line))->response()->setStatusCode(201);
    }

    public function show(ShippingLine $shippingLine)
    {
        return new ShippingLineResource($shippingLine);
    }

    public function update(ShippingLineRequest $request, ShippingLine $shippingLine)
    {
        $shippingLine->update($request->validated());

        return new ShippingLineResource($shippingLine);
    }

    public function destroy(ShippingLine $shippingLine)
    {
        $shippingLine->delete();

        return response()->json(['message' => 'Shipping line deleted.']);
    }
}
