<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('count_number');
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            $table->date('count_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'count_number']);
            $table->index('status');
        });

        Schema::create('cycle_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_count_id')->constrained('cycle_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // Book quantity: snapshotted when the sheet is built, refreshed to the
            // live on-hand figure (under lock) at completion so the posted variance
            // is exact even if stock moved while counting.
            $table->decimal('system_quantity', 15, 3)->default(0);
            $table->decimal('counted_quantity', 15, 3)->nullable();
            // counted - system, finalized at completion.
            $table->decimal('variance', 15, 3)->nullable();
            // Average cost at completion, used to value the variance.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_count_items');
        Schema::dropIfExists('cycle_counts');
    }
};
