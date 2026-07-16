<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorRatingRequest;
use App\Http\Resources\VendorRatingResource;
use App\Models\Supplier;
use App\Models\VendorRating;
use Illuminate\Http\Request;

class VendorRatingController extends Controller
{
    public function index(Request $request)
    {
        $query = VendorRating::query()->with(['supplier', 'goodsReceipt']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('remarks', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderByDesc('rated_on')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return VendorRatingResource::collection($query->paginate($perPage));
    }

    public function store(VendorRatingRequest $request)
    {
        $data = $request->validated();

        $rating = VendorRating::create([
            'supplier_id' => $data['supplier_id'],
            'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'quality_score' => $data['quality_score'],
            'delivery_score' => $data['delivery_score'],
            'pricing_score' => $data['pricing_score'],
            'service_score' => $data['service_score'],
            'overall_score' => VendorRating::computeOverall(
                $data['quality_score'],
                $data['delivery_score'],
                $data['pricing_score'],
                $data['service_score'],
            ),
            'rated_on' => $data['rated_on'],
            'remarks' => $data['remarks'] ?? null,
            'rated_by' => $request->user()?->id,
        ]);

        return (new VendorRatingResource($rating->load(['supplier', 'goodsReceipt'])))
            ->response()->setStatusCode(201);
    }

    public function show(VendorRating $vendorRating)
    {
        return new VendorRatingResource($vendorRating->load(['supplier', 'goodsReceipt']));
    }

    public function destroy(VendorRating $vendorRating)
    {
        $vendorRating->delete();

        return response()->json(['message' => 'Vendor rating deleted.']);
    }

    /**
     * Per-vendor rating scorecard: averages across each category plus a count,
     * ranked by overall score. Powers the vendor-rating leaderboard.
     */
    public function summary(Request $request)
    {
        $rows = Supplier::query()
            ->withCount('ratings')
            ->withAvg('ratings as avg_quality', 'quality_score')
            ->withAvg('ratings as avg_delivery', 'delivery_score')
            ->withAvg('ratings as avg_pricing', 'pricing_score')
            ->withAvg('ratings as avg_service', 'service_score')
            ->withAvg('ratings as avg_overall', 'overall_score')
            ->having('ratings_count', '>', 0)
            ->orderByDesc('avg_overall')
            ->get()
            ->map(fn (Supplier $s) => [
                'supplier_id' => $s->id,
                'supplier' => $s->name,
                'ratings_count' => $s->ratings_count,
                'avg_quality' => round((float) $s->avg_quality, 2),
                'avg_delivery' => round((float) $s->avg_delivery, 2),
                'avg_pricing' => round((float) $s->avg_pricing, 2),
                'avg_service' => round((float) $s->avg_service, 2),
                'avg_overall' => round((float) $s->avg_overall, 2),
            ]);

        return response()->json(['data' => $rows]);
    }
}
