<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::query()->with('employee:id,employee_code,first_name,last_name');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        $query->orderByDesc('date')->orderBy('employee_id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return AttendanceResource::collection($query->paginate($perPage));
    }

    public function store(AttendanceRequest $request)
    {
        $attendance = Attendance::create($request->validated())->refresh();

        return (new AttendanceResource($attendance->load('employee:id,employee_code,first_name,last_name')))
            ->response()->setStatusCode(201);
    }

    public function show(Attendance $attendance)
    {
        return new AttendanceResource($attendance->load('employee:id,employee_code,first_name,last_name'));
    }

    public function update(AttendanceRequest $request, Attendance $attendance)
    {
        $attendance->update($request->validated());

        return new AttendanceResource($attendance->load('employee:id,employee_code,first_name,last_name'));
    }

    public function destroy(Attendance $attendance)
    {
        $attendance->delete();

        return response()->json(['message' => 'Attendance record deleted.']);
    }
}
