<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header: one manufacturing order produces a finished product from
        // consumed raw materials, tracked through a chain of production stages.
        Schema::create('manufacturing_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('mo_number');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity_to_produce', 15, 3);
            $table->decimal('quantity_produced', 15, 3)->default(0);
            $table->enum('status', ['draft', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->date('order_date');
            $table->date('expected_completion_date')->nullable();
            $table->decimal('material_cost', 15, 2)->default(0);   // raw materials consumed
            $table->decimal('stage_cost', 15, 2)->default(0);      // sum of per-stage costs (labour etc.)
            $table->decimal('total_cost', 15, 2)->default(0);      // material + stage
            $table->boolean('materials_consumed')->default(false); // guard: outward posted on start
            $table->boolean('finished_received')->default(false);  // guard: inward posted on completion
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'mo_number']);
            $table->index('status');
        });

        // Bill of materials for this order — the raw materials to consume.
        Schema::create('manufacturing_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('consumed_quantity', 15, 3)->default(0); // stamped when consumed at start
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // Production pipeline — one row per stage this order runs through.
        Schema::create('manufacturing_order_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->string('stage_key');
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending');
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();

            $table->index(['manufacturing_order_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_order_stages');
        Schema::dropIfExists('manufacturing_order_materials');
        Schema::dropIfExists('manufacturing_orders');
    }
};
