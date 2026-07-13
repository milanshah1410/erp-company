<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('base_unit_cost', 15, 4)->nullable()->after('unit_cost');
            $table->decimal('landed_cost_per_unit', 15, 4)->default(0)->after('base_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['base_unit_cost', 'landed_cost_per_unit']);
        });
    }
};
