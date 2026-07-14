<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production costing: break the finished-product cost into named buckets on
 * the manufacturing order. Raw material stays auto-derived from the bill of
 * materials (existing material_cost); these six are entered per order and
 * summed with it into total_cost. The old stage_cost column is left in place
 * but no longer feeds the total (stages are workflow tracking now).
 */
return new class extends Migration
{
    private const BUCKETS = [
        'tailor_cost',
        'printing_cost',
        'embroidery_cost',
        'packing_cost',
        'transport_cost',
        'other_cost',
    ];

    public function up(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $after = 'material_cost';
            foreach (self::BUCKETS as $column) {
                $table->decimal($column, 15, 2)->default(0)->after($after);
                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->dropColumn(self::BUCKETS);
        });
    }
};
