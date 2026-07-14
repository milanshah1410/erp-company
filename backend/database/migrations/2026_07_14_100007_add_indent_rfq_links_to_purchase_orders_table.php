<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('purchase_indent_id')->nullable()->after('warehouse_id')
                ->constrained('purchase_indents')->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->after('purchase_indent_id')
                ->constrained('rfqs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_indent_id');
            $table->dropConstrainedForeignId('rfq_id');
        });
    }
};
