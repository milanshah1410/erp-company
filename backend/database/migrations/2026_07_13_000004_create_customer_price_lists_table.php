<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('min_quantity', 15, 3)->default(1); // qty threshold for this price
            $table->timestamps();

            // One override per customer + product + variant + quantity break.
            $table->unique(['customer_id', 'product_id', 'variant_id', 'min_quantity'], 'cust_price_unique');
            $table->index(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_price_lists');
    }
};
