<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RateCardRequest;
use App\Http\Resources\RateCardResource;
use App\Models\RateCard;
use Illuminate\Http\Request;

class RateCardController extends Controller
{
    public function index(Request $request)
    {
        $query = RateCard::query()
            ->with('product:id,name,sku')
            ->withCount('pieceRates');

        if ($search = $request->query('search')) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return RateCardResource::collection($query->paginate($perPage));
    }

    public function store(RateCardRequest $request)
    {
        $rateCard = RateCard::create($request->validated());

        return (new RateCardResource($rateCard->load('product')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(RateCard $rateCard)
    {
        return new RateCardResource($rateCard->load('product')->loadCount('pieceRates'));
    }

    public function update(RateCardRequest $request, RateCard $rateCard)
    {
        $rateCard->update($request->validated());

        return new RateCardResource($rateCard->load('product'));
    }

    public function destroy(RateCard $rateCard)
    {
        // Piece rates cascade on delete; work orders keep their rate snapshot.
        $rateCard->delete();

        return response()->json(['message' => 'Rate card deleted.']);
    }
}
