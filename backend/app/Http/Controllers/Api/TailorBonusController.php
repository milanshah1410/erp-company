<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TailorBonusRequest;
use App\Http\Resources\TailorBonusResource;
use App\Models\Tailor;
use App\Models\TailorBonus;
use App\Services\TailorWorkService;
use Illuminate\Http\Request;

/**
 * Tailor performance bonuses. Bonuses may be derived from a rule (pieces above
 * target, efficiency, on-time) or entered manually. A bonus credits the tailor
 * ledger only once approved.
 */
class TailorBonusController extends Controller
{
    public function __construct(private TailorWorkService $service)
    {
    }

    public function index(Request $request)
    {
        $query = TailorBonus::query()->with('tailor:id,name,code');

        if ($request->filled('tailor_id')) {
            $query->where('tailor_id', $request->query('tailor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('bonus_number', 'like', "%{$search}%")
                    ->orWhereHas('tailor', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return TailorBonusResource::collection($query->paginate($perPage));
    }

    public function store(TailorBonusRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $tailor = Tailor::findOrFail($data['tailor_id']);
        $bonus = $this->service->awardBonus($tailor, $data);

        return (new TailorBonusResource($bonus->load('tailor')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TailorBonus $tailorBonus)
    {
        return new TailorBonusResource($tailorBonus->load('tailor'));
    }

    public function update(TailorBonusRequest $request, TailorBonus $tailorBonus)
    {
        $tailorBonus->update($request->validated());

        return new TailorBonusResource($tailorBonus->load('tailor'));
    }

    public function destroy(TailorBonus $tailorBonus)
    {
        $tailorBonus->delete();

        return response()->json(['message' => 'Bonus deleted.']);
    }

    /**
     * Preview a suggested bonus amount for a tailor without saving, so the UI
     * can prefill the amount before the user confirms.
     */
    public function calculate(Request $request)
    {
        $params = $request->validate([
            'tailor_id' => ['required', 'integer', 'exists:tailors,id'],
            'basis' => ['required', 'in:pieces,efficiency,ontime,manual'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'target' => ['nullable', 'numeric', 'min:0'],
            'tolerance' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $tailor = Tailor::findOrFail($params['tailor_id']);

        return response()->json([
            'data' => [
                'amount' => $this->service->computeBonus($tailor, $params),
            ],
        ]);
    }

    /** Approve a draft bonus so it credits the tailor's ledger. */
    public function approve(TailorBonus $tailorBonus)
    {
        if ($tailorBonus->status !== 'draft') {
            return response()->json(['message' => 'Only draft bonuses can be approved.'], 422);
        }
        $tailorBonus->update(['status' => 'approved']);

        return new TailorBonusResource($tailorBonus->load('tailor'));
    }
}
