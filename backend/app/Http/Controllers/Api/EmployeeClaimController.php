<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeClaimRequest;
use App\Http\Resources\EmployeeClaimResource;
use App\Models\EmployeeClaim;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employee Expense Claim — reimbursable staff expense claims with line items,
 * running through the shared approval workflow. Marking an approved claim paid
 * records the reimbursement.
 */
class EmployeeClaimController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = EmployeeClaim::query()
            ->with(['employee:id,first_name,last_name,employee_code', 'branch:id,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('claim_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('claim_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('claim_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('claim_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmployeeClaimResource::collection($query->paginate($perPage));
    }

    public function store(EmployeeClaimRequest $request)
    {
        $data = $request->validated();

        $claim = DB::transaction(function () use ($request, $data) {
            $claim = EmployeeClaim::create([
                'claim_number' => EmployeeClaim::nextNumber(),
                'employee_id' => $data['employee_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'claim_date' => $data['claim_date'],
                'title' => $data['title'] ?? null,
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]);
            $claim->items()->createMany($data['items']);
            $claim->recalcTotal();

            return $claim;
        });

        return (new EmployeeClaimResource($claim->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(EmployeeClaim $employeeClaim)
    {
        return new EmployeeClaimResource($employeeClaim->load($this->relations()));
    }

    public function update(EmployeeClaimRequest $request, EmployeeClaim $employeeClaim)
    {
        if (! $employeeClaim->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected claims can be edited.'], 422);
        }
        $data = $request->validated();

        DB::transaction(function () use ($employeeClaim, $data) {
            $employeeClaim->update([
                'employee_id' => $data['employee_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'claim_date' => $data['claim_date'],
                'title' => $data['title'] ?? null,
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'rejection_reason' => null,
            ]);
            $employeeClaim->items()->delete();
            $employeeClaim->items()->createMany($data['items']);
            $employeeClaim->recalcTotal();
        });

        return new EmployeeClaimResource($employeeClaim->load($this->relations()));
    }

    public function destroy(EmployeeClaim $employeeClaim)
    {
        if (! $employeeClaim->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected claims can be deleted.'], 422);
        }
        $this->deleteAttachment($employeeClaim);
        $employeeClaim->delete();

        return response()->json(['message' => 'Employee claim deleted.']);
    }

    public function submit(EmployeeClaim $employeeClaim)
    {
        return $this->doSubmit($employeeClaim);
    }

    public function approve(Request $request, EmployeeClaim $employeeClaim)
    {
        return $this->doApprove($request, $employeeClaim);
    }

    public function reject(Request $request, EmployeeClaim $employeeClaim)
    {
        return $this->doReject($request, $employeeClaim);
    }

    public function markPaid(Request $request, EmployeeClaim $employeeClaim)
    {
        return $this->doMarkPaid($request, $employeeClaim);
    }

    public function uploadAttachment(Request $request, EmployeeClaim $employeeClaim)
    {
        return $this->doUploadAttachment($request, $employeeClaim);
    }

    protected function relations(): array
    {
        return ['employee', 'branch', 'items.category', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'employee-claims';
    }

    protected function resource(Model $model)
    {
        return new EmployeeClaimResource($model);
    }
}
