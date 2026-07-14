<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->enum('status', ['invited', 'responded', 'declined'])->default('invited');
            // Cached roll-up of this vendor's quoted lines, for the comparison grid.
            $table->decimal('quoted_total', 15, 2)->default(0);
            $table->integer('lead_time_days')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_suppliers');
    }
};
