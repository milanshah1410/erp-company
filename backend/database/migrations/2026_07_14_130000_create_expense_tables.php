<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Expense module.
 *
 * Operating expenses (rent, salary, electricity, GST payment, …) booked
 * against a category and — optionally — a branch, so spend can be reported
 * by month, by branch and by category. Each expense runs through a light
 * approval workflow (draft → pending → approved / rejected), can carry a
 * scanned bill/receipt attachment, and is marked paid once settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('expense_number');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            // Optional branch the spend belongs to — drives branch-wise reporting.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'bank', 'upi', 'card', 'cheque', 'other'])->default('cash');
            $table->string('payee')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->string('attachment_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'expense_date']);
            $table->index(['tenant_id', 'expense_category_id']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
