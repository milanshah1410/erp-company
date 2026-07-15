<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retail POS exchanges settle the trade-in value of returned goods against a
 * new sale by recording a payment line on each invoice. Those lines need a
 * mode that reads as neither cash nor credit, so the enum gains 'exchange'.
 * (Cash/card/upi refunds are still ordinary negative payments in their own
 * tender mode; only the exchange trade-in credit uses this value.)
 *
 * MySQL keeps a native ENUM; on SQLite/others (used by the test suite) the
 * column — created by Laravel with a CHECK constraint — is relaxed to a plain
 * string so the new value is accepted without a fragile constraint rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY COLUMN mode ENUM('cash','card','upi','credit','exchange') NOT NULL DEFAULT 'cash'");

            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('mode')->default('cash')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE payments SET mode = 'credit' WHERE mode = 'exchange'");
            DB::statement("ALTER TABLE payments MODIFY COLUMN mode ENUM('cash','card','upi','credit') NOT NULL DEFAULT 'cash'");
        }
    }
};
