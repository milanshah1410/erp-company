<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryResource;
use App\Models\Salary;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Monthly payroll processing: generate draft payslips for a period from each
 * employee's salary structure (folding in attendance, overtime, bonuses and
 * advance/loan recoveries), then pay them out. Payslips are stored as Salary
 * rows, so the existing salary screens see the same data.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll)
    {
    }

    /** Payslips for a period (defaults to the current month) + roll-up totals. */
    public function index(Request $request)
    {
        $month = $this->month($request->query('month'));

        $query = Salary::query()
            ->with('employee:id,employee_code,first_name,last_name')
            ->whereYear('month', $month->year)->whereMonth('month', $month->month);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        $payslips = $query->orderBy('employee_id')->get();

        return response()->json([
            'data' => SalaryResource::collection($payslips)->resolve(),
            'month' => $month->format('Y-m-d'),
            'summary' => $this->summarize($payslips),
        ]);
    }

    /** Generate (or refresh) draft payslips for a month. */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $month = $this->month($data['month']);
        $payslips = $this->payroll->generate($month, $data['employee_ids'] ?? null);

        return response()->json([
            'message' => "Generated {$payslips->count()} payslip(s) for {$month->format('M Y')}.",
            'data' => SalaryResource::collection($payslips->load('employee:id,employee_code,first_name,last_name'))->resolve(),
            'summary' => $this->summarize($payslips),
        ], 201);
    }

    /** Pay a single draft payslip, settling its overtime/bonus/advance/loan links. */
    public function pay(Request $request, Salary $salary)
    {
        $data = $request->validate([
            'paid_on' => ['nullable', 'date'],
        ]);

        $salary = $this->payroll->markPaid($salary, $data['paid_on'] ?? null);

        return new SalaryResource($salary->load('employee:id,employee_code,first_name,last_name'));
    }

    /** Pay every draft payslip in a month at once. */
    public function payAll(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date'],
            'paid_on' => ['nullable', 'date'],
        ]);

        $month = $this->month($data['month']);
        $drafts = Salary::where('status', 'draft')
            ->whereYear('month', $month->year)->whereMonth('month', $month->month)
            ->get();

        foreach ($drafts as $draft) {
            $this->payroll->markPaid($draft, $data['paid_on'] ?? null);
        }

        return response()->json(['message' => "Paid {$drafts->count()} payslip(s).", 'paid' => $drafts->count()]);
    }

    /** Delete a draft payslip (paid slips are locked). */
    public function destroy(Salary $salary)
    {
        if ($salary->status === 'paid') {
            return response()->json(['message' => 'A paid payslip cannot be deleted.'], 422);
        }

        $salary->delete();

        return response()->json(['message' => 'Payslip deleted.']);
    }

    /** @param  \Illuminate\Support\Collection<int, Salary>  $payslips */
    private function summarize($payslips): array
    {
        return [
            'count' => $payslips->count(),
            'gross' => round((float) $payslips->sum('gross'), 2),
            'net' => round((float) $payslips->sum('net_salary'), 2),
            'pf_employee' => round((float) $payslips->sum('pf_employee'), 2),
            'pf_employer' => round((float) $payslips->sum('pf_employer'), 2),
            'esi_employee' => round((float) $payslips->sum('esi_employee'), 2),
            'esi_employer' => round((float) $payslips->sum('esi_employer'), 2),
            'professional_tax' => round((float) $payslips->sum('professional_tax'), 2),
            'tds' => round((float) $payslips->sum('tds'), 2),
            'advance_deduction' => round((float) $payslips->sum('advance_deduction'), 2),
            'loan_deduction' => round((float) $payslips->sum('loan_deduction'), 2),
        ];
    }

    private function month(?string $value): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfMonth() : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            return Carbon::now()->startOfMonth();
        }
    }
}
