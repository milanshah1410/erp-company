<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeeLoan;
use App\Models\LoanRepayment;
use App\Models\OvertimeRecord;
use App\Models\SalaryAdvance;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\PayrollService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exercises the PayrollService money math: structure proration, the statutory
 * PF/ESI/PT split, overtime/bonus add-ons and advance/loan recovery on payout.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $payroll;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['company_name' => 'Payroll Co', 'status' => 'active']);
        app(TenantContext::class)->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $this->payroll = app(PayrollService::class);
        $this->month = Carbon::parse('2026-06-01');
    }

    private function makeEmployee(): Employee
    {
        return Employee::forceCreate([
            'employee_code' => 'EMP1',
            'first_name' => 'Asha',
            'is_active' => true,
        ]);
    }

    public function test_generates_payslip_with_statutory_deductions(): void
    {
        $employee = $this->makeEmployee();
        SalaryStructure::forceCreate([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'basic' => 10000, 'hra' => 4000, 'conveyance' => 1000,
            'medical' => 0, 'special_allowance' => 0, 'other_allowances' => 0,
            'pf_applicable' => true, 'esi_applicable' => true, 'pt_applicable' => true,
            'is_active' => true,
        ]);

        // No attendance rows → treated as a fully-paid month.
        $slip = $this->payroll->generateFor($employee, $this->month);

        $this->assertNotNull($slip);
        $this->assertEqualsWithDelta(15000, (float) $slip->gross, 0.01);
        // PF: 12% of basic 10000 (< ceiling) = 1200.
        $this->assertEqualsWithDelta(1200, (float) $slip->pf_employee, 0.01);
        // ESI applies (gross 15000 <= 21000): 0.75% of 15000 = 112.5 → ceil 113.
        $this->assertEqualsWithDelta(113, (float) $slip->esi_employee, 0.01);
        // PT: gross 15000 > 12000 floor → 200.
        $this->assertEqualsWithDelta(200, (float) $slip->professional_tax, 0.01);
        // Net = 15000 - (1200 + 113 + 200) = 13487.
        $this->assertEqualsWithDelta(13487, (float) $slip->net_salary, 0.01);
        $this->assertSame('draft', $slip->status);
    }

    public function test_lop_prorates_earnings(): void
    {
        $employee = $this->makeEmployee();
        SalaryStructure::forceCreate([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'basic' => 30000, 'pf_applicable' => false, 'esi_applicable' => false, 'pt_applicable' => false,
            'is_active' => true,
        ]);

        // June has 30 days; mark 3 absent → 27 paid days → 90% proration.
        for ($d = 1; $d <= 3; $d++) {
            Attendance::forceCreate([
                'employee_id' => $employee->id,
                'date' => sprintf('2026-06-%02d', $d),
                'status' => 'absent',
            ]);
        }

        $slip = $this->payroll->generateFor($employee, $this->month);

        $this->assertEqualsWithDelta(27000, (float) $slip->basic, 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $slip->lop_days, 0.01);
    }

    public function test_overtime_and_bonus_add_to_gross_and_settle_on_pay(): void
    {
        $employee = $this->makeEmployee();
        SalaryStructure::forceCreate([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'basic' => 20000, 'pf_applicable' => false, 'esi_applicable' => false, 'pt_applicable' => false,
            'is_active' => true,
        ]);
        $ot = OvertimeRecord::forceCreate([
            'employee_id' => $employee->id, 'date' => '2026-06-10',
            'hours' => 5, 'rate' => 200, 'amount' => 1000, 'status' => 'approved',
        ]);
        $bonus = EmployeeBonus::forceCreate([
            'employee_id' => $employee->id, 'month' => '2026-06-01',
            'type' => 'performance', 'amount' => 2500, 'status' => 'approved',
        ]);

        $slip = $this->payroll->generateFor($employee, $this->month);
        $this->assertEqualsWithDelta(23500, (float) $slip->gross, 0.01);

        $this->payroll->markPaid($slip);

        $this->assertSame('paid', $ot->refresh()->status);
        $this->assertSame($slip->id, $ot->salary_id);
        $this->assertSame('paid', $bonus->refresh()->status);
    }

    public function test_advance_and_loan_recover_on_pay(): void
    {
        $employee = $this->makeEmployee();
        SalaryStructure::forceCreate([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'basic' => 50000, 'pf_applicable' => false, 'esi_applicable' => false, 'pt_applicable' => false,
            'is_active' => true,
        ]);
        $advance = SalaryAdvance::forceCreate([
            'employee_id' => $employee->id, 'date' => '2026-05-01',
            'amount' => 12000, 'monthly_recovery' => 3000, 'recovered' => 0, 'status' => 'approved',
        ]);
        $loan = EmployeeLoan::forceCreate([
            'employee_id' => $employee->id, 'loan_number' => 'LN-1',
            'principal' => 24000, 'interest_rate' => 0, 'tenure_months' => 12,
            'emi' => 2000, 'total_payable' => 24000, 'repaid' => 0,
            'disbursed_on' => '2026-05-01', 'status' => 'disbursed',
        ]);
        LoanRepayment::forceCreate([
            'employee_loan_id' => $loan->id, 'installment_no' => 1,
            'due_date' => '2026-06-30', 'amount' => 2000, 'status' => 'pending',
        ]);

        $slip = $this->payroll->generateFor($employee, $this->month);
        // Deductions = advance 3000 + loan EMI 2000 = 5000; net = 45000.
        $this->assertEqualsWithDelta(3000, (float) $slip->advance_deduction, 0.01);
        $this->assertEqualsWithDelta(2000, (float) $slip->loan_deduction, 0.01);
        $this->assertEqualsWithDelta(45000, (float) $slip->net_salary, 0.01);

        $this->payroll->markPaid($slip);

        $this->assertEqualsWithDelta(3000, (float) $advance->refresh()->recovered, 0.01);
        $this->assertSame('recovering', $advance->status);
        $this->assertEqualsWithDelta(2000, (float) $loan->refresh()->repaid, 0.01);
        $this->assertSame('paid', LoanRepayment::where('employee_loan_id', $loan->id)->first()->status);
    }
}
