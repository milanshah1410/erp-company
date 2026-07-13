<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryRequest;
use App\Http\Resources\SalaryResource;
use App\Models\Salary;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function index(Request $request)
    {
        $query = Salary::query()->with('employee:id,employee_code,first_name,last_name');

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

        $query->orderByDesc('month')->orderBy('employee_id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalaryResource::collection($query->paginate($perPage));
    }

    public function store(SalaryRequest $request)
    {
        $salary = Salary::create($this->withNet($request->validated()))->refresh();

        return (new SalaryResource($salary->load('employee:id,employee_code,first_name,last_name')))
            ->response()->setStatusCode(201);
    }

    public function show(Salary $salary)
    {
        return new SalaryResource($salary->load('employee:id,employee_code,first_name,last_name'));
    }

    public function update(SalaryRequest $request, Salary $salary)
    {
        // Recompute net from the merged component values so a partial update stays consistent.
        $data = $request->validated();
        $merged = array_merge($salary->only(['basic', 'allowances', 'deductions']), $data);
        $salary->update($this->withNet($data, $merged));

        return new SalaryResource($salary->load('employee:id,employee_code,first_name,last_name'));
    }

    public function destroy(Salary $salary)
    {
        $salary->delete();

        return response()->json(['message' => 'Salary record deleted.']);
    }

    /**
     * Stamp net_salary = basic + allowances - deductions onto the write payload.
     * $components defaults to $data (create); on update pass the merged current+new values.
     */
    private function withNet(array $data, ?array $components = null): array
    {
        $c = $components ?? $data;
        $basic = (float) ($c['basic'] ?? 0);
        $allowances = (float) ($c['allowances'] ?? 0);
        $deductions = (float) ($c['deductions'] ?? 0);
        $data['net_salary'] = round($basic + $allowances - $deductions, 2);

        return $data;
    }
}
