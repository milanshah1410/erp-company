<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShrinkageRecordRequest;
use App\Http\Resources\ShrinkageRecordResource;
use App\Models\ShrinkageRecord;
use Illuminate\Http\Request;

/**
 * Shrinkage records — SRS §5. Length & width measured before / after a process
 * (washing, dyeing, steaming); the shrinkage percentages are derived on save.
 */
class ShrinkageRecordController extends Controller
{
    public function index(Request $request)
    {
        $query = ShrinkageRecord::query()
            ->with(['fabricRoll:id,roll_number', 'product:id,name']);

        if ($search = $request->query('search')) {
            $query->where('record_number', 'like', "%{$search}%");
        }
        if ($request->filled('process')) {
            $query->where('process', $request->query('process'));
        }
        if ($request->filled('fabric_roll_id')) {
            $query->where('fabric_roll_id', $request->query('fabric_roll_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShrinkageRecordResource::collection($query->paginate($perPage));
    }

    public function store(ShrinkageRecordRequest $request)
    {
        $data = $this->withComputed($request->validated());

        $record = ShrinkageRecord::create([
            'record_number' => ShrinkageRecord::nextNumber(),
            'created_by' => $request->user()?->id,
            ...$data,
        ]);

        return (new ShrinkageRecordResource($record->load(['fabricRoll', 'product'])))
            ->response()->setStatusCode(201);
    }

    public function show(ShrinkageRecord $shrinkageRecord)
    {
        return new ShrinkageRecordResource($shrinkageRecord->load(['fabricRoll', 'product', 'creator']));
    }

    public function update(ShrinkageRecordRequest $request, ShrinkageRecord $shrinkageRecord)
    {
        $shrinkageRecord->update($this->withComputed($request->validated()));

        return new ShrinkageRecordResource($shrinkageRecord->load(['fabricRoll', 'product']));
    }

    public function destroy(ShrinkageRecord $shrinkageRecord)
    {
        $shrinkageRecord->delete();

        return response()->json(['message' => 'Shrinkage record deleted.']);
    }

    /** Attach the derived length / width shrinkage percentages. */
    private function withComputed(array $data): array
    {
        $data['length_shrinkage_pct'] = ShrinkageRecord::shrinkagePct(
            $data['length_before'] ?? 0,
            $data['length_after'] ?? 0,
        );
        $data['width_shrinkage_pct'] = ShrinkageRecord::shrinkagePct(
            $data['width_before'] ?? 0,
            $data['width_after'] ?? 0,
        );

        return $data;
    }
}
