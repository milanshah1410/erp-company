<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BiometricPunchResource;
use App\Models\Attendance;
use App\Models\BiometricPunch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Biometric attendance integration: ingest raw in/out punches from devices
 * (live push or CSV/JSON import) and roll them up into daily attendance rows.
 */
class BiometricPunchController extends Controller
{
    public function index(Request $request)
    {
        $query = BiometricPunch::query()->with(['device:id,name', 'employee:id,employee_code,first_name,last_name']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('biometric_device_id')) {
            $query->where('biometric_device_id', $request->query('biometric_device_id'));
        }
        if ($request->filled('processed')) {
            $query->where('processed', $request->boolean('processed'));
        }
        if ($request->filled('date')) {
            $query->whereDate('punched_at', $request->query('date'));
        }

        $query->orderByDesc('punched_at');
        $perPage = min((int) $request->query('per_page', 25), 200);

        return BiometricPunchResource::collection($query->paginate($perPage));
    }

    /**
     * Bulk-ingest punches pushed from a device or uploaded from an export file.
     * Each punch resolves to an employee by explicit employee_id or by the
     * device-side user id mapped on the employee record.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'biometric_device_id' => ['nullable', 'integer', 'exists:biometric_devices,id'],
            'punches' => ['required', 'array', 'min:1'],
            'punches.*.employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'punches.*.device_user_id' => ['nullable', 'string', 'max:50'],
            'punches.*.punched_at' => ['required', 'date'],
            'punches.*.direction' => ['nullable', 'in:in,out'],
        ]);

        $deviceId = $data['biometric_device_id'] ?? null;

        // Pre-map device user ids → employee ids for fast resolution.
        $byDeviceUser = Employee::whereNotNull('biometric_user_id')
            ->pluck('id', 'biometric_user_id');

        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($data, $deviceId, $byDeviceUser, &$created, &$skipped) {
            foreach ($data['punches'] as $p) {
                $employeeId = $p['employee_id'] ?? ($p['device_user_id'] ?? null ? $byDeviceUser[$p['device_user_id']] ?? null : null);
                if (! $employeeId) {
                    $skipped++;

                    continue;
                }

                BiometricPunch::create([
                    'biometric_device_id' => $deviceId,
                    'employee_id' => $employeeId,
                    'device_user_id' => $p['device_user_id'] ?? null,
                    'punched_at' => $p['punched_at'],
                    'direction' => $p['direction'] ?? 'in',
                    'source' => 'import',
                    'processed' => false,
                ]);
                $created++;
            }
        });

        return response()->json([
            'message' => "Imported {$created} punch(es), {$skipped} unmatched.",
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * Roll unprocessed punches into daily attendance: earliest punch of a day
     * becomes check-in, latest becomes check-out, and the day is marked present.
     */
    public function sync(Request $request)
    {
        $params = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = BiometricPunch::query()->where('processed', false)->whereNotNull('employee_id');
        if (! empty($params['from'])) {
            $query->where('punched_at', '>=', Carbon::parse($params['from'])->startOfDay());
        }
        if (! empty($params['to'])) {
            $query->where('punched_at', '<=', Carbon::parse($params['to'])->endOfDay());
        }

        $punches = $query->orderBy('punched_at')->get();

        // Group by employee + calendar day.
        $groups = $punches->groupBy(fn (BiometricPunch $p) => $p->employee_id . '|' . $p->punched_at->format('Y-m-d'));

        $days = 0;
        DB::transaction(function () use ($groups, &$days) {
            foreach ($groups as $key => $group) {
                [$employeeId, $date] = explode('|', $key);
                $first = $group->first();
                $last = $group->last();

                Attendance::updateOrCreate(
                    ['employee_id' => (int) $employeeId, 'date' => $date],
                    [
                        'status' => 'present',
                        'check_in' => $first->punched_at->format('H:i:s'),
                        'check_out' => $group->count() > 1 ? $last->punched_at->format('H:i:s') : null,
                        'remarks' => 'Biometric',
                    ]
                );

                BiometricPunch::whereIn('id', $group->pluck('id'))->update(['processed' => true]);
                $days++;
            }
        });

        return response()->json([
            'message' => "Synced {$days} attendance day(s) from {$punches->count()} punch(es).",
            'attendance_days' => $days,
            'punches' => $punches->count(),
        ]);
    }

    public function destroy(BiometricPunch $biometricPunch)
    {
        $biometricPunch->delete();

        return response()->json(['message' => 'Punch deleted.']);
    }
}
