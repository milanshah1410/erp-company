<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Salary period as the first day of the month (e.g. 2026-07-01).
            $table->date('month');
            $table->decimal('basic', 14, 2)->default(0);
            $table->decimal('allowances', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            // net = basic + allowances - deductions, computed server-side on write.
            $table->decimal('net_salary', 14, 2)->default(0);
            $table->enum('status', ['draft', 'paid'])->default('draft');
            $table->date('paid_on')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            // One salary record per employee per month.
            $table->unique(['tenant_id', 'employee_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};
