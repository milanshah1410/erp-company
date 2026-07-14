<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TailorWorkCompletionRequest;
use App\Http\Requests\TailorWorkRequest;
use App\Http\Resources\TailorWorkResource;
use App\Models\RateCard;
use App\Models\TailorWork;
use App\Services\TailorWorkService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TailorWorkController extends Controller
{
    public function __construct(private TailorWorkService $service)
    {
    }

    public function index(Request $request)
    {
        $query = TailorWork::query()
            ->with(['tailor:id,name,code', 'product:id,name,sku']);

        // The "Pending Work" screen passes status=pending (open orders); the
        // "Completed Work" screen passes status=completed.
        $status = $request->query('status');
        if ($status === 'pending') {
            $query->whereIn('status', ['pending', 'partial']);
        } elseif ($status === 'completed') {
            $query->where('status', 'completed');
        } elseif ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('tailor_id')) {
            $query->where('tailor_id', $request->query('tailor_id'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('work_number', 'like', "%{$search}%")
                    ->orWhereHas('tailor', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return TailorWorkResource::collection($query->paginate($perPage));
    }

    public function store(TailorWorkRequest $request)
    {
        $data = $request->validated();

        // Snapshot the per-piece rate. Prefer an explicit override, else the
        // product's rate-card effective rate (sum of operation rates).
        $rateCard = RateCard::where('product_id', $data['product_id'])->first();
        $data['rate'] = isset($data['rate'])
            ? round((float) $data['rate'], 2)
            : ($rateCard?->effectiveRate() ?? 0.0);
        $data['rate_card_id'] = $rateCard?->id;
        $data['work_number'] = TailorWork::nextNumber();
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();
        $data['created_by'] = $request->user()?->id;

        $work = TailorWork::create($data);

        return (new TailorWorkResource($work->load(['tailor', 'product'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TailorWork $tailorWork)
    {
        return new TailorWorkResource($tailorWork->load(['tailor', 'product']));
    }

    public function update(TailorWorkRequest $request, TailorWork $tailorWork)
    {
        if ($tailorWork->completed_qty > 0) {
            return response()->json([
                'message' => 'Work with recorded completions cannot be edited.',
            ], 422);
        }

        $data = $request->validated();
        // Keep the rate snapshot in step with an edited product/override.
        if (array_key_exists('rate', $data) && $data['rate'] !== null) {
            $data['rate'] = round((float) $data['rate'], 2);
        } else {
            $rateCard = RateCard::where('product_id', $data['product_id'])->first();
            $data['rate'] = $rateCard?->effectiveRate() ?? (float) $tailorWork->rate;
            $data['rate_card_id'] = $rateCard?->id;
        }

        $tailorWork->update($data);

        return new TailorWorkResource($tailorWork->load(['tailor', 'product']));
    }

    public function destroy(TailorWork $tailorWork)
    {
        if ($tailorWork->completions()->exists()) {
            return response()->json([
                'message' => 'Work with recorded completions cannot be deleted. Cancel it instead.',
            ], 422);
        }
        $tailorWork->delete();

        return response()->json(['message' => 'Work order deleted.']);
    }

    /** Record a completion (full or partial) against a work order. */
    public function complete(TailorWorkCompletionRequest $request, TailorWork $tailorWork)
    {
        if ($tailorWork->status === 'cancelled') {
            throw ValidationException::withMessages(['qty' => 'This work order is cancelled.']);
        }

        $qty = round((float) $request->validated()['qty'], 2);
        if ($qty > $tailorWork->remainingQty() + 0.005) {
            throw ValidationException::withMessages([
                'qty' => "Cannot complete more than the remaining {$tailorWork->remainingQty()} pieces.",
            ]);
        }

        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $this->service->complete($tailorWork, $data);

        return new TailorWorkResource($tailorWork->fresh()->load(['tailor', 'product']));
    }
}
