<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the simple salary row into a full payslip: detailed earnings, the
 * statutory PF/ESI/PT/TDS split, recovery deductions (advance, loan) and the
 * attendance day counts a payroll run is computed from. The original
 * basic/allowances/deductions/net_salary columns are retained — `allowances`
 * mirrors total non-basic earnings and `deductions` the total deductions so
 * the existing simple screens keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $table) {
            $table->foreignId('salary_structure_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();

            // Earnings breakup
            $table->decimal('hra', 14, 2)->default(0)->after('basic');
            $table->decimal('conveyance', 14, 2)->default(0)->after('hra');
            $table->decimal('medical', 14, 2)->default(0)->after('conveyance');
            $table->decimal('special_allowance', 14, 2)->default(0)->after('medical');
            $table->decimal('other_earnings', 14, 2)->default(0)->after('special_allowance');
            $table->decimal('overtime_amount', 14, 2)->default(0)->after('other_earnings');
            $table->decimal('bonus_amount', 14, 2)->default(0)->after('overtime_amount');
            $table->decimal('gross', 14, 2)->default(0)->after('bonus_amount');

            // Statutory + recovery deductions
            $table->decimal('pf_employee', 14, 2)->default(0)->after('gross');
            $table->decimal('pf_employer', 14, 2)->default(0)->after('pf_employee');
            $table->decimal('esi_employee', 14, 2)->default(0)->after('pf_employer');
            $table->decimal('esi_employer', 14, 2)->default(0)->after('esi_employee');
            $table->decimal('professional_tax', 14, 2)->default(0)->after('esi_employer');
            $table->decimal('tds', 14, 2)->default(0)->after('professional_tax');
            $table->decimal('advance_deduction', 14, 2)->default(0)->after('tds');
            $table->decimal('loan_deduction', 14, 2)->default(0)->after('advance_deduction');
            $table->decimal('other_deductions', 14, 2)->default(0)->after('loan_deduction');

            // Attendance basis
            $table->decimal('working_days', 6, 1)->default(0)->after('other_deductions');
            $table->decimal('present_days', 6, 1)->default(0)->after('working_days');
            $table->decimal('paid_days', 6, 1)->default(0)->after('present_days');
            $table->decimal('lop_days', 6, 1)->default(0)->after('paid_days');

            // Marks payslips produced by an automated payroll run vs. manual entry.
            $table->boolean('auto_generated')->default(false)->after('lop_days');
        });
    }

    public function down(): void
    {
        Schema::table('salaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_structure_id');
            $table->dropColumn([
                'hra', 'conveyance', 'medical', 'special_allowance', 'other_earnings',
                'overtime_amount', 'bonus_amount', 'gross',
                'pf_employee', 'pf_employer', 'esi_employee', 'esi_employer',
                'professional_tax', 'tds', 'advance_deduction', 'loan_deduction', 'other_deductions',
                'working_days', 'present_days', 'paid_days', 'lop_days', 'auto_generated',
            ]);
        });
    }
};
