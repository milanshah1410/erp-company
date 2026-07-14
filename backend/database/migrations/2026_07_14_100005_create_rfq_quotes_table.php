<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('rfq_supplier_id')->constrained('rfq_suppliers')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->cascadeOnDelete();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['rfq_supplier_id', 'rfq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_quotes');
    }
};
