<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeBonusRequest;
use App\Http\Resources\EmployeeBonusResource;
use App\Models\EmployeeBonus;
use Illuminate\Http\Request;

class EmployeeBonusController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name'];

    public function index(Request $request)
    {
        $query = EmployeeBonus::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('month')) {
            $query->whereYear('month', substr((string) $request->query('month'), 0, 4))
                ->whereMonth('month', substr((string) $request->query('month'), 5, 2));
        }

        $query->orderByDesc('month');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmployeeBonusResource::collection($query->paginate($perPage));
    }

    public function store(EmployeeBonusRequest $request)
    {
        $bonus = EmployeeBonus::create($request->validated());

        return (new EmployeeBonusResource($bonus->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(EmployeeBonus $employeeBonus)
    {
        return new EmployeeBonusResource($employeeBonus->load(self::WITH));
    }

    public function update(EmployeeBonusRequest $request, EmployeeBonus $employeeBonus)
    {
        $employeeBonus->update($request->validated());

        return new EmployeeBonusResource($employeeBonus->load(self::WITH));
    }

    public function destroy(EmployeeBonus $employeeBonus)
    {
        $employeeBonus->delete();

        return response()->json(['message' => 'Bonus deleted.']);
    }

    /** Approve a bonus so the month's payroll run pays it out. */
    public function approve(Request $request, EmployeeBonus $employeeBonus)
    {
        if ($employeeBonus->status !== 'pending') {
            return response()->json(['message' => 'Only pending bonuses can be approved.'], 422);
        }

        $employeeBonus->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new EmployeeBonusResource($employeeBonus->load(self::WITH));
    }
}
