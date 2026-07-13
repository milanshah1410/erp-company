<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_items', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 15, 3)->default(0)->after('quantity');
            $table->decimal('backorder_quantity', 15, 3)->default(0)->after('reserved_quantity');
        });

        // Widen the status enum to add 'backordered'. Raw MODIFY is MySQL-only;
        // SQLite (test DB) stores enums as unconstrained strings, so the value
        // works without an ALTER.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('draft','confirmed','backordered','delivered','cancelled') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->enum('channel', ['standard', 'pos'])->default('standard')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('draft','confirmed','delivered','cancelled') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('sales_items', function (Blueprint $table) {
            $table->dropColumn(['reserved_quantity', 'backorder_quantity']);
        });
    }
};
