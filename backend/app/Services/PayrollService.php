<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\LoanRepayment;
use App\Models\OvertimeRecord;
use App\Models\Salary;
use App\Models\SalaryAdvance;
use App\Models\SalaryStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payroll engine. Expands each employee's active salary structure into a monthly
 * payslip: prorates earnings by paid attendance days, folds in approved overtime
 * and bonuses, computes the statutory PF/ESI/PT split and recovers any salary
 * advance instalment and loan EMI due that month.
 *
 * Generation produces DRAFT payslips and never touches ones already marked paid,
 * so a month can be re-run safely. Recovery balances (advance recovered, loan
 * repaid) only move when a payslip is actually paid, via markPaid().
 *
 * Statutory rates follow Indian defaults and can be overridden per run.
 */
class PayrollService
{
    /** EPF: 12% of basic (capped at a ₹15,000 wage ceiling), matched by employer. */
    public const PF_RATE = 0.12;
    public const PF_WAGE_CEILING = 15000.0;

    /** ESI applies when monthly gross ≤ ₹21,000. Employee 0.75%, employer 3.25%. */
    public const ESI_GROSS_LIMIT = 21000.0;
    public const ESI_EMPLOYEE_RATE = 0.0075;
    public const ESI_EMPLOYER_RATE = 0.0325;

    /** Professional tax: flat ₹200/month above a ₹12,000 gross floor (Gujarat slab). */
    public const PT_GROSS_FLOOR = 12000.0;
    public const PT_AMOUNT = 200.0;

    /**
     * Generate draft payslips for a month. Only active employees that have an
     * active salary structure are processed. Returns the resulting payslips.
     *
     * @param  int[]|null  $employeeIds  Limit to these employees; null = all.
     */
    public function generate(Carbon $month, ?array $employeeIds = null): Collection
    {
        $month = $month->copy()->startOfMonth();

        $query = Employee::query()->where('is_active', true)
            ->whereHas('salaryStructures', fn ($q) => $q->where('is_active', true));
        if ($employeeIds) {
            $query->whereIn('id', $employeeIds);
        }

        return $query->get()->map(fn (Employee $e) => $this->generateFor($e, $month))->filter()->values();
    }

    /**
     * Build (or refresh) the draft payslip for one employee. A payslip already
     * marked paid is returned untouched.
     */
    public function generateFor(Employee $employee, Carbon $month): ?Salary
    {
        $month = $month->copy()->startOfMonth();
        $structure = $employee->activeStructure();
        if (! $structure) {
            return null;
        }

        $existing = Salary::where('employee_id', $employee->id)
            ->whereYear('month', $month->year)->whereMonth('month', $month->month)
            ->first();
        if ($existing && $existing->status === 'paid') {
            return $existing;
        }

        return DB::transaction(function () use ($employee, $structure, $month, $existing) {
            $data = $this->compute($employee, $structure, $month);

            if ($existing) {
                $existing->update($data);

                return $existing->refresh();
            }

            return Salary::create(array_merge($data, [
                'employee_id' => $employee->id,
                'month' => $month->format('Y-m-d'),
            ]))->refresh();
        });
    }

    /**
     * Pure computation of a payslip's figures for the given month. No writes.
     *
     * @return array<string, mixed>
     */
    public function compute(Employee $employee, SalaryStructure $structure, Carbon $month): array
    {
        [$workingDays, $paidDays, $presentDays, $lopDays] = $this->attendanceDays($employee, $month);
        $factor = $workingDays > 0 ? $paidDays / $workingDays : 1.0;

        // Earnings prorated by paid-day factor.
        $basic = $this->prorate($structure->basic, $factor);
        $hra = $this->prorate($structure->hra, $factor);
        $conveyance = $this->prorate($structure->conveyance, $factor);
        $medical = $this->prorate($structure->medical, $factor);
        $special = $this->prorate($structure->special_allowance, $factor);
        $other = $this->prorate($structure->other_allowances, $factor);

        $overtime = $this->approvedOvertime($employee, $month);
        $bonus = $this->approvedBonus($employee, $month);

        $earningsBeforeExtras = $basic + $hra + $conveyance + $medical + $special + $other;
        $gross = round($earningsBeforeExtras + $overtime + $bonus, 2);

        // Statutory deductions.
        $pfEmployee = $pfEmployer = 0.0;
        if ($structure->pf_applicable) {
            $pfWage = min($basic, self::PF_WAGE_CEILING);
            $pfEmployee = round($pfWage * self::PF_RATE, 2);
            $pfEmployer = round($pfWage * self::PF_RATE, 2);
        }

        $esiEmployee = $esiEmployer = 0.0;
        if ($structure->esi_applicable && $gross <= self::ESI_GROSS_LIMIT) {
            $esiEmployee = ceil($gross * self::ESI_EMPLOYEE_RATE); // ESI rounds up to next rupee
            $esiEmployer = ceil($gross * self::ESI_EMPLOYER_RATE);
        }

        $pt = ($structure->pt_applicable && $gross > self::PT_GROSS_FLOOR) ? self::PT_AMOUNT : 0.0;

        // Recovery deductions (projected; balances move only on markPaid()).
        $advanceDeduction = $this->advanceDueThisMonth($employee);
        $loanDeduction = $this->loanDueThisMonth($employee, $month);

        $totalDeductions = round(
            $pfEmployee + $esiEmployee + $pt + $advanceDeduction + $loanDeduction,
            2
        );
        $net = round($gross - $totalDeductions, 2);

        return [
            'salary_structure_id' => $structure->id,
            'basic' => $basic,
            'hra' => $hra,
            'conveyance' => $conveyance,
            'medical' => $medical,
            'special_allowance' => $special,
            'other_earnings' => $other,
            'overtime_amount' => $overtime,
            'bonus_amount' => $bonus,
            'gross' => $gross,
            // Legacy mirror columns kept in sync for the simple salary screens.
            'allowances' => round($hra + $conveyance + $medical + $special + $other + $overtime + $bonus, 2),
            'pf_employee' => $pfEmployee,
            'pf_employer' => $pfEmployer,
            'esi_employee' => $esiEmployee,
            'esi_employer' => $esiEmployer,
            'professional_tax' => $pt,
            'tds' => 0,
            'advance_deduction' => $advanceDeduction,
            'loan_deduction' => $loanDeduction,
            'other_deductions' => 0,
            'deductions' => $totalDeductions,
            'net_salary' => $net,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'paid_days' => $paidDays,
            'lop_days' => $lopDays,
            'auto_generated' => true,
            'status' => 'draft',
        ];
    }

    /**
     * Pay a draft payslip: mark it paid and settle everything it consumed —
     * link/close the overtime and bonus rows, advance recoveries and the loan
     * instalment due that month. Idempotent-safe against an already-paid slip.
     */
    public function markPaid(Salary $salary, ?string $paidOn = null): Salary
    {
        if ($salary->status === 'paid') {
            return $salary;
        }

        return DB::transaction(function () use ($salary, $paidOn) {
            $month = $salary->month->copy()->startOfMonth();

            // Consume approved overtime for the month.
            OvertimeRecord::where('employee_id', $salary->employee_id)
                ->where('status', 'approved')
                ->whereYear('date', $month->year)->whereMonth('date', $month->month)
                ->update(['status' => 'paid', 'salary_id' => $salary->id]);

            // Consume approved bonuses for the month.
            EmployeeBonus::where('employee_id', $salary->employee_id)
                ->where('status', 'approved')
                ->whereYear('month', $month->year)->whereMonth('month', $month->month)
                ->update(['status' => 'paid', 'salary_id' => $salary->id]);

            // Recover salary advances up to the amount deducted on this slip.
            $this->applyAdvanceRecovery($salary);

            // Settle the loan instalment(s) due this month.
            $this->applyLoanRepayment($salary, $month);

            $salary->update([
                'status' => 'paid',
                'paid_on' => $paidOn ?? $month->copy()->endOfMonth()->format('Y-m-d'),
            ]);

            return $salary->refresh();
        });
    }

    /**
     * Working / paid / present / LOP day counts for the month, using an
     * "assume present unless marked otherwise" model: a day is paid unless it
     * carries an explicit absence. Loss-of-pay is driven only by 'absent' rows
     * (and the unworked half of a 'half-day'); recorded paid leave, holidays
     * and weekly offs stay paid but don't count as worked/present days. When no
     * attendance is recorded at all the month is treated as fully paid — many
     * SMBs pay a fixed monthly salary without daily marking.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [working, paid, present, lop]
     */
    private function attendanceDays(Employee $employee, Carbon $month): array
    {
        $workingDays = (float) $month->daysInMonth;

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereYear('date', $month->year)->whereMonth('date', $month->month)
            ->get(['status']);

        if ($rows->isEmpty()) {
            return [$workingDays, $workingDays, $workingDays, 0.0];
        }

        $lop = 0.0;          // unpaid days: full absences + half of half-days
        $nonWorked = 0.0;    // paid but not "present": leave / holiday / week-off
        foreach ($rows as $row) {
            match ($row->status) {
                'absent' => $lop += 1,
                'half-day' => $lop += 0.5,
                'leave', 'holiday', 'week-off' => $nonWorked += 1,
                default => null, // 'present' (or anything else) → a normal paid worked day
            };
        }

        $lop = min($lop, $workingDays);
        $paid = $workingDays - $lop;
        $present = max(0.0, $paid - $nonWorked);

        return [$workingDays, round($paid, 1), round($present, 1), round($lop, 1)];
    }

    private function approvedOvertime(Employee $employee, Carbon $month): float
    {
        return (float) OvertimeRecord::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereYear('date', $month->year)->whereMonth('date', $month->month)
            ->sum('amount');
    }

    private function approvedBonus(Employee $employee, Carbon $month): float
    {
        return (float) EmployeeBonus::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereYear('month', $month->year)->whereMonth('month', $month->month)
            ->sum('amount');
    }

    /** Total advance instalment recoverable this month (capped at outstanding). */
    private function advanceDueThisMonth(Employee $employee): float
    {
        $advances = SalaryAdvance::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'recovering'])
            ->get();

        $total = 0.0;
        foreach ($advances as $advance) {
            $total += min((float) $advance->monthly_recovery, $advance->outstanding());
        }

        return round($total, 2);
    }

    /** Sum of pending loan instalments due within the month. */
    private function loanDueThisMonth(Employee $employee, Carbon $month): float
    {
        return (float) LoanRepayment::whereHas('loan', fn ($q) => $q->where('employee_id', $employee->id))
            ->where('status', 'pending')
            ->whereYear('due_date', $month->year)->whereMonth('due_date', $month->month)
            ->sum('amount');
    }

    private function applyAdvanceRecovery(Salary $salary): void
    {
        $remaining = (float) $salary->advance_deduction;
        if ($remaining <= 0) {
            return;
        }

        $advances = SalaryAdvance::where('employee_id', $salary->employee_id)
            ->whereIn('status', ['approved', 'recovering'])
            ->orderBy('date')
            ->get();

        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $advance->monthly_recovery, $advance->outstanding());
            if ($take <= 0) {
                continue;
            }
            $advance->recovered = round((float) $advance->recovered + $take, 2);
            $advance->status = $advance->outstanding() <= 0 ? 'closed' : 'recovering';
            $advance->save();
            $remaining = round($remaining - $take, 2);
        }
    }

    private function applyLoanRepayment(Salary $salary, Carbon $month): void
    {
        $installments = LoanRepayment::whereHas('loan', fn ($q) => $q->where('employee_id', $salary->employee_id))
            ->where('status', 'pending')
            ->whereYear('due_date', $month->year)->whereMonth('due_date', $month->month)
            ->with('loan')
            ->get();

        foreach ($installments as $installment) {
            $installment->update([
                'status' => 'paid',
                'paid_on' => $salary->paid_on ?? $month->copy()->endOfMonth()->format('Y-m-d'),
                'salary_id' => $salary->id,
            ]);

            $loan = $installment->loan;
            if ($loan) {
                $loan->repaid = round((float) $loan->repaid + (float) $installment->amount, 2);
                if ($loan->outstanding() <= 0) {
                    $loan->status = 'closed';
                }
                $loan->save();
            }
        }
    }

    private function prorate(float|string|null $amount, float $factor): float
    {
        return round((float) $amount * $factor, 2);
    }
}
