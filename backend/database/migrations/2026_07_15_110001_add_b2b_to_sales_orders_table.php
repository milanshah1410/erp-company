<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B Order System (SRS §14): orders placed through the customer portal land as
 * a sales order on the 'b2b' channel in a 'pending_approval' state. Staff then
 * approve (credit-check + reserve stock) or reject them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen the enums. Raw MODIFY is MySQL-only; SQLite (test DB) stores
        // enums as unconstrained strings, so the new values work without it.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('pending_approval','draft','confirmed','backordered','delivered','cancelled') NOT NULL DEFAULT 'draft'");
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b') NOT NULL DEFAULT 'standard'");
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('rejected_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'rejected_reason']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos') NOT NULL DEFAULT 'standard'");
            DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('draft','confirmed','backordered','delivered','cancelled') NOT NULL DEFAULT 'draft'");
        }
    }
};
