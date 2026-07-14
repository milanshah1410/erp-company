<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense sub-modules (SRS §10 "Expenses").
 *
 * Six dedicated spend types layered on the same light approval workflow as the
 * generic Expense module (draft → pending → approved / rejected, bill
 * attachment, mark-paid), each capturing type-specific detail:
 *
 *   - employee_claims (+ items)  reimbursable staff expense claims
 *   - vehicle_expenses           per-vehicle service / repair / insurance / tax
 *   - fuel_expenses              fuel fills with odometer → mileage reporting
 *   - electricity_bills          meter readings → units consumed & per-unit cost
 *   - factory_maintenances       plant / machine upkeep with downtime
 *   - asset_maintenances         asset servicing with next-service-due tracking
 */
return new class extends Migration
{
    /** Append the shared approval-workflow + payment + attachment columns. */
    private function workflowColumns(Blueprint $table, string $defaultMethod = 'cash'): void
    {
        $table->enum('payment_method', ['cash', 'bank', 'upi', 'card', 'cheque', 'other'])->default($defaultMethod);
        $table->string('reference_no')->nullable();
        $table->text('description')->nullable();
        $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
        $table->string('attachment_path')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('approved_at')->nullable();
    }

    public function up(): void
    {
        // --- Employee expense claims (header + line items) ---------------------
        Schema::create('employee_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number');
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('claim_date');
            $table->string('title')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $this->workflowColumns($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'claim_date']);
        });

        Schema::create('employee_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_claim_id')->constrained('employee_claims')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->date('expense_date')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('employee_claim_id');
        });

        // --- Vehicle expenses (non-fuel) --------------------------------------
        Schema::create('vehicle_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('expense_number');
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('expense_date');
            $table->enum('expense_type', ['service', 'repair', 'insurance', 'tax', 'permit', 'fitness', 'toll', 'parking', 'fine', 'accessory', 'other'])->default('service');
            $table->unsignedInteger('odometer')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payee')->nullable();
            $this->workflowColumns($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
            $table->index(['tenant_id', 'expense_date']);
        });

        // --- Fuel expenses -----------------------------------------------------
        Schema::create('fuel_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('expense_number');
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('fuel_date');
            $table->enum('fuel_type', ['petrol', 'diesel', 'cng', 'electric'])->default('diesel');
            $table->decimal('litres', 10, 3);
            $table->decimal('rate_per_litre', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('odometer')->nullable();
            $table->string('station')->nullable();
            $this->workflowColumns($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
            $table->index(['tenant_id', 'fuel_date']);
        });

        // --- Electricity bills -------------------------------------------------
        Schema::create('electricity_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bill_number');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('meter_number')->nullable();
            $table->string('provider')->nullable();
            $table->string('connection_name')->nullable();
            $table->date('bill_date');
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('previous_reading', 12, 2)->nullable();
            $table->decimal('current_reading', 12, 2)->nullable();
            $table->decimal('units_consumed', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $this->workflowColumns($table, 'bank');
            $table->timestamps();

            $table->unique(['tenant_id', 'bill_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'bill_date']);
        });

        // --- Factory maintenance ----------------------------------------------
        Schema::create('factory_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('maintenance_number');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('area')->nullable();
            $table->enum('maintenance_type', ['preventive', 'breakdown', 'routine', 'installation', 'other'])->default('breakdown');
            $table->date('maintenance_date');
            $table->string('performed_by')->nullable();
            $table->decimal('downtime_hours', 6, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $this->workflowColumns($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'maintenance_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'maintenance_date']);
        });

        // --- Asset maintenance -------------------------------------------------
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('maintenance_number');
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('maintenance_type', ['preventive', 'repair', 'calibration', 'amc', 'inspection', 'other'])->default('repair');
            $table->date('maintenance_date');
            $table->date('next_service_date')->nullable();
            $table->string('performed_by')->nullable();
            $table->decimal('amount', 12, 2);
            $this->workflowColumns($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'maintenance_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'next_service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('factory_maintenances');
        Schema::dropIfExists('electricity_bills');
        Schema::dropIfExists('fuel_expenses');
        Schema::dropIfExists('vehicle_expenses');
        Schema::dropIfExists('employee_claim_items');
        Schema::dropIfExists('employee_claims');
    }
};
