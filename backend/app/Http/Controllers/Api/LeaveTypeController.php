<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveType::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return LeaveTypeResource::collection($query->paginate($perPage));
    }

    public function store(LeaveTypeRequest $request)
    {
        $leaveType = LeaveType::create($request->validated());

        return (new LeaveTypeResource($leaveType))->response()->setStatusCode(201);
    }

    public function show(LeaveType $leaveType)
    {
        return new LeaveTypeResource($leaveType);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType)
    {
        $leaveType->update($request->validated());

        return new LeaveTypeResource($leaveType);
    }

    public function destroy(LeaveType $leaveType)
    {
        $leaveType->delete();

        return response()->json(['message' => 'Leave type deleted.']);
    }
}
