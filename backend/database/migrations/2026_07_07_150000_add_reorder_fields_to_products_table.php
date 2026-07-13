<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reorder_quantity', 15, 3)->default(0)->after('reorder_level');
            $table->foreignId('preferred_supplier_id')->nullable()->after('reorder_quantity')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
            $table->dropColumn('reorder_quantity');
        });
    }
};
