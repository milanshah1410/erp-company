<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->enum('level', ['mrp', 'wholesale', 'retail', 'discount'])->default('retail');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('min_quantity', 15, 3)->default(1); // qty threshold for this price
            $table->timestamps();

            $table->index(['product_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
