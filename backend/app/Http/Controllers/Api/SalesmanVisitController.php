<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanVisitRequest;
use App\Http\Requests\VisitCheckInRequest;
use App\Http\Resources\SalesmanVisitResource;
use App\Models\Beat;
use App\Models\SalesmanLocation;
use App\Models\SalesmanVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily visit plan + actual visit tracking. Planned stops can be generated in
 * bulk from a beat for a date; each stop is then checked in (stamping a GPS
 * fix and time) and checked out in the field.
 */
class SalesmanVisitController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesmanVisit::query()
            ->with(['salesman:id,name,code', 'customer:id,name', 'beat:id,name', 'order:id,so_number']);

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('beat_id')) {
            $query->where('beat_id', $request->query('beat_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date')) {
            $query->whereDate('visit_date', $request->query('date'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('visit_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('visit_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('visit_date')->orderBy('sequence')->orderBy('id');
        $perPage = min((int) $request->query('per_page', 50), 200);

        return SalesmanVisitResource::collection($query->paginate($perPage));
    }

    public function store(SalesmanVisitRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $visit = SalesmanVisit::create($data);

        return (new SalesmanVisitResource($visit->load(['salesman', 'customer', 'beat'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalesmanVisit $salesmanVisit)
    {
        return new SalesmanVisitResource($salesmanVisit->load(['salesman', 'customer', 'beat', 'order:id,so_number']));
    }

    public function update(SalesmanVisitRequest $request, SalesmanVisit $salesmanVisit)
    {
        $salesmanVisit->update($request->validated());

        return new SalesmanVisitResource($salesmanVisit->load(['salesman', 'customer', 'beat', 'order:id,so_number']));
    }

    public function destroy(SalesmanVisit $salesmanVisit)
    {
        $salesmanVisit->delete();

        return response()->json(['message' => 'Visit deleted.']);
    }

    /**
     * Generate a day's visit plan from a beat: one planned stop per beat
     * customer, in route order. Skips customers already planned for that date
     * so re-running is idempotent.
     */
    public function generatePlan(Request $request)
    {
        $data = $request->validate([
            'beat_id' => ['required', 'integer', 'exists:beats,id'],
            'visit_date' => ['required', 'date'],
        ]);

        $beat = Beat::with('stops')->findOrFail($data['beat_id']);
        $date = $data['visit_date'];

        $existing = SalesmanVisit::where('beat_id', $beat->id)
            ->whereDate('visit_date', $date)
            ->pluck('customer_id')
            ->all();

        $created = DB::transaction(function () use ($beat, $date, $existing, $request) {
            $rows = [];
            foreach ($beat->stops as $stop) {
                if (in_array($stop->customer_id, $existing, true)) {
                    continue;
                }
                $rows[] = SalesmanVisit::create([
                    'salesman_id' => $beat->salesman_id,
                    'customer_id' => $stop->customer_id,
                    'beat_id' => $beat->id,
                    'visit_date' => $date,
                    'sequence' => $stop->sequence,
                    'status' => 'planned',
                    'created_by' => $request->user()?->id,
                ]);
            }

            return $rows;
        });

        return response()->json([
            'message' => count($created).' visit(s) planned.',
            'data' => SalesmanVisitResource::collection(collect($created)),
        ], 201);
    }

    /** Check in at a customer: stamp time + GPS fix and mark visited. */
    public function checkIn(VisitCheckInRequest $request, SalesmanVisit $salesmanVisit)
    {
        if ($salesmanVisit->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'This visit is cancelled.']);
        }

        $data = $request->validated();
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;

        DB::transaction(function () use ($salesmanVisit, $data, $lat, $lng) {
            $salesmanVisit->update([
                'status' => 'visited',
                'check_in_at' => now(),
                'check_in_lat' => $lat,
                'check_in_lng' => $lng,
                'outcome' => $data['outcome'] ?? $salesmanVisit->outcome,
                'sales_order_id' => $data['sales_order_id'] ?? $salesmanVisit->sales_order_id,
                'notes' => $data['notes'] ?? $salesmanVisit->notes,
            ]);

            // A check-in with a GPS fix doubles as a location ping, so the
            // live-tracking map reflects field activity even without the
            // mobile app streaming background pings.
            if ($lat !== null && $lng !== null) {
                SalesmanLocation::create([
                    'salesman_id' => $salesmanVisit->salesman_id,
                    'recorded_at' => now(),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'visit-check-in',
                ]);
            }
        });

        return new SalesmanVisitResource($salesmanVisit->load(['salesman', 'customer', 'beat', 'order:id,so_number']));
    }

    /** Check out of a visit: stamp the departure time. */
    public function checkOut(SalesmanVisit $salesmanVisit)
    {
        if ($salesmanVisit->check_in_at === null) {
            throw ValidationException::withMessages(['status' => 'Cannot check out before checking in.']);
        }
        $salesmanVisit->update(['check_out_at' => now()]);

        return new SalesmanVisitResource($salesmanVisit->load(['salesman', 'customer', 'beat', 'order:id,so_number']));
    }
}
