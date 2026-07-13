<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('batch_number', 100);
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 15, 3)->default(0);       // on-hand within this batch
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'variant_id', 'warehouse_id', 'batch_number'], 'batch_unique');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
