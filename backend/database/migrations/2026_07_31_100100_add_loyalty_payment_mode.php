<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Points redeemed at the counter settle part of a bill, so they belong in the
 * tender mix next to cash and UPI rather than in the discount column.
 *
 * Recording them as a discount would change the taxable value of a sale that
 * was rung up at full price — the shop still sold ₹1,000 of goods, the customer
 * just paid ₹100 of it with points. As a tender, the invoice total and its GST
 * stay exactly as billed and the ledger still balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty') NOT NULL DEFAULT 'cash'");
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Rows in the mode being dropped would be truncated to '' by MySQL, so
        // move them to cash first: the money was collected either way, and a
        // wrong tender label beats an unreadable one.
        DB::table('payments')->where('mode', 'loyalty')->update(['mode' => 'cash']);

        DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit') NOT NULL DEFAULT 'cash'");
    }
};
