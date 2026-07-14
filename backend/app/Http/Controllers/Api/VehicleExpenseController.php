<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleExpenseRequest;
use App\Http\Resources\VehicleExpenseResource;
use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Vehicle Expense — non-fuel running costs (service, repair, insurance, tax,
 * tolls…) booked against a vehicle, on the shared approval workflow. The
 * per-vehicle report rolls up approved spend for fleet cost analysis.
 */
class VehicleExpenseController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = VehicleExpense::query()->with(['vehicle:id,registration_no,name', 'branch:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                    ->orWhere('payee', 'like', "%{$search}%");
            });
        }
        foreach (['status', 'vehicle_id', 'branch_id', 'expense_type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('expense_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return VehicleExpenseResource::collection($query->paginate($perPage));
    }

    public function store(VehicleExpenseRequest $request)
    {
        $expense = VehicleExpense::create([
            ...$request->validated(),
            'expense_number' => VehicleExpense::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new VehicleExpenseResource($expense->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(VehicleExpense $vehicleExpense)
    {
        return new VehicleExpenseResource($vehicleExpense->load($this->relations()));
    }

    public function update(VehicleExpenseRequest $request, VehicleExpense $vehicleExpense)
    {
        if (! $vehicleExpense->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be edited.'], 422);
        }
        $vehicleExpense->update([...$request->validated(), 'status' => 'draft', 'rejection_reason' => null]);

        return new VehicleExpenseResource($vehicleExpense->load($this->relations()));
    }

    public function destroy(VehicleExpense $vehicleExpense)
    {
        if (! $vehicleExpense->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be deleted.'], 422);
        }
        $this->deleteAttachment($vehicleExpense);
        $vehicleExpense->delete();

        return response()->json(['message' => 'Vehicle expense deleted.']);
    }

    public function submit(VehicleExpense $vehicleExpense)
    {
        return $this->doSubmit($vehicleExpense);
    }

    public function approve(Request $request, VehicleExpense $vehicleExpense)
    {
        return $this->doApprove($request, $vehicleExpense);
    }

    public function reject(Request $request, VehicleExpense $vehicleExpense)
    {
        return $this->doReject($request, $vehicleExpense);
    }

    public function markPaid(Request $request, VehicleExpense $vehicleExpense)
    {
        return $this->doMarkPaid($request, $vehicleExpense);
    }

    public function uploadAttachment(Request $request, VehicleExpense $vehicleExpense)
    {
        return $this->doUploadAttachment($request, $vehicleExpense);
    }

    /** Per-vehicle approved spend (optionally filtered by date / type). */
    public function report(Request $request)
    {
        $query = VehicleExpense::query()
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_expenses.vehicle_id')
            ->where('vehicle_expenses.status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('vehicle_expenses.expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('vehicle_expenses.expense_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('expense_type')) {
            $query->where('vehicle_expenses.expense_type', $request->query('expense_type'));
        }

        $rows = $query
            ->selectRaw('vehicle_expenses.vehicle_id, vehicles.registration_no, vehicles.name,
                COUNT(*) as count, SUM(vehicle_expenses.amount) as total')
            ->groupBy('vehicle_expenses.vehicle_id', 'vehicles.registration_no', 'vehicles.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'vehicle_id' => $r->vehicle_id,
                'vehicle' => trim($r->registration_no.($r->name ? " — {$r->name}" : '')),
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    protected function relations(): array
    {
        return ['vehicle', 'branch', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'vehicle-expenses';
    }

    protected function resource(Model $model)
    {
        return new VehicleExpenseResource($model);
    }
}
