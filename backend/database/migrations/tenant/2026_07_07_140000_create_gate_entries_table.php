<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->enum('direction', ['inward', 'outward']);
            $table->string('vehicle_number');
            $table->string('driver_name')->nullable();
            $table->string('driver_contact')->nullable();
            $table->enum('reference_type', [
                'goods_receipt', 'sales_order', 'stock_transfer', 'purchase_order', 'stock_return', 'other',
            ])->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('purpose')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('gate_in_at')->nullable();
            $table->timestamp('gate_out_at')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_entries');
    }
};
