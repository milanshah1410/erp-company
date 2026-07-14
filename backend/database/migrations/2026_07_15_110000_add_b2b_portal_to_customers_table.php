<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B Order System (SRS §14): give customers a self-service portal login so
 * dealers / distributors / retailers can place their own orders. Credentials
 * live on the customer row; the customer becomes a Sanctum tokenable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen the customer type enum so the three B2B roles are first-class.
        // Raw MODIFY is MySQL-only; SQLite (test DB) stores enums as free strings.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','dealer','distributor','retailer') NOT NULL DEFAULT 'retail'");
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false)->after('is_active');
            $table->string('portal_password')->nullable()->after('portal_enabled');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_password');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_enabled', 'portal_password', 'portal_last_login_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','distributor') NOT NULL DEFAULT 'retail'");
        }
    }
};
