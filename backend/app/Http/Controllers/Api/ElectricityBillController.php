<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\ElectricityBillRequest;
use App\Http\Resources\ElectricityBillResource;
use App\Models\ElectricityBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Electricity Bill — utility bills per branch / connection on the shared
 * approval workflow. Units consumed are derived from meter readings when not
 * entered directly; the report trends units and effective per-unit cost.
 */
class ElectricityBillController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = ElectricityBill::query()->with('branch:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhere('meter_number', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%");
            });
        }
        foreach (['status', 'branch_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('bill_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('bill_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('bill_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ElectricityBillResource::collection($query->paginate($perPage));
    }

    public function store(ElectricityBillRequest $request)
    {
        $bill = ElectricityBill::create([
            ...$this->withDerivedUnits($request->validated()),
            'bill_number' => ElectricityBill::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new ElectricityBillResource($bill->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(ElectricityBill $electricityBill)
    {
        return new ElectricityBillResource($electricityBill->load($this->relations()));
    }

    public function update(ElectricityBillRequest $request, ElectricityBill $electricityBill)
    {
        if (! $electricityBill->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be edited.'], 422);
        }
        $electricityBill->update([
            ...$this->withDerivedUnits($request->validated()),
            'status' => 'draft',
            'rejection_reason' => null,
        ]);

        return new ElectricityBillResource($electricityBill->load($this->relations()));
    }

    public function destroy(ElectricityBill $electricityBill)
    {
        if (! $electricityBill->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be deleted.'], 422);
        }
        $this->deleteAttachment($electricityBill);
        $electricityBill->delete();

        return response()->json(['message' => 'Electricity bill deleted.']);
    }

    public function submit(ElectricityBill $electricityBill)
    {
        return $this->doSubmit($electricityBill);
    }

    public function approve(Request $request, ElectricityBill $electricityBill)
    {
        return $this->doApprove($request, $electricityBill);
    }

    public function reject(Request $request, ElectricityBill $electricityBill)
    {
        return $this->doReject($request, $electricityBill);
    }

    public function markPaid(Request $request, ElectricityBill $electricityBill)
    {
        return $this->doMarkPaid($request, $electricityBill);
    }

    public function uploadAttachment(Request $request, ElectricityBill $electricityBill)
    {
        return $this->doUploadAttachment($request, $electricityBill);
    }

    /** Per-branch units consumed, amount and effective per-unit cost. */
    public function report(Request $request)
    {
        $query = ElectricityBill::query()
            ->leftJoin('branches', 'branches.id', '=', 'electricity_bills.branch_id')
            ->where('electricity_bills.status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('electricity_bills.bill_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('electricity_bills.bill_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('branch_id')) {
            $query->where('electricity_bills.branch_id', $request->query('branch_id'));
        }

        $rows = $query
            ->selectRaw('electricity_bills.branch_id, branches.name as branch, COUNT(*) as count,
                SUM(electricity_bills.units_consumed) as units, SUM(electricity_bills.amount) as total')
            ->groupBy('electricity_bills.branch_id', 'branches.name')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r) {
                $units = (float) $r->units;

                return [
                    'branch_id' => $r->branch_id,
                    'branch' => $r->branch ?? 'Unassigned',
                    'count' => (int) $r->count,
                    'units' => round($units, 2),
                    'total' => (float) $r->total,
                    'per_unit' => $units > 0 ? round((float) $r->total / $units, 2) : null,
                ];
            });

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    /**
     * Fill in units_consumed from meter readings when the user leaves it blank
     * but supplies both readings.
     */
    private function withDerivedUnits(array $data): array
    {
        if (($data['units_consumed'] ?? null) === null
            && ($data['current_reading'] ?? null) !== null
            && ($data['previous_reading'] ?? null) !== null) {
            $data['units_consumed'] = max(0, (float) $data['current_reading'] - (float) $data['previous_reading']);
        }

        return $data;
    }

    protected function relations(): array
    {
        return ['branch', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'electricity-bills';
    }

    protected function resource(Model $model)
    {
        return new ElectricityBillResource($model);
    }
}
