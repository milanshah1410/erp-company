<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `PosController::exchange()` settles both legs of a swap by writing payment
 * rows with mode 'exchange' — the credit consumed off the original invoice, and
 * the same credit applied to the replacement sale. That value has never been in
 * the `payments.mode` enum, so on a strict-mode connection every exchange died
 * on "Data truncated for column 'mode'".
 *
 * Nothing had caught it because the counter exchange flow had no test exercising
 * it end to end. The tender is real and the code is right about wanting it; the
 * column just never learned about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isMysql()) {
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty','exchange') NOT NULL DEFAULT 'cash'");
        }
    }

    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        // Same reasoning as the loyalty mode: MySQL would truncate rows in the
        // dropped value to '', so move them somewhere readable first. 'credit'
        // is the closest honest label — an exchange credit is not cash.
        DB::table('payments')->where('mode', 'exchange')->update(['mode' => 'credit']);

        DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty') NOT NULL DEFAULT 'cash'");
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
