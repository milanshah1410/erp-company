<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query()->with(['department:id,name', 'user:id,name']);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->orderBy('employee_code');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmployeeResource::collection($query->paginate($perPage));
    }

    public function store(EmployeeRequest $request)
    {
        $employee = Employee::create($request->validated())->refresh();

        return (new EmployeeResource($employee->load(['department:id,name', 'user:id,name'])))->response()->setStatusCode(201);
    }

    public function show(Employee $employee)
    {
        return new EmployeeResource($employee->load(['department:id,name', 'user:id,name']));
    }

    public function update(EmployeeRequest $request, Employee $employee)
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(['department:id,name', 'user:id,name']));
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }
}
