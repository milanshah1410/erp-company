<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryAdvanceRequest;
use App\Http\Resources\SalaryAdvanceResource;
use App\Models\SalaryAdvance;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name'];

    public function index(Request $request)
    {
        $query = SalaryAdvance::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('date');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalaryAdvanceResource::collection($query->paginate($perPage));
    }

    public function store(SalaryAdvanceRequest $request)
    {
        $advance = SalaryAdvance::create($request->validated());

        return (new SalaryAdvanceResource($advance->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(SalaryAdvance $salaryAdvance)
    {
        return new SalaryAdvanceResource($salaryAdvance->load(self::WITH));
    }

    public function update(SalaryAdvanceRequest $request, SalaryAdvance $salaryAdvance)
    {
        // Recovery already in progress is locked to protect the ledger.
        if (in_array($salaryAdvance->status, ['recovering', 'closed'], true)) {
            return response()->json(['message' => 'Cannot edit an advance that is being recovered.'], 422);
        }

        $salaryAdvance->update($request->validated());

        return new SalaryAdvanceResource($salaryAdvance->load(self::WITH));
    }

    public function destroy(SalaryAdvance $salaryAdvance)
    {
        if ((float) $salaryAdvance->recovered > 0) {
            return response()->json(['message' => 'Cannot delete an advance with recoveries.'], 422);
        }

        $salaryAdvance->delete();

        return response()->json(['message' => 'Salary advance deleted.']);
    }

    /** Approve an advance so payroll starts recovering it. */
    public function approve(Request $request, SalaryAdvance $salaryAdvance)
    {
        if ($salaryAdvance->status !== 'pending') {
            return response()->json(['message' => 'Only pending advances can be approved.'], 422);
        }

        $salaryAdvance->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new SalaryAdvanceResource($salaryAdvance->load(self::WITH));
    }
}
