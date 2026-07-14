<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employee loans with a flat-rate EMI recovered from payroll.
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('loan_number');
            $table->decimal('principal', 12, 2);
            $table->decimal('interest_rate', 6, 2)->default(0); // flat annual %
            $table->unsignedInteger('tenure_months');
            $table->decimal('emi', 12, 2); // computed monthly instalment (principal+interest)
            $table->decimal('total_payable', 12, 2);
            $table->decimal('repaid', 12, 2)->default(0);
            $table->date('disbursed_on');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'disbursed', 'closed', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'loan_number']);
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        // Per-instalment ledger: scheduled on disburse, marked paid by payroll.
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->date('paid_on')->nullable();
            $table->foreignId('salary_id')->nullable()->constrained('salaries')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('employee_loans');
    }
};
