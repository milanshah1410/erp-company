<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            // Optional provenance: the receipt/order this rating reflects.
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->unsignedTinyInteger('quality_score');   // 1..5
            $table->unsignedTinyInteger('delivery_score');  // 1..5
            $table->unsignedTinyInteger('pricing_score');   // 1..5
            $table->unsignedTinyInteger('service_score');   // 1..5
            $table->decimal('overall_score', 3, 2)->default(0); // average of the four
            $table->date('rated_on');
            $table->text('remarks')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ratings');
    }
};
