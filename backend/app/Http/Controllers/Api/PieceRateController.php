<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PieceRateRequest;
use App\Http\Resources\PieceRateResource;
use App\Models\PieceRate;
use Illuminate\Http\Request;

class PieceRateController extends Controller
{
    public function index(Request $request)
    {
        $query = PieceRate::query()
            ->with('rateCard.product:id,name');

        if ($request->filled('rate_card_id')) {
            $query->where('rate_card_id', $request->query('rate_card_id'));
        }

        if ($search = $request->query('search')) {
            $query->where('operation', 'like', "%{$search}%");
        }

        $query->orderBy('rate_card_id')->orderBy('sequence')->orderBy('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return PieceRateResource::collection($query->paginate($perPage));
    }

    public function store(PieceRateRequest $request)
    {
        $pieceRate = PieceRate::create($request->validated());

        return (new PieceRateResource($pieceRate->load('rateCard.product')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PieceRate $pieceRate)
    {
        return new PieceRateResource($pieceRate->load('rateCard.product'));
    }

    public function update(PieceRateRequest $request, PieceRate $pieceRate)
    {
        $pieceRate->update($request->validated());

        return new PieceRateResource($pieceRate->load('rateCard.product'));
    }

    public function destroy(PieceRate $pieceRate)
    {
        $pieceRate->delete();

        return response()->json(['message' => 'Piece rate deleted.']);
    }
}
