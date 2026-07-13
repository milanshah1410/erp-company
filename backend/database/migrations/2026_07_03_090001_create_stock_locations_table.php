<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quantity placed at each sub-location (put-away). The per-warehouse
     * aggregate in warehouse_stocks remains the source of truth for totals;
     * this table records where within the warehouse the stock sits.
     */
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'location_id', 'product_id', 'variant_id'], 'stock_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
