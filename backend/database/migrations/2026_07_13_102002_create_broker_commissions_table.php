<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->enum('type', ['accrual', 'reversal'])->default('accrual');
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('fixed_commission', 15, 2)->default(0);
            // Negative on reversal rows.
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('settled_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'partial', 'paid'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_commissions');
    }
};
