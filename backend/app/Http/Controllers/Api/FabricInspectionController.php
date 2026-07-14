<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FabricInspectionRequest;
use App\Http\Resources\FabricInspectionResource;
use App\Models\FabricInspection;
use App\Models\FabricRoll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fabric Inspection (4-point system). Defect occurrences accrue penalty points;
 * the score per 100 m² grades the fabric (A/B/C) and drives a pass / reject
 * result. A reject quarantines the underlying roll; the roll's grade is stamped
 * from the latest inspection.
 */
class FabricInspectionController extends Controller
{
    public function index(Request $request)
    {
        $query = FabricInspection::query()
            ->with(['fabricRoll:id,roll_number,shade_no,dye_lot,product_id', 'fabricRoll.product:id,name']);

        if ($search = $request->query('search')) {
            $query->where('inspection_number', 'like', "%{$search}%");
        }
        if ($request->filled('result')) {
            $query->where('result', $request->query('result'));
        }
        if ($request->filled('grade')) {
            $query->where('grade', $request->query('grade'));
        }
        if ($request->filled('fabric_roll_id')) {
            $query->where('fabric_roll_id', $request->query('fabric_roll_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FabricInspectionResource::collection($query->paginate($perPage));
    }

    public function store(FabricInspectionRequest $request)
    {
        $data = $request->validated();

        $inspection = DB::transaction(function () use ($request, $data) {
            $roll = FabricRoll::findOrFail($data['fabric_roll_id']);
            $defects = $data['defects'] ?? [];

            [$totalPoints, $per100, $grade, $result] = $this->grade($roll, $data, $defects);

            $inspection = FabricInspection::create([
                'inspection_number' => FabricInspection::nextNumber(),
                'fabric_roll_id' => $roll->id,
                'inspected_length' => $data['inspected_length'],
                'inspected_width' => $data['inspected_width'] ?? $roll->width,
                'total_points' => $totalPoints,
                'points_per_100sqm' => $per100,
                'grade' => $grade,
                'result' => $result,
                'inspector' => $data['inspector'] ?? null,
                'inspection_date' => $data['inspection_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncDefects($inspection, $defects);
            $this->applyToRoll($roll, $grade, $result);

            return $inspection;
        });

        return (new FabricInspectionResource($inspection->load(['fabricRoll.product', 'defects'])))
            ->response()->setStatusCode(201);
    }

    public function show(FabricInspection $fabricInspection)
    {
        return new FabricInspectionResource($fabricInspection->load(['fabricRoll.product', 'defects', 'creator']));
    }

    public function update(FabricInspectionRequest $request, FabricInspection $fabricInspection)
    {
        $data = $request->validated();

        DB::transaction(function () use ($fabricInspection, $data) {
            $roll = FabricRoll::findOrFail($data['fabric_roll_id']);
            $defects = $data['defects'] ?? [];

            [$totalPoints, $per100, $grade, $result] = $this->grade($roll, $data, $defects);

            $fabricInspection->update([
                'fabric_roll_id' => $roll->id,
                'inspected_length' => $data['inspected_length'],
                'inspected_width' => $data['inspected_width'] ?? $roll->width,
                'total_points' => $totalPoints,
                'points_per_100sqm' => $per100,
                'grade' => $grade,
                'result' => $result,
                'inspector' => $data['inspector'] ?? null,
                'inspection_date' => $data['inspection_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $fabricInspection->defects()->delete();
            $this->syncDefects($fabricInspection, $defects);
            $this->applyToRoll($roll, $grade, $result);
        });

        return new FabricInspectionResource($fabricInspection->load(['fabricRoll.product', 'defects']));
    }

    public function destroy(FabricInspection $fabricInspection)
    {
        $fabricInspection->delete();

        return response()->json(['message' => 'Inspection deleted.']);
    }

    /**
     * Compute total points, the 4-point score per 100 m², the resulting grade
     * and the pass/reject result. An explicit result in the payload overrides
     * the grade-derived default (a grader may accept a C-grade roll).
     *
     * @return array{0: float, 1: float, 2: string, 3: string}
     */
    private function grade(FabricRoll $roll, array $data, array $defects): array
    {
        $totalPoints = collect($defects)->sum(
            fn ($d) => (float) ($d['points'] ?? 0) * (int) ($d['count'] ?? 1)
        );

        $per100 = FabricInspection::scorePer100Sqm(
            totalPoints: (float) $totalPoints,
            lengthMeters: $this->toMeters((float) $data['inspected_length'], $roll->length_unit),
            width: $data['inspected_width'] ?? (float) $roll->width,
            widthUnit: $roll->width_unit,
        );

        $grade = FabricInspection::gradeFor($per100);
        $result = $data['result'] ?? ($grade === 'C' ? 'reject' : 'pass');

        return [round((float) $totalPoints, 2), $per100, $grade, $result];
    }

    private function toMeters(float $length, string $unit): float
    {
        return $unit === 'yard' ? $length * 0.9144 : $length;
    }

    private function applyToRoll(FabricRoll $roll, string $grade, string $result): void
    {
        $roll->grade = $grade;
        if ($result === 'reject') {
            $roll->status = 'quarantined';
        }
        $roll->save();
    }

    private function syncDefects(FabricInspection $inspection, array $defects): void
    {
        foreach ($defects as $d) {
            $inspection->defects()->create([
                'defect_type' => $d['defect_type'],
                'severity' => $d['severity'] ?? 'minor',
                'size_range' => $d['size_range'] ?? null,
                'points' => $d['points'],
                'count' => $d['count'] ?? 1,
            ]);
        }
    }
}
