<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BeatRequest;
use App\Http\Resources\BeatResource;
use App\Models\Beat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Beat plan: a named, optionally weekday-scheduled route of customers for a
 * salesman. The ordered stops (route planning sequence) are managed inline
 * with the beat via the `customers` payload.
 */
class BeatController extends Controller
{
    public function index(Request $request)
    {
        $query = Beat::query()
            ->with('salesman:id,name,code')
            ->withCount('stops');

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->query('day_of_week'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return BeatResource::collection($query->paginate($perPage));
    }

    public function store(BeatRequest $request)
    {
        $data = $request->validated();

        $beat = DB::transaction(function () use ($data) {
            $beat = Beat::create(collect($data)->except('customers')->all());
            $this->syncStops($beat, $data['customers'] ?? null);

            return $beat;
        });

        return (new BeatResource($beat->load(['salesman', 'stops.customer'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Beat $beat)
    {
        return new BeatResource($beat->load(['salesman', 'stops.customer']));
    }

    public function update(BeatRequest $request, Beat $beat)
    {
        $data = $request->validated();

        DB::transaction(function () use ($beat, $data) {
            $beat->update(collect($data)->except('customers')->all());
            // Only touch stops when the caller sent a customers array; omitting
            // it leaves the existing route untouched.
            if (array_key_exists('customers', $data)) {
                $this->syncStops($beat, $data['customers']);
            }
        });

        return new BeatResource($beat->load(['salesman', 'stops.customer']));
    }

    public function destroy(Beat $beat)
    {
        if ($beat->visits()->exists()) {
            return response()->json([
                'message' => 'This beat has visit history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $beat->delete(); // stops cascade

        return response()->json(['message' => 'Beat deleted.']);
    }

    /**
     * Replace the beat's route stops with the given customers, preserving the
     * order they arrive in (or an explicit sequence). A null list is a no-op.
     */
    private function syncStops(Beat $beat, ?array $customers): void
    {
        if ($customers === null) {
            return;
        }

        $beat->stops()->delete();

        foreach (array_values($customers) as $i => $row) {
            $beat->stops()->create([
                'customer_id' => $row['customer_id'],
                'sequence' => $row['sequence'] ?? $i + 1,
            ]);
        }
    }
}
