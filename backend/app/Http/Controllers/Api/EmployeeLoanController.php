<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeLoanRequest;
use App\Http\Resources\EmployeeLoanResource;
use App\Models\EmployeeLoan;
use App\Models\LoanRepayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeLoanController extends Controller
{
    private const WITH = ['employee:id,employee_code,first_name,last_name'];

    public function index(Request $request)
    {
        $query = EmployeeLoan::query()->with(self::WITH);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('disbursed_on');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmployeeLoanResource::collection($query->paginate($perPage));
    }

    public function store(EmployeeLoanRequest $request)
    {
        $data = $request->validated();
        $data['loan_number'] ??= $this->nextLoanNumber();
        $data = array_merge($data, $this->amortize(
            (float) $data['principal'],
            (float) ($data['interest_rate'] ?? 0),
            (int) $data['tenure_months'],
        ));

        $loan = EmployeeLoan::create($data);

        return (new EmployeeLoanResource($loan->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function show(EmployeeLoan $employeeLoan)
    {
        return new EmployeeLoanResource($employeeLoan->load([...self::WITH, 'repayments']));
    }

    public function update(EmployeeLoanRequest $request, EmployeeLoan $employeeLoan)
    {
        if ($employeeLoan->status !== 'pending') {
            return response()->json(['message' => 'Only a pending loan can be edited.'], 422);
        }

        $data = $request->validated();
        $data = array_merge($data, $this->amortize(
            (float) ($data['principal'] ?? $employeeLoan->principal),
            (float) ($data['interest_rate'] ?? $employeeLoan->interest_rate),
            (int) ($data['tenure_months'] ?? $employeeLoan->tenure_months),
        ));
        $employeeLoan->update($data);

        return new EmployeeLoanResource($employeeLoan->load(self::WITH));
    }

    public function destroy(EmployeeLoan $employeeLoan)
    {
        if ((float) $employeeLoan->repaid > 0) {
            return response()->json(['message' => 'Cannot delete a loan with repayments.'], 422);
        }

        $employeeLoan->delete();

        return response()->json(['message' => 'Loan deleted.']);
    }

    public function approve(Request $request, EmployeeLoan $employeeLoan)
    {
        if ($employeeLoan->status !== 'pending') {
            return response()->json(['message' => 'Only pending loans can be approved.'], 422);
        }

        $employeeLoan->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new EmployeeLoanResource($employeeLoan->load(self::WITH));
    }

    /** Disburse an approved loan: build the EMI schedule payroll will collect. */
    public function disburse(EmployeeLoan $employeeLoan)
    {
        if (! in_array($employeeLoan->status, ['approved', 'pending'], true)) {
            return response()->json(['message' => 'Loan is already disbursed or closed.'], 422);
        }

        DB::transaction(function () use ($employeeLoan) {
            $this->buildSchedule($employeeLoan);
            $employeeLoan->update(['status' => 'disbursed']);
        });

        return new EmployeeLoanResource($employeeLoan->load([...self::WITH, 'repayments']));
    }

    /** Compute flat-rate EMI, total payable and per-EMI amount. */
    private function amortize(float $principal, float $rate, int $tenure): array
    {
        $tenure = max(1, $tenure);
        $interest = round($principal * ($rate / 100) * ($tenure / 12), 2);
        $totalPayable = round($principal + $interest, 2);
        $emi = round($totalPayable / $tenure, 2);

        return [
            'emi' => $emi,
            'total_payable' => $totalPayable,
        ];
    }

    /** Generate one pending repayment row per month; the last absorbs rounding. */
    private function buildSchedule(EmployeeLoan $loan): void
    {
        $loan->repayments()->delete();

        $tenure = (int) $loan->tenure_months;
        $emi = (float) $loan->emi;
        $total = (float) $loan->total_payable;
        $start = Carbon::parse($loan->disbursed_on)->startOfMonth();

        for ($i = 1; $i <= $tenure; $i++) {
            $amount = $i < $tenure ? $emi : round($total - $emi * ($tenure - 1), 2);
            LoanRepayment::create([
                'employee_loan_id' => $loan->id,
                'installment_no' => $i,
                'due_date' => $start->copy()->addMonths($i)->endOfMonth()->format('Y-m-d'),
                'amount' => $amount,
                'status' => 'pending',
            ]);
        }
    }

    private function nextLoanNumber(): string
    {
        $count = EmployeeLoan::count();

        return 'LN-' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }
}
