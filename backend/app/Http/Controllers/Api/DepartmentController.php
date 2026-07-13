<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::query()->withCount('employees');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return DepartmentResource::collection($query->paginate($perPage));
    }

    public function store(DepartmentRequest $request)
    {
        $department = Department::create($request->validated())->refresh();

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function show(Department $department)
    {
        return new DepartmentResource($department->loadCount('employees'));
    }

    public function update(DepartmentRequest $request, Department $department)
    {
        $department->update($request->validated());

        return new DepartmentResource($department->loadCount('employees'));
    }

    public function destroy(Department $department)
    {
        if ($department->employees()->exists()) {
            return response()->json(['message' => 'This department has employees assigned and cannot be deleted.'], 422);
        }
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }
}
