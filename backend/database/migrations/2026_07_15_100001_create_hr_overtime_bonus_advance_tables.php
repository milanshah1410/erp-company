<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overtime worked on a given day; amount = hours * rate. Feeds payroll once approved.
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('hours', 6, 2);
            $table->decimal('rate', 10, 2)->default(0); // per-hour rate
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Set when consumed by a payroll run so it is not double-counted.
            $table->foreignId('salary_id')->nullable()->constrained('salaries')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'date']);
        });

        // Ad-hoc / festival / performance bonuses. Paid out via payroll when approved.
        Schema::create('employee_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // payroll period the bonus belongs to (1st of month)
            $table->string('type')->default('performance'); // performance, festival, diwali, statutory…
            $table->decimal('amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('salary_id')->nullable()->constrained('salaries')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'month']);
        });

        // Salary advance: lump sum paid up front, recovered from future payslips.
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2);
            $table->decimal('monthly_recovery', 12, 2); // deducted each payroll until cleared
            $table->decimal('recovered', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'recovering', 'closed'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
        Schema::dropIfExists('employee_bonuses');
        Schema::dropIfExists('overtime_records');
    }
};
