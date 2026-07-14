<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OvertimeRecordRequest;
use App\Http\Resources\OvertimeRecordResource;
use App\Models\OvertimeRecord;
use Illuminate\Http\Request;

class OvertimeRecordController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name'];

    public function index(Request $request)
    {
        $query = OvertimeRecord::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('month')) {
            $query->whereYear('date', substr((string) $request->query('month'), 0, 4))
                ->whereMonth('date', substr((string) $request->query('month'), 5, 2));
        }

        $query->orderByDesc('date');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return OvertimeRecordResource::collection($query->paginate($perPage));
    }

    public function store(OvertimeRecordRequest $request)
    {
        $overtime = OvertimeRecord::create($this->withAmount($request->validated()));

        return (new OvertimeRecordResource($overtime->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(OvertimeRecord $overtimeRecord)
    {
        return new OvertimeRecordResource($overtimeRecord->load(self::WITH));
    }

    public function update(OvertimeRecordRequest $request, OvertimeRecord $overtimeRecord)
    {
        $data = $request->validated();
        $merged = array_merge($overtimeRecord->only(['hours', 'rate']), $data);
        $overtimeRecord->update($this->withAmount($data, $merged));

        return new OvertimeRecordResource($overtimeRecord->load(self::WITH));
    }

    public function destroy(OvertimeRecord $overtimeRecord)
    {
        $overtimeRecord->delete();

        return response()->json(['message' => 'Overtime record deleted.']);
    }

    /** Approve overtime so a payroll run will include it. */
    public function approve(Request $request, OvertimeRecord $overtimeRecord)
    {
        if ($overtimeRecord->status !== 'pending') {
            return response()->json(['message' => 'Only pending overtime can be approved.'], 422);
        }

        $overtimeRecord->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new OvertimeRecordResource($overtimeRecord->load(self::WITH));
    }

    /** Stamp amount = hours * rate on the write payload. */
    private function withAmount(array $data, ?array $components = null): array
    {
        $c = $components ?? $data;
        $data['amount'] = round((float) ($c['hours'] ?? 0) * (float) ($c['rate'] ?? 0), 2);

        return $data;
    }
}
