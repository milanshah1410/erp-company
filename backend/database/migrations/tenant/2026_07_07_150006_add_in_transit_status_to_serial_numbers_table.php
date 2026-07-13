<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE serial_numbers MODIFY status ENUM('in_stock','in_transit','sold','damaged','returned') NOT NULL DEFAULT 'in_stock'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE serial_numbers MODIFY status ENUM('in_stock','sold','damaged','returned') NOT NULL DEFAULT 'in_stock'");
    }
};
