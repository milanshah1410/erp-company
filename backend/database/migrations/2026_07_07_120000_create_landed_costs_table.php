<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->decimal('freight_amount', 15, 2)->default(0);
            $table->decimal('duty_amount', 15, 2)->default(0);
            $table->decimal('other_amount', 15, 2)->default(0);
            $table->enum('allocation_method', ['value', 'quantity'])->default('value');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'goods_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_costs');
    }
};
