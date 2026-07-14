<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryStructureRequest;
use App\Http\Resources\SalaryStructureResource;
use App\Models\SalaryStructure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryStructureController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name'];

    public function index(Request $request)
    {
        $query = SalaryStructure::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('effective_from');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalaryStructureResource::collection($query->paginate($perPage));
    }

    public function store(SalaryStructureRequest $request)
    {
        $data = $request->validated();

        $structure = DB::transaction(function () use ($data) {
            // A newly-added active structure supersedes any prior active one.
            if (($data['is_active'] ?? true)) {
                SalaryStructure::where('employee_id', $data['employee_id'])
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            return SalaryStructure::create($data);
        });

        return (new SalaryStructureResource($structure->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(SalaryStructure $salaryStructure)
    {
        return new SalaryStructureResource($salaryStructure->load(self::WITH));
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $salaryStructure)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $salaryStructure) {
            if (($data['is_active'] ?? $salaryStructure->is_active)) {
                SalaryStructure::where('employee_id', $data['employee_id'] ?? $salaryStructure->employee_id)
                    ->where('is_active', true)
                    ->where('id', '!=', $salaryStructure->id)
                    ->update(['is_active' => false]);
            }
            $salaryStructure->update($data);
        });

        return new SalaryStructureResource($salaryStructure->load(self::WITH));
    }

    public function destroy(SalaryStructure $salaryStructure)
    {
        $salaryStructure->delete();

        return response()->json(['message' => 'Salary structure deleted.']);
    }
}
