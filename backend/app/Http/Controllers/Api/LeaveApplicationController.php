<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveApplicationRequest;
use App\Http\Resources\LeaveApplicationResource;
use App\Models\LeaveApplication;
use Illuminate\Http\Request;

class LeaveApplicationController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name', 'leaveType:id,name', 'approver:id,name'];

    public function index(Request $request)
    {
        $query = LeaveApplication::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->query('leave_type_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('from')) {
            $query->where('from_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('to_date', '<=', $request->query('to'));
        }

        $query->orderByDesc('from_date');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return LeaveApplicationResource::collection($query->paginate($perPage));
    }

    public function store(LeaveApplicationRequest $request)
    {
        $leave = LeaveApplication::create($request->validated());

        return (new LeaveApplicationResource($leave->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(LeaveApplication $leaveApplication)
    {
        return new LeaveApplicationResource($leaveApplication->load(self::WITH));
    }

    public function update(LeaveApplicationRequest $request, LeaveApplication $leaveApplication)
    {
        $leaveApplication->update($request->validated());

        return new LeaveApplicationResource($leaveApplication->load(self::WITH));
    }

    public function destroy(LeaveApplication $leaveApplication)
    {
        $leaveApplication->delete();

        return response()->json(['message' => 'Leave application deleted.']);
    }

    /** Approve a pending leave request. */
    public function approve(Request $request, LeaveApplication $leaveApplication)
    {
        if ($leaveApplication->status !== 'pending') {
            return response()->json(['message' => 'Only pending leave requests can be approved.'], 422);
        }

        $leaveApplication->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'remarks' => $request->input('remarks', $leaveApplication->remarks),
        ]);

        return new LeaveApplicationResource($leaveApplication->load(self::WITH));
    }

    /** Reject a pending leave request. */
    public function reject(Request $request, LeaveApplication $leaveApplication)
    {
        if ($leaveApplication->status !== 'pending') {
            return response()->json(['message' => 'Only pending leave requests can be rejected.'], 422);
        }

        $leaveApplication->update([
            'status' => 'rejected',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'remarks' => $request->input('remarks', $leaveApplication->remarks),
        ]);

        return new LeaveApplicationResource($leaveApplication->load(self::WITH));
    }
}
