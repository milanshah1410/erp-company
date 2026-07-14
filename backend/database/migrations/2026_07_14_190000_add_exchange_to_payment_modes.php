<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retail POS exchanges settle the trade-in value of returned goods against a
 * new sale by recording a payment line on each invoice. Those lines need a
 * mode that reads as neither cash nor credit, so the enum gains 'exchange'.
 * (Cash/card/upi refunds are still ordinary negative payments in their own
 * tender mode; only the exchange trade-in credit uses this value.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN mode ENUM('cash','card','upi','credit','exchange') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("UPDATE payments SET mode = 'credit' WHERE mode = 'exchange'");
        DB::statement("ALTER TABLE payments MODIFY COLUMN mode ENUM('cash','card','upi','credit') NOT NULL DEFAULT 'cash'");
    }
};
