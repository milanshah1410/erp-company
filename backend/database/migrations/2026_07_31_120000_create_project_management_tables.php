<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management: a budgeted piece of work, broken into tasks, with the
 * hours people spend on it logged against those tasks.
 *
 * The point of running this inside an ERP rather than a task tracker is the
 * money: a project's actual cost is its logged labour plus the expenses booked
 * to it, and that has to sit next to the budget it was sold against. So
 * `expenses` gains a project tag, timesheets snapshot the rate they were valued
 * at, and billing goes out through the ordinary Sales Order + Invoice spine —
 * the same route Service and POS take.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('project_number');
            $table->string('name');
            // Internal projects (an office move, an ISO audit) have no customer;
            // that is also what makes them non-billable by default.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->enum('status', ['planning', 'active', 'on_hold', 'completed', 'cancelled'])->default('planning');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            /**
             * How the work is sold:
             *  fixed             — one agreed price, hours are cost only
             *  time_and_material — approved billable hours are invoiced
             *  non_billable      — internal work, never invoiced
             */
            $table->enum('billing_type', ['fixed', 'time_and_material', 'non_billable'])->default('non_billable');
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->decimal('estimated_hours', 12, 2)->default(0);
            // Default charge-out rate; a timesheet may override it per line.
            $table->decimal('hourly_rate', 12, 2)->default(0);

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // One level of nesting: a checklist under a task is useful, an
            // unbounded tree is a project-tracker feature nobody asked for.
            $table->foreignId('parent_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['todo', 'in_progress', 'review', 'blocked', 'done'])->default('todo');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->decimal('estimated_hours', 12, 2)->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('project_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('work_date');
            $table->decimal('hours', 8, 2);
            $table->text('description')->nullable();

            // Billable hours can still be invoiced; non-billable ones are cost
            // only. Both count towards the project's actual labour spend.
            $table->boolean('is_billable')->default(true);
            // Snapshotted at entry: a rate change next quarter must not silently
            // restate what last quarter's work cost.
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);

            // Only approved hours are billable — the same gate the rest of the
            // HR module puts in front of anything that turns into money.
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Set when the hours leave on an invoice, so they cannot bill twice.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        // Spend booked to a project is the other half of its actual cost.
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('branch_id')
                ->constrained('projects')->nullOnDelete();
        });

        if ($this->isMysql()) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service','project') NOT NULL DEFAULT 'standard'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('sales_orders')->where('channel', 'project')->update(['channel' => 'standard']);
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service') NOT NULL DEFAULT 'standard'");
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::dropIfExists('project_timesheets');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('projects');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
