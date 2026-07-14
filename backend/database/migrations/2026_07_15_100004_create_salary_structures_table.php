<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-employee CTC breakup + statutory applicability, used as the
        // template a payroll run expands into a payslip.
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');

            // Earnings
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('hra', 12, 2)->default(0);
            $table->decimal('conveyance', 12, 2)->default(0);
            $table->decimal('medical', 12, 2)->default(0);
            $table->decimal('special_allowance', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);

            // Statutory applicability flags
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('pt_applicable')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_structures');
    }
};
