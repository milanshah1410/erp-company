<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\FuelExpenseRequest;
use App\Http\Resources\FuelExpenseResource;
use App\Models\FuelExpense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Fuel Expense — fuel fills booked against a vehicle, on the shared approval
 * workflow. The mileage report derives km-per-litre per vehicle from
 * consecutive odometer readings (full-to-full method).
 */
class FuelExpenseController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = FuelExpense::query()->with(['vehicle:id,registration_no,name', 'branch:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                    ->orWhere('station', 'like', "%{$search}%");
            });
        }
        foreach (['status', 'vehicle_id', 'branch_id', 'fuel_type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('fuel_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('fuel_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('fuel_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FuelExpenseResource::collection($query->paginate($perPage));
    }

    public function store(FuelExpenseRequest $request)
    {
        $expense = FuelExpense::create([
            ...$request->validated(),
            'expense_number' => FuelExpense::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new FuelExpenseResource($expense->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(FuelExpense $fuelExpense)
    {
        return new FuelExpenseResource($fuelExpense->load($this->relations()));
    }

    public function update(FuelExpenseRequest $request, FuelExpense $fuelExpense)
    {
        if (! $fuelExpense->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be edited.'], 422);
        }
        $fuelExpense->update([...$request->validated(), 'status' => 'draft', 'rejection_reason' => null]);

        return new FuelExpenseResource($fuelExpense->load($this->relations()));
    }

    public function destroy(FuelExpense $fuelExpense)
    {
        if (! $fuelExpense->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be deleted.'], 422);
        }
        $this->deleteAttachment($fuelExpense);
        $fuelExpense->delete();

        return response()->json(['message' => 'Fuel expense deleted.']);
    }

    public function submit(FuelExpense $fuelExpense)
    {
        return $this->doSubmit($fuelExpense);
    }

    public function approve(Request $request, FuelExpense $fuelExpense)
    {
        return $this->doApprove($request, $fuelExpense);
    }

    public function reject(Request $request, FuelExpense $fuelExpense)
    {
        return $this->doReject($request, $fuelExpense);
    }

    public function markPaid(Request $request, FuelExpense $fuelExpense)
    {
        return $this->doMarkPaid($request, $fuelExpense);
    }

    public function uploadAttachment(Request $request, FuelExpense $fuelExpense)
    {
        return $this->doUploadAttachment($request, $fuelExpense);
    }

    /**
     * Per-vehicle fuel summary with derived mileage. Mileage uses the
     * full-to-full method: distance covered across fills (max − min odometer)
     * over the litres burned to cover it (total litres minus the first fill).
     * Needs at least two odometer-stamped fills to compute.
     */
    public function report(Request $request)
    {
        $query = FuelExpense::query()
            ->with('vehicle:id,registration_no,name')
            ->where('status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('fuel_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('fuel_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->query('vehicle_id'));
        }

        $rows = $query->get()->groupBy('vehicle_id')->map(function ($fills) {
            $vehicle = $fills->first()->vehicle;
            $totalLitres = round((float) $fills->sum('litres'), 3);
            $totalAmount = round((float) $fills->sum('amount'), 2);

            $withOdo = $fills->whereNotNull('odometer')->sortBy('odometer')->values();
            $distance = null;
            $mileage = null;
            if ($withOdo->count() >= 2) {
                $distance = (int) $withOdo->last()->odometer - (int) $withOdo->first()->odometer;
                // Litres burned to cover that distance excludes the first (baseline) fill.
                $litresForRun = (float) $withOdo->slice(1)->sum('litres');
                $mileage = $litresForRun > 0 ? round($distance / $litresForRun, 2) : null;
            }

            return [
                'vehicle_id' => $vehicle?->id,
                'vehicle' => $vehicle ? trim($vehicle->registration_no.($vehicle->name ? " — {$vehicle->name}" : '')) : null,
                'fills' => $fills->count(),
                'total_litres' => $totalLitres,
                'total_amount' => $totalAmount,
                'distance_km' => $distance,
                'mileage_kmpl' => $mileage,
            ];
        })->sortByDesc('total_amount')->values();

        return response()->json([
            'data' => $rows,
            'total' => round($rows->sum('total_amount'), 2),
        ]);
    }

    protected function relations(): array
    {
        return ['vehicle', 'branch', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'fuel-expenses';
    }

    protected function resource(Model $model)
    {
        return new FuelExpenseResource($model);
    }
}
