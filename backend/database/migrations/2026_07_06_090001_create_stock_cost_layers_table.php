<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost layers (receipt lots) backing FIFO/LIFO inventory costing.
     *
     * Every inward movement creates a layer at its actual unit cost; every
     * outward movement consumes remaining_quantity from layers in the order
     * dictated by the company's costing method. Layers are kept up to date
     * under all methods so the costing method can be switched at any time.
     */
    public function up(): void
    {
        Schema::create('stock_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('stock_ledger_id')->nullable()->constrained('stock_ledgers')->nullOnDelete();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('quantity', 15, 3)->default(0);           // as received
            $table->decimal('remaining_quantity', 15, 3)->default(0); // not yet consumed
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['warehouse_id', 'product_id', 'variant_id', 'remaining_quantity'],
                'cost_layers_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cost_layers');
    }
};
