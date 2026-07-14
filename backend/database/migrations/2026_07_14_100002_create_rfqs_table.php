<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('rfq_number');
            $table->foreignId('purchase_indent_id')->nullable()->constrained('purchase_indents')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->enum('status', [
                'draft', 'sent', 'awarded', 'closed', 'cancelled',
            ])->default('draft');
            $table->date('rfq_date');
            $table->date('response_deadline')->nullable();
            // Set once a winning supplier is chosen; the generated PO is linked back.
            $table->foreignId('awarded_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'rfq_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
