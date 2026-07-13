<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->string('settlement_number');
            $table->decimal('amount', 15, 2);
            $table->enum('mode', ['cash', 'bank', 'upi', 'adjustment'])->default('cash');
            $table->date('settlement_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'settlement_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_settlements');
    }
};
