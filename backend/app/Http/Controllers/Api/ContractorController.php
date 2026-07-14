<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContractorRequest;
use App\Http\Resources\ContractorResource;
use App\Http\Resources\TailorResource;
use App\Models\Contractor;
use Illuminate\Http\Request;

/**
 * Contractor master: a subcontractor who supplies several tailors. The money
 * ledger lives on the tailors; a contractor rolls its tailors' figures up.
 */
class ContractorController extends Controller
{
    public function index(Request $request)
    {
        $query = Contractor::query()->withCount('tailors');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ContractorResource::collection($query->paginate($perPage));
    }

    public function store(ContractorRequest $request)
    {
        $contractor = Contractor::create($request->validated());

        return (new ContractorResource($contractor))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contractor $contractor)
    {
        return new ContractorResource($contractor->loadCount('tailors')->load('tailors'));
    }

    public function update(ContractorRequest $request, Contractor $contractor)
    {
        $contractor->update($request->validated());

        return new ContractorResource($contractor);
    }

    public function destroy(Contractor $contractor)
    {
        if ($contractor->tailors()->exists()) {
            return response()->json([
                'message' => 'This contractor has tailors attached and cannot be deleted. Reassign them or mark it inactive instead.',
            ], 422);
        }
        $contractor->delete();

        return response()->json(['message' => 'Contractor deleted.']);
    }

    /** The tailors that belong to this contractor. */
    public function tailors(Contractor $contractor)
    {
        return TailorResource::collection(
            $contractor->tailors()->with('contractor:id,name')->orderBy('name')->get()
        );
    }

    /**
     * Rolled-up performance and money position for the contractor: totals across
     * its tailors plus a per-tailor breakdown.
     */
    public function performance(Contractor $contractor)
    {
        $contractor->load([
            'tailors' => fn ($q) => $q->orderBy('name'),
        ]);

        $tailors = $contractor->tailors->map(function ($t) {
            $issued = (float) $t->works()->sum('issued_qty');
            $completed = (float) $t->works()->sum('completed_qty');
            $rejected = (float) $t->works()->sum('rejected_qty');

            return [
                'tailor_id' => $t->id,
                'tailor' => $t->name,
                'issued_qty' => round($issued, 2),
                'completed_qty' => round($completed, 2),
                'rejected_qty' => round($rejected, 2),
                'earned' => $t->totalEarned(),
                'paid' => $t->totalPaid(),
                'outstanding' => $t->outstandingBalance(),
            ];
        });

        return response()->json([
            'data' => $tailors,
            'summary' => [
                'contractor' => new ContractorResource($contractor->loadCount('tailors')),
                'total_earned' => $contractor->totalEarned(),
                'total_paid' => $contractor->totalPaid(),
                'outstanding_balance' => $contractor->outstandingBalance(),
            ],
        ]);
    }
}
